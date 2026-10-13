<?php

declare(strict_types=1);

namespace App\Service;

use Predis\Client;

/** Reviewed metadata only. Never writes an existing refresh key or its TTL. */
final class LegacyRefreshMetadataBackfill
{
    public function __construct(
        private readonly Client $redis,
        private readonly LegacyRefreshReplayStore $store,
        private readonly LegacyRefreshAccountRepository $accounts,
        private readonly array $source,
    ) {
        if (array_keys($source) !== ['mysqlDsnHash', 'mysqlHost', 'mysqlDatabase', 'redisHost', 'redisPort', 'redisDatabase']
            || !is_string($source['mysqlDsnHash']) || preg_match('/^[a-f0-9]{64}$/D', $source['mysqlDsnHash']) !== 1
            || !is_int($source['redisPort']) || $source['redisPort'] < 1 || $source['redisPort'] > 65535
            || !is_int($source['redisDatabase']) || $source['redisDatabase'] < 0) throw new \InvalidArgumentException('Invalid metadata review source.');
        foreach (['mysqlHost', 'mysqlDatabase', 'redisHost'] as $field) {
            if (!is_string($source[$field]) || strlen($source[$field]) > 255 || preg_match('/^[A-Za-z0-9_.:\[\]-]+$/D', $source[$field]) !== 1) {
                throw new \InvalidArgumentException('Invalid metadata review source.');
            }
        }
    }

    public function review(): array
    {
        $entries = [];
        $inventory = LegacyRefreshNamespaceInspector::scan($this->redis);
        foreach ($inventory['tokens'] as $hash => $token) {
            $raw = $token['raw'];
            $first = $this->store->capture($raw);
            if (!is_string($first['value'])) throw new \RuntimeException('Refresh source changed during review.', 409);
            $owner = LegacyRefreshReplayStore::decodeSession($first['value'], $raw)['user_id'];
            $entries[] = $this->accounts->withLockedUser($owner, function (array $user) use ($raw, $hash, $owner): array {
                $captured = $this->store->capture($raw);
                if (!is_string($captured['value']) || $captured['ttl_ms'] <= 0 || $captured['ttl_ms'] > 2592000000) {
                    throw new \RuntimeException('Refresh source has an unsupported lifetime.', 409);
                }
                $session = LegacyRefreshReplayStore::decodeSession($captured['value'], $raw);
                if ($session['user_id'] !== $owner) throw new \RuntimeException('Refresh owner changed during review.', 409);
                $fingerprint = LegacyRefreshAccountRepository::fingerprint($user);
                if ($captured['metadata'] !== null) {
                    $current = $this->store->inspect($raw);
                    if ($current['status'] !== 'active' || $current['metadata']['account_fingerprint'] !== $fingerprint
                        || $current['metadata']['account_epoch'] !== LegacyRefreshAccountRepository::accountEpoch($user)) {
                        throw new \RuntimeException('Existing refresh metadata is not active and consistent.', 409);
                    }
                }
                return ['credentialHash' => $hash, 'valueHash' => hash('sha256', $captured['value']),
                    'metadataHash' => $captured['metadata'] === null ? null : hash('sha256', $captured['metadata']),
                    'userId' => $owner, 'userEpoch' => $this->store->epoch($owner),
                    'accountFingerprint' => $fingerprint, 'accountEpoch' => LegacyRefreshAccountRepository::accountEpoch($user), 'authContext' => (object) DeviceSnContext::fromArray($session),
                    'familyId' => bin2hex(random_bytes(16)), 'expiresAtMs' => $captured['expires_at_ms']];
            });
        }
        return ['version' => 'y1-refresh-metadata-review-v2', 'source' => $this->source,
            'siblingInventorySha256' => $inventory['siblingInventorySha256'], 'entries' => $entries];
    }

    public function apply(array $review): array
    {
        $entries = $this->normalize($review);
        $keys = $this->keys($review['siblingInventorySha256']);
        if (array_keys($keys) !== array_column($entries, 'credentialHash')) {
            throw new \RuntimeException('Reviewed refresh credential set changed.', 409);
        }
        // Full preflight before the first mutation. Each row is checked again under its lock before CAS.
        foreach ($entries as $entry) {
            $this->accounts->withLockedUser($entry['userId'], fn (array $user) => $this->check($keys[$entry['credentialHash']], $entry, $user));
        }
        $applied = 0;
        foreach ($entries as $entry) {
            $raw = $keys[$entry['credentialHash']];
            $this->accounts->withLockedUser($entry['userId'], function (array $user) use ($raw, $entry, &$applied): void {
                $captured = $this->check($raw, $entry, $user);
                if ($entry['metadataHash'] !== null) return;
                $this->store->backfill($raw, $captured['value'], $entry);
                $applied++;
            });
        }
        if (array_keys($this->keys($review['siblingInventorySha256'])) !== array_keys($keys)) {
            throw new \RuntimeException('Refresh credential set changed during metadata application; review partial metadata again.', 409);
        }
        return ['total' => count($entries), 'applied' => $applied, 'existing' => count($entries) - $applied,
            'independentReady' => false];
    }

    private function check(string $raw, array $entry, array $user): array
    {
        $current = $this->store->capture($raw);
        if (!is_string($current['value']) || $current['ttl_ms'] <= 0
            || hash('sha256', $current['value']) !== $entry['valueHash']
            || ($current['metadata'] === null ? null : hash('sha256', $current['metadata'])) !== $entry['metadataHash']
            || abs($current['expires_at_ms'] - $entry['expiresAtMs']) > 2
            || LegacyRefreshAccountRepository::fingerprint($user) !== $entry['accountFingerprint']
            || LegacyRefreshAccountRepository::accountEpoch($user) !== $entry['accountEpoch']
            || $this->store->epoch($entry['userId']) !== $entry['userEpoch']) {
            throw new \RuntimeException('Reviewed refresh source or account changed.', 409);
        }
        $session = LegacyRefreshReplayStore::decodeSession($current['value'], $raw);
        if ($session['user_id'] !== $entry['userId'] || DeviceSnContext::fromArray($session) !== $entry['authContext']) {
            throw new \RuntimeException('Reviewed refresh provenance changed.', 409);
        }
        return $current;
    }

    private function keys(string $siblingsSha256): array
    {
        $inventory = LegacyRefreshNamespaceInspector::scan($this->redis);
        if ($inventory['siblingInventorySha256'] !== $siblingsSha256) throw new \RuntimeException('Reviewed sibling namespace changed.', 409);
        return array_map(static fn(array $token): string => $token['raw'], $inventory['tokens']);
    }

    private function normalize(array $review): array
    {
        if (array_keys($review) !== ['version', 'source', 'siblingInventorySha256', 'entries'] || $review['version'] !== 'y1-refresh-metadata-review-v2' || $review['source'] !== $this->source
            || !is_string($review['siblingInventorySha256']) || preg_match('/^[a-f0-9]{64}$/D', $review['siblingInventorySha256']) !== 1
            || !is_array($review['entries']) || !array_is_list($review['entries']) || count($review['entries']) > 100000) {
            throw new \RuntimeException('Invalid reviewed metadata manifest.', 400);
        }
        $prior = '';
        foreach ($review['entries'] as &$entry) {
            if (!is_array($entry) || array_keys($entry) !== ['credentialHash', 'valueHash', 'metadataHash', 'userId', 'userEpoch',
                'accountFingerprint', 'accountEpoch', 'authContext', 'familyId', 'expiresAtMs']) throw new \RuntimeException('Invalid reviewed metadata entry.', 400);
            foreach (['credentialHash', 'valueHash', 'accountFingerprint'] as $field) {
                if (!is_string($entry[$field]) || preg_match('/^[a-f0-9]{64}$/D', $entry[$field]) !== 1) throw new \RuntimeException('Invalid reviewed metadata hash.', 400);
            }
            if ($entry['metadataHash'] !== null && (!is_string($entry['metadataHash']) || preg_match('/^[a-f0-9]{64}$/D', $entry['metadataHash']) !== 1)) {
                throw new \RuntimeException('Invalid reviewed metadata hash.', 400);
            }
            LegacyRefreshAccountRepository::accountEpoch(['_identity_account_epoch' => $entry['accountEpoch']]);
            $context = is_object($entry['authContext']) ? (array) $entry['authContext'] : $entry['authContext'];
            if (!is_array($context) || DeviceSnContext::fromArray($context) !== $context
                || !is_int($entry['userId']) || $entry['userId'] < 1 || !is_int($entry['userEpoch']) || $entry['userEpoch'] < 0
                || !is_int($entry['expiresAtMs']) || $entry['expiresAtMs'] < 1 || !is_string($entry['familyId'])
                || preg_match('/^[a-f0-9]{32}$/D', $entry['familyId']) !== 1 || strcmp($prior, $entry['credentialHash']) >= 0) {
                throw new \RuntimeException('Invalid reviewed metadata identity or order.', 400);
            }
            $prior = $entry['credentialHash']; $entry['authContext'] = $context;
        }
        return $review['entries'];
    }
}
