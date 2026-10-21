<?php

declare(strict_types=1);

namespace App\Service;

final class LegacyRefreshReplayService
{
    public function __construct(
        private readonly LegacyRefreshReplayStore $store,
        private readonly LegacyRefreshAccountRepository $accounts,
    ) {
    }

    public function enabled(): bool { return $this->store->enabled(); }

    /** Null is only a truly unknown credential eligible for legacy QR lookup. */
    public function rotate(string $raw, array $requestContext, callable $authorize, callable $prepare): ?array
    {
        \App\Admission\AdmissionLeaseScope::assertWriter();
        $key = $requestContext['idempotencyKey'] ?? '';
        if (!is_string($key) || strlen($key) > 256 || preg_match('/[^\x21-\x7e]/', $key)) {
            throw new \RuntimeException('Invalid Idempotency-Key.', 400);
        }
        $requestHash = $key === '' ? null : hash('sha256', $key);
        $initial = $this->store->inspect($raw, $requestHash);
        if ($initial['status'] === 'miss') return null;
        if ($initial['status'] === 'unannotated') throw new \RuntimeException('Refresh metadata baseline is incomplete.', 503);
        if (!in_array($initial['status'], ['active', 'replay'], true)) throw new \RuntimeException('Refresh token is invalid.', 401);
        $owner = $initial['metadata']['user_id'];
        return $this->accounts->withLockedUser($owner, function (array $user) use ($raw, $requestHash, $authorize, $prepare, $owner): array {
            // Re-read under the SQL account lock: prepare and replay cannot use a stale account snapshot.
            $snapshot = $this->store->inspect($raw, $requestHash);
            if (!in_array($snapshot['status'], ['active', 'replay'], true)
                || $snapshot['metadata']['user_id'] !== $owner
                || !hash_equals($snapshot['metadata']['account_fingerprint'], LegacyRefreshAccountRepository::fingerprint($user))
                || $snapshot['metadata']['account_epoch'] !== LegacyRefreshAccountRepository::accountEpoch($user)) {
                throw new \RuntimeException('Refresh token is invalid.', 401);
            }
            $context = DeviceSnContext::fromArray($snapshot['metadata']['auth_context']);
            $authorize($owner, $context);
            if ($snapshot['status'] === 'replay') {
                // Authorization may take time. Logout/revocation during it must not reuse an old read.
                $confirmed = $this->store->inspect($raw, $requestHash);
                if ($confirmed['status'] !== 'replay' || $confirmed['metadata']['user_id'] !== $owner
                    || $confirmed['metadata']['account_fingerprint'] !== $snapshot['metadata']['account_fingerprint']
                    || $confirmed['metadata']['account_epoch'] !== LegacyRefreshAccountRepository::accountEpoch($user)) {
                    throw new \RuntimeException('Refresh token is invalid.', 401);
                }
                return $this->store->response($confirmed);
            }
            $successor = ($context === [] ? '' : 'dsn_') . bin2hex(random_bytes(32));
            $response = $prepare($user, $context, $successor);
            return $this->store->rotate($raw, $snapshot, $successor, $response, $requestHash);
        });
    }

    public function create(string $raw, int $userId, array $context, ?string $expectedFingerprint = null): void
    {
        \App\Admission\AdmissionLeaseScope::assertWriter();
        $this->accounts->withLockedUser($userId, function (array $user) use ($raw, $userId, $context, $expectedFingerprint): void {
            $fingerprint = LegacyRefreshAccountRepository::fingerprint($user);
            if ($expectedFingerprint !== null && !hash_equals($expectedFingerprint, $fingerprint)) {
                throw new \RuntimeException('Account credentials changed; authenticate again.', 401);
            }
            $this->store->create($raw, $userId, $context, $fingerprint, LegacyRefreshAccountRepository::accountEpoch($user));
        });
    }

    public function revoke(string $raw): void
    {
        \App\Admission\AdmissionLeaseScope::assertWriter();
        // The Redis script is the same linearization boundary as rotation.
        $this->store->revoke($raw);
    }

    public function validate(string $raw): ?int
    {
        $snapshot = $this->store->inspect($raw);
        if ($snapshot['status'] !== 'active') return null;
        $owner = $snapshot['metadata']['user_id'];
        return $this->accounts->withLockedUser($owner, function (array $user) use ($raw, $owner): ?int {
            $current = $this->store->inspect($raw);
            if ($current['status'] !== 'active' || $current['metadata']['user_id'] !== $owner
                || !hash_equals($current['metadata']['account_fingerprint'], LegacyRefreshAccountRepository::fingerprint($user))
                || $current['metadata']['account_epoch'] !== LegacyRefreshAccountRepository::accountEpoch($user)
                || DeviceSnContext::fromArray($current['metadata']['auth_context']) !== []) return null;
            return $owner;
        });
    }

    public function revokeUser(int $userId, callable $removeLegacyKeys): void
    {
        \App\Admission\AdmissionLeaseScope::assertWriter();
        $this->accounts->withLockedUser($userId, function () use ($userId, $removeLegacyKeys): void {
            $this->store->advanceEpoch($userId);
            $removeLegacyKeys();
        }, false);
    }
}
