<?php

declare(strict_types=1);

namespace App\Service;

use Closure;
use RuntimeException;

/** Identity is the sole account/session authority after the explicit ownership switch. */
final class IdentityAuthorityClient
{
    private bool $enabled;

    /** @param Closure(string,array,array,int,string):array{status:int,body:array}|null $transport */
    public function __construct(
        string $dataSource = 'legacy',
        private readonly string $baseUrl = '',
        private readonly string $internalToken = '',
        private readonly string $iamToken = '',
        private readonly int $timeoutMs = 1500,
        bool $ready = false,
        private readonly ?Closure $transport = null,
        bool $legacyFixtureEnabled = false,
    ) {
        if (!in_array($dataSource, ['legacy', 'identity'], true)) {
            throw new RuntimeException('IDENTITY_DATA_SOURCE must be legacy or identity.', 503);
        }
        $this->enabled = $dataSource === 'identity';
        if ($this->enabled && ($legacyFixtureEnabled || !$ready || !$this->validBaseUrl() || $internalToken === '' || $iamToken === ''
            || strlen($internalToken) > 4096 || strlen($iamToken) > 4096 || preg_match('/[\r\n\x00]/', $internalToken . $iamToken)
            || $timeoutMs < 100 || $timeoutMs > 10000)) {
            throw new RuntimeException('Independent Identity readiness or configuration is incomplete.', 503);
        }
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function login(string $username, string $password, array $context = []): array
    {
        $response = $this->request('/v1/auth/login', compact('username', 'password'), context: $context);
        $tokens = $this->tokens($response['token'] ?? []);
        return ['authTokens' => $tokens, 'subject' => $this->verifiedSubject($tokens['accessToken'])];
    }

    public function refresh(string $refreshToken, bool $allowLoginCode, array $context = [], string $sourceEndpoint = '/v1/auth/refresh'): array
    {
        if (!in_array($sourceEndpoint, ['/v1/auth/refresh', '/v2/auth/refresh-token'], true)) {
            throw new RuntimeException('Invalid Yii3 refresh source.', 503);
        }
        return $this->result($this->request('/internal/yii3/refresh', compact('refreshToken', 'allowLoginCode', 'sourceEndpoint'), $this->internalToken, context: $context), true);
    }

    public function loginCode(string $key, bool $contextOnly = false, array $context = []): array
    {
        $body = $this->request('/internal/yii3/login-code', ['key' => $key, 'operation' => $contextOnly ? 'context' : 'redeem'], $this->internalToken, context: $context);
        if ($contextOnly) return ['frontendDomain' => $this->frontendDomain($body['frontendDomain'] ?? null)];
        return $this->result($body);
    }

    public function logout(string $refreshToken): array
    {
        $this->request('/v1/auth/logout', compact('refreshToken'));
        return ['success' => true, 'message' => 'logout'];
    }

    public function issueDevice(int $userId, int $snId, array $context = []): array
    {
        $response = $this->request('/internal/auth/issue-user-token', [
            'legacyUserId' => $userId, 'auth_method' => 'device_sn', 'device_sn_id' => $snId,
        ], $this->internalToken, context: $context);
        $tokens = $this->tokens($response['token'] ?? []);
        $subject = $this->verifiedSubject($tokens['accessToken']);
        if ($subject['id'] !== $userId || ($subject['auth_method'] ?? null) !== 'device_sn' || ($subject['device_sn_id'] ?? null) !== $snId) {
            throw new RuntimeException('Identity did not preserve device authorization.', 503);
        }
        return ['authTokens' => $tokens, 'subject' => $subject];
    }

    /** Invalid credentials return null; an unavailable authority remains a 503. */
    public function verifyAccessToken(string $token): ?array
    {
        try {
            $body = $this->request('/internal/auth/verify-access-token', compact('token'), $this->iamToken);
            return $this->subject($body['data'] ?? null);
        } catch (RuntimeException $exception) {
            if ($exception->getCode() === 401) return null;
            throw $exception;
        }
    }

    public function deviceAccount(int $id): array
    {
        $body = $this->request('/internal/identity/subjects/' . $id, [], $this->iamToken, 'GET');
        $subject = $this->subject($body['data'] ?? null);
        if ($subject['id'] !== $id) throw new RuntimeException('Identity returned invalid account data.', 503);
        if (array_intersect(['root', 'admin', 'manager'], array_map('strtolower', $subject['roles'])) !== []) {
            throw new RuntimeException('This account is not eligible for device SN login.', 401);
        }
        return $subject;
    }

    private function result(array $body, bool $refresh = false): array
    {
        $result = ['authTokens' => $this->tokens($body['authTokens'] ?? []), 'subject' => $this->subject($body['subject'] ?? null)];
        if (array_key_exists('frontendDomain', $body)) $result['frontendDomain'] = $this->frontendDomain($body['frontendDomain']);
        if (array_key_exists('legacyResponse', $body)) {
            if (!$refresh) throw new RuntimeException('Unexpected legacy response.', 503);
            $result['legacyResponseBody'] = LegacyIdentityRefreshResponse::body($body['legacyResponse'], $result['authTokens'], $result['subject']['id']);
        }
        return $result;
    }

    private function verifiedSubject(string $token): array
    {
        return $this->verifyAccessToken($token) ?? throw new RuntimeException('Authentication credentials are invalid.', 401);
    }

    private function subject(mixed $body): array
    {
        if (!is_array($body) || !is_int($body['id'] ?? null) || $body['id'] <= 0
            || !is_string($body['username'] ?? null) || !is_array($body['roles'] ?? null)
            || array_filter($body['roles'], static fn($role) => !is_string($role)) !== []) {
            throw new RuntimeException('Identity returned invalid account data.', 503);
        }
        if (($body['status'] ?? null) !== 10 || ($body['deletedAt'] ?? null) !== null) {
            throw new RuntimeException('Authentication credentials are invalid.', 401);
        }
        return ['id' => $body['id'], 'username' => $body['username'], 'nickname' => (string)($body['nickname'] ?? ''),
            'status' => 10, 'roles' => $body['roles'], 'fixture' => false]
            + DeviceSnContext::fromArray($body);
    }

    private function tokens(mixed $body): array
    {
        if (!is_array($body)) throw new RuntimeException('Identity returned invalid session data.', 503);
        $access = $body['accessToken'] ?? null;
        $refresh = $body['refreshToken'] ?? null;
        $expires = $body['expires'] ?? null;
        if (!is_string($access) || $access === '' || strlen($access) > 16384 || !is_string($refresh)
            || $refresh === '' || strlen($refresh) > 4096 || !is_string($expires) || $expires === '') {
            throw new RuntimeException('Identity returned invalid session data.', 503);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?Z$/D', $expires) !== 1) {
            throw new RuntimeException('Identity returned invalid session expiry.', 503);
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', str_replace('Z', str_contains($expires, '.') ? 'Z' : '.000Z', $expires), new \DateTimeZone('UTC'));
        if ($date === false || \DateTimeImmutable::getLastErrors() !== false) throw new RuntimeException('Identity returned invalid session expiry.', 503);
        return ['accessToken' => $access, 'expires' => $date->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s'), 'refreshToken' => $refresh];
    }

    private function frontendDomain(mixed $value): ?string
    {
        if ($value === null) return null;
        if (!is_string($value) || $value === '' || strlen($value) > 253 || $value !== strtolower($value) || str_ends_with($value, '.')) {
            throw new RuntimeException('Identity returned invalid login-code context.', 503);
        }
        if ($value !== 'localhost' && filter_var($value, FILTER_VALIDATE_IP) === false
            && preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $value) !== 1) {
            throw new RuntimeException('Identity returned invalid login-code context.', 503);
        }
        return $value;
    }

    private function validBaseUrl(): bool
    {
        $parts = parse_url($this->baseUrl);
        return is_array($parts) && in_array($parts['scheme'] ?? null, ['http', 'https'], true) && isset($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment']);
    }

    private function request(string $path, array $payload, string $internalToken = '', string $method = 'POST', array $context = []): array
    {
        if (!$this->enabled) throw new RuntimeException('Independent Identity is not enabled.', 503);
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($internalToken !== '') $headers['X-Identity-Internal-Token'] = $internalToken;
        foreach (['idempotencyKey' => 'Idempotency-Key', 'userAgent' => 'User-Agent'] as $field => $header) {
            if (!isset($context[$field]) || $context[$field] === '') continue;
            if (!is_string($context[$field]) || strlen($context[$field]) > ($field === 'idempotencyKey' ? 256 : 4096)
                || preg_match($field === 'idempotencyKey' ? '/[\x00-\x1f\x7f]/' : '/[\r\n\x00]/', $context[$field])) throw new RuntimeException('Authentication request context is invalid.', 400);
            $headers[$header] = $context[$field];
        }
        try {
            $response = $this->transport !== null
                ? ($this->transport)($path, $payload, $headers, $this->timeoutMs, $method)
                : $this->http($path, $payload, $headers, $method);
        } catch (\Throwable) {
            throw new RuntimeException('Identity authority is unavailable.', 503);
        }
        $status = $response['status'] ?? 0;
        if ($status === 401 || $status === 403) throw new RuntimeException('Authentication credentials are invalid.', 401);
        if ($status === 400) throw new RuntimeException('Authentication request is invalid.', 400);
        if ($status < 200 || $status >= 300 || !is_array($response['body'] ?? null)) {
            throw new RuntimeException('Identity authority is unavailable.', 503);
        }
        return $response['body'];
    }

    private function http(string $path, array $payload, array $headers, string $method): array
    {
        $lines = [];
        foreach ($headers as $name => $value) $lines[] = $name . ': ' . $value;
        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $lines),
            'content' => $method === 'GET' ? '' : json_encode($payload, JSON_THROW_ON_ERROR), 'timeout' => $this->timeoutMs / 1000,
            'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0]]);
        $body = @file_get_contents(rtrim($this->baseUrl, '/') . $path, false, $context, 0, 1048577);
        if (!is_string($body) || strlen($body) > 1048576) throw new RuntimeException('Identity transport failed.');
        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('/^HTTP\/\S+ (\d{3})/', $statusLine, $match) !== 1) throw new RuntimeException('Identity transport failed.');
        return ['status' => (int)$match[1], 'body' => json_decode($body, true, 64, JSON_THROW_ON_ERROR)];
    }
}
