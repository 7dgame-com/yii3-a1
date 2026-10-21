<?php

declare(strict_types=1);

namespace App\Admission;

/** One synchronous request context; local nested writers reuse the entered lease. */
final class AdmissionLeaseScope
{
    private static ?self $current = null;
    private ?string $capability = null;
    private bool $entered = false;
    private bool $handlerFinished = false;
    private bool $unknown = false;
    private bool $finished = false;
    private bool $requiresAbandon = false;
    private bool $started = false;
    private int $startedAtNs = 0;
    private bool $childrenIssued = false;
    private array $enteredIdentity = [];
    private bool $unknownReported = false;
    private bool $lostReported = false;
    public function __construct(public readonly AdmissionSettings $settings, private readonly AdmissionControlClient $client) {}
    public static function install(?self $scope): ?self { $previous = self::$current; self::$current = $scope; return $previous; }
    public static function current(): ?self { return self::$current; }
    public static function assertWriter(): void
    {
        $scope = self::$current;
        if ($scope !== null && !$scope->settings->enabled) return;
        if ($scope === null && !AdmissionSettings::enabledFromEnvironment()) return;
        if ($scope === null || !$scope->entered || $scope->finished || $scope->unknown) throw new \RuntimeException('An entered request lease is required before side effects.', 503);
    }
    public function begin(?string $incoming = null): void
    {
        if (!$this->settings->enabled) return;
        if ($this->started) throw new \RuntimeException('A request lease cannot be started twice.', 503);
        $this->started = true;
        $this->startedAtNs = hrtime(true);
        $provided = $incoming !== null;
        if ($provided && (strlen($incoming) > 2048 || !preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{43}$/D', $incoming))) throw new AdmissionDenied('INVALID_INGRESS_CAPABILITY');
        try {
            $this->requiresAbandon = true;
            $this->client->call('register', ['identity' => $this->settings->identity]);
            if ($provided) $this->capability = $incoming;
            else {
                $lease = $this->client->call('begin');
                if (!is_string($lease['capability'] ?? null)) throw new \UnexpectedValueException();
                $this->capability = $lease['capability'];
            }
            $entered = $this->client->call('enter', ['capability' => $this->capability]);
            if (!is_string($entered['operationId'] ?? null) || !is_string($entered['rootOperationId'] ?? null) || !is_int($entered['epoch'] ?? null) || $entered['epoch'] < 1) throw new \UnexpectedValueException();
            $this->entered = true;
            $this->enteredIdentity = ['operationId' => $entered['operationId'], 'rootOperationId' => $entered['rootOperationId'], 'epoch' => $entered['epoch']];
        } catch (AdmissionDenied $error) {
            // A public replay/forgery cannot mark somebody else's live lease unknown.
            if ($provided || $this->capability === null) {
                $this->capability = null;
                $this->requiresAbandon = false;
            } else $this->abandon();
            throw $error;
        } catch (\Throwable) { $this->abandon(true); throw new \RuntimeException('Admission result is unknown.', 503); }
    }
    public function handlerFinished(bool $successfulBoundary): void { $this->handlerFinished = true; if (!$successfulBoundary) $this->unknown = true; }
    public function markUnknown(): void { if ($this->settings->enabled) $this->unknown = true; }
    public function frameworkError(): void { if ($this->handlerFinished) $this->markUnknown(); }
    public static function unknownCurrent(): void { self::$current?->markUnknown(); }
    public function finish(bool $sideEffectsSettled): void
    {
        if (!$this->settings->enabled) { $this->finished = true; return; }
        if (!$this->entered || !$this->handlerFinished || !$sideEffectsSettled || $this->unknown) { $this->abandon(); return; }
        try {
            $this->awaitChildren();
            $status = $this->client->call('complete', ['capability' => $this->capability,
                'evidence' => ['handlerFinished' => true, 'sideEffectsFinished' => true, 'sessionWriteClosed' => true]]);
            $this->finished = true;
            if (($status['phase'] ?? null) === 'closing') $this->acknowledgeClose($status);
        } catch (\Throwable) { $this->abandon(true); throw new \RuntimeException('Admission completion result is unknown.', 503); }
    }
    private function awaitChildren(): void
    {
        // The original root start fixes the child deadline. Handler time and
        // control latency consume it; finish never creates a fresh allowance.
        $deadline = $this->startedAtNs + $this->settings->childrenWaitMs * 1000000;
        do {
            $remaining = (int) floor(($deadline - hrtime(true)) / 1000000);
            if ($this->childrenIssued && $remaining < 1) throw new \RuntimeException('Child completion deadline expired.', 503);
            $state = $this->client->call('validate', ['capability' => $this->capability], $this->childrenIssued ? $remaining : null);
            foreach ($this->enteredIdentity as $field => $value) if (($state[$field] ?? null) !== $value) throw new \UnexpectedValueException();
            if (($state['unknown'] ?? null) !== false || !is_int($state['childrenActive'] ?? null) || $state['childrenActive'] < 0) throw new \UnexpectedValueException();
            if ($state['childrenActive'] === 0) return;
            if (!$this->childrenIssued) throw new \UnexpectedValueException();
            $remaining = $deadline - hrtime(true);
            if ($remaining <= 0) throw new \RuntimeException('Child completion deadline expired.', 503);
            usleep((int) min(25000, ceil($remaining / 1000)));
        } while (true);
    }
    private function acknowledgeClose(array $status): void
    {
        foreach ($status['instances'] ?? [] as $row) if (($row['id'] ?? null) === $this->settings->identity['id']) {
            if (!is_int($status['epoch'] ?? null) || !is_int($row['lastBegin'] ?? null) || !is_int($row['lastEnd'] ?? null)) throw new \UnexpectedValueException();
            $this->client->call('close-ack', ['epoch' => $status['epoch'], 'lastBegin' => $row['lastBegin'], 'lastEnd' => $row['lastEnd']]); return;
        }
        throw new \UnexpectedValueException();
    }
    public function abandon(bool $lost = false): void
    {
        if (!$this->settings->enabled || !$this->requiresAbandon || ($this->finished && !$lost)) return;
        $this->unknown = true;
        if ($this->capability !== null && !$this->unknownReported) {
            $this->unknownReported = true;
            try { $this->client->call('unknown', ['capability' => $this->capability]); } catch (\Throwable) { $lost = true; }
        }
        if (($lost || $this->capability === null) && !$this->lostReported) {
            $this->lostReported = true;
            try { $this->client->call('lost'); } catch (\Throwable) {}
        }
    }
    /** Contract only: receiver integration is not implied by generating a child. */
    public function continuationHeaders(string $targetId, string $existingInternalToken): array
    {
        self::assertWriter();
        if (!$this->settings->enabled) return [];
        if (self::$current !== $this) { $this->markUnknown(); throw new \RuntimeException('An entered request lease is required before side effects.', 503); }
        if ($existingInternalToken === '' || preg_match('/[\r\n\x00]/', $existingInternalToken) || !in_array($targetId, $this->settings->identity['allowedChildren'], true)) throw new \RuntimeException('A reviewed protected continuation is required.', 503);
        $deadline = $this->startedAtNs + $this->settings->childrenWaitMs * 1000000;
        $remaining = (int) floor(($deadline - hrtime(true)) / 1000000);
        if ($remaining < 1) { $this->markUnknown(); throw new \RuntimeException('Child admission deadline expired.', 503); }
        $this->childrenIssued = true;
        try { $child = $this->client->call('continue', ['capability' => $this->capability, 'targetId' => $targetId], $remaining); }
        catch (AdmissionDenied $error) { $this->markUnknown(); throw $error; }
        catch (\Throwable) { $this->abandon(true); throw new \RuntimeException('Continuation result is unknown.', 503); }
        if (hrtime(true) >= $deadline || self::$current !== $this || !$this->entered || $this->finished || $this->unknown) {
            $this->markUnknown(); throw new \RuntimeException('Child admission deadline expired or result is unknown.', 503);
        }
        if (!is_string($child['capability'] ?? null)) { $this->abandon(true); throw new \RuntimeException('Continuation result is unknown.', 503); }
        return ['X-Identity-Admission-Lease' => $child['capability'], 'X-Identity-Internal-Token' => $existingInternalToken];
    }
}
