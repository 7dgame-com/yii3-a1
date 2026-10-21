<?php

declare(strict_types=1);

namespace App\Admission;

/** No redirects, retries, request credentials or capability-bearing errors. */
final class AdmissionControlClient
{
    private const SAFE_REFUSALS = [
        'begin' => ['ROOT_ADMISSION_CLOSED', 'REVIEWED_GENERATION_NOT_READY', 'MAX_INFLIGHT_REACHED'],
        'enter' => ['INVALID_OPERATION_CAPABILITY', 'OPERATION_NOT_ACTIVE', 'HANDLER_CAPABILITY_ALREADY_ENTERED_OR_WRONG_INSTANCE'],
        'continue' => ['CONTINUATION_NOT_AUTHORIZED', 'CONTINUATION_TARGET_NOT_REVIEWED', 'MAX_INFLIGHT_REACHED', 'CONTINUATION_DEPTH_REACHED'],
    ];
    private ?int $registeredEnd = null;
    /** @param \Closure(string,array,array,int):array{status:int,body:array}|null $transport Unit-only protocol seam. */
    public function __construct(private readonly AdmissionSettings $settings, private readonly ?\Closure $transport = null) {}

    public function call(string $operation, array $payload = [], ?int $remainingMs = null): array
    {
        if (!$this->settings->enabled || !in_array($operation, ['register', 'begin', 'enter', 'validate', 'continue', 'complete', 'unknown', 'lost', 'status', 'close-ack'], true)) {
            throw new \RuntimeException('Admission control operation is unavailable.', 503);
        }
        $i = $this->settings->identity;
        $timeout = $remainingMs === null ? $this->settings->timeoutMs : min($remainingMs, $this->settings->timeoutMs);
        if ($timeout < 1) throw new \RuntimeException('Admission control deadline expired.', 503);
        $headers = ['Authorization' => 'Bearer ' . rtrim(strtr(base64_encode($this->settings->key), '+/', '-_'), '='),
            'X-Identity-Instance-Id' => $i['id'], 'X-Identity-Instance-Boot-Id' => $i['bootId'], 'X-Identity-Instance-Revision' => $i['revision'],
            'X-Identity-Router-Hash' => $i['routerHash'], 'X-Identity-Generation' => $i['generation'], 'Content-Type' => 'application/json'];
        try {
            $bytes = json_encode($payload === [] ? (object) [] : $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen($bytes) > 8192) throw new \UnexpectedValueException();
            if ($this->transport !== null) $response = ($this->transport)($operation, $payload, $headers, $timeout);
            else {
                if (!function_exists('curl_init')) throw new \UnexpectedValueException();
                $handle = curl_init(rtrim($this->settings->controlUrl, '/') . '/' . $operation); $body = '';
                try {
                    curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $bytes,
                        CURLOPT_HTTPHEADER => array_map(static fn ($name, $value) => $name . ': ' . $value, array_keys($headers), $headers),
                        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP, CURLOPT_CONNECTTIMEOUT_MS => min(250, $timeout),
                        CURLOPT_TIMEOUT_MS => $timeout, CURLOPT_PROXY => '', CURLOPT_RETURNTRANSFER => false,
                        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                            if (strlen($body) + strlen($chunk) > 1024 * 1024) return 0; $body .= $chunk; return strlen($chunk);
                        }]);
                    if (curl_exec($handle) === false) throw new \UnexpectedValueException();
                    $response = ['status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => json_decode($body, true, 32, JSON_THROW_ON_ERROR)];
                } finally { curl_close($handle); }
            }
            if (!is_int($response['status'] ?? null) || !is_array($response['body'] ?? null)) throw new \UnexpectedValueException();
        } catch (\Throwable) { throw new \RuntimeException('Admission control result is unknown.', 503); }
        if ($response['status'] !== 200) {
            $code = $response['body']['code'] ?? '';
            // Journal/operation unknown, every 5xx and unreviewed refusal are
            // uncertain even if their body looks like an ordinary denial.
            if ($response['status'] === 409 && self::keys($response['body'], ['code']) && is_string($code)
                && in_array($code, self::SAFE_REFUSALS[$operation] ?? [], true)) throw new AdmissionDenied($code);
            throw new \RuntimeException('Admission control result is unknown.', 503);
        }
        try { $this->assertAcknowledgement($operation, $payload, $response['body']); }
        catch (\Throwable) { throw new \RuntimeException('Admission control result is unknown.', 503); }
        return $response['body'];
    }

    private static function keys(array $value, array $expected): bool
    {
        $actual = array_keys($value); sort($actual); sort($expected); return $actual === $expected;
    }
    private static function integer(mixed $value, int $minimum = 0): bool { return is_int($value) && $value >= $minimum && $value <= 9007199254740991; }
    private static function uuid(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $value) === 1; }

    /** Decoding binds a successful control ACK; it never authenticates a lease. */
    private static function capability(mixed $capability, string $instanceId): array
    {
        if (!is_string($capability) || strlen($capability) > 2048 || preg_match('/^([A-Za-z0-9_-]+)\.[A-Za-z0-9_-]{43}$/D', $capability, $parts) !== 1) throw new \UnexpectedValueException();
        $bytes = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if (!is_string($bytes) || rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=') !== $parts[1]) throw new \UnexpectedValueException();
        $body = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($body) || !self::keys($body, ['operationId', 'rootOperationId', 'instanceId', 'epoch', 'nonce'])
            || !self::uuid($body['operationId']) || !self::uuid($body['rootOperationId']) || !self::integer($body['epoch'], 1)
            || $body['instanceId'] !== $instanceId || !is_string($body['nonce']) || preg_match('/^[a-f0-9]{32}$/D', $body['nonce']) !== 1) throw new \UnexpectedValueException();
        return $body;
    }

    private function assertAcknowledgement(string $operation, array $payload, array $body): void
    {
        if (in_array($operation, ['unknown', 'lost'], true)) {
            if (!self::keys($body, ['accepted']) || $body['accepted'] !== true) throw new \UnexpectedValueException();
            return;
        }
        if (in_array($operation, ['begin', 'continue'], true)) {
            if (!self::keys($body, ['capability', 'operationId', 'epoch'])) throw new \UnexpectedValueException();
            $identity = self::capability($body['capability'], $operation === 'begin' ? $this->settings->identity['id'] : (string) ($payload['targetId'] ?? ''));
            if ($body['operationId'] !== $identity['operationId'] || $body['epoch'] !== $identity['epoch']) throw new \UnexpectedValueException();
            if ($operation === 'begin' && $identity['rootOperationId'] !== $identity['operationId']) throw new \UnexpectedValueException();
            if ($operation === 'continue') {
                $parent = self::capability($payload['capability'] ?? null, $this->settings->identity['id']);
                if ($identity['rootOperationId'] !== $parent['rootOperationId'] || $identity['epoch'] !== $parent['epoch'] || $identity['operationId'] === $parent['operationId']) throw new \UnexpectedValueException();
            }
            return;
        }
        if (in_array($operation, ['enter', 'validate'], true)) {
            $fields = ['operationId', 'rootOperationId', 'epoch'];
            if (!self::keys($body, $operation === 'enter' ? $fields : [...$fields, 'unknown', 'childrenActive'])) throw new \UnexpectedValueException();
            $identity = self::capability($payload['capability'] ?? null, $this->settings->identity['id']);
            foreach ($fields as $field) if ($body[$field] !== $identity[$field]) throw new \UnexpectedValueException();
            if ($operation === 'validate' && ($body['unknown'] !== false || !self::integer($body['childrenActive']))) throw new \UnexpectedValueException();
            return;
        }
        if (!in_array($body['phase'] ?? null, ['legacy_open', 'closing', 'drained', 'prepared', 'committing', 'identity_open'], true)
            || !self::integer($body['epoch'] ?? null, 1) || ($body['unknownSealed'] ?? null) !== false
            || !self::integer($body['active'] ?? null) || !self::integer($body['unknown'] ?? null)
            || !is_array($body['instances'] ?? null) || !array_is_list($body['instances'])) throw new \UnexpectedValueException();
        $own = array_values(array_filter($body['instances'], fn ($row) => is_array($row) && ($row['id'] ?? null) === $this->settings->identity['id']));
        if (count($own) !== 1) throw new \UnexpectedValueException();
        $row = $own[0];
        if (($row['bootId'] ?? null) !== $this->settings->identity['bootId'] || ($row['generation'] ?? null) !== $this->settings->identity['generation']
            || ($row['registered'] ?? null) !== true || ($row['lost'] ?? null) !== false
            || !self::integer($row['lastBegin'] ?? null) || !self::integer($row['lastEnd'] ?? null) || $row['lastEnd'] > $row['lastBegin']) throw new \UnexpectedValueException();
        if ($operation === 'register') $this->registeredEnd = $row['lastEnd'];
        if ($operation === 'complete') {
            self::capability($payload['capability'] ?? null, $this->settings->identity['id']);
            if ($this->registeredEnd === null || $row['lastEnd'] <= $this->registeredEnd) throw new \UnexpectedValueException();
        }
        if ($operation === 'close-ack' && ($body['epoch'] !== ($payload['epoch'] ?? null) || $row['lastBegin'] !== ($payload['lastBegin'] ?? null)
            || $row['lastEnd'] !== ($payload['lastEnd'] ?? null) || ($row['closeAcknowledged'] ?? null) !== true)) throw new \UnexpectedValueException();
    }
}
