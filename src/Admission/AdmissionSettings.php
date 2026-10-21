<?php

declare(strict_types=1);

namespace App\Admission;

/** Default-off, same-host protocol candidate. Never generates a boot key. */
final class AdmissionSettings
{
    public function __construct(
        public readonly bool $enabled = false,
        public readonly string $controlUrl = '',
        public readonly string $key = '',
        public readonly array $identity = [],
        public readonly int $timeoutMs = 1000,
        public readonly int $childrenWaitMs = 1500,
    ) {
        if (!$enabled) return;
        $url = parse_url($controlUrl);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'http' || ($url['host'] ?? '') !== '127.0.0.1'
            || !isset($url['port']) || $url['port'] < 1 || $url['port'] > 65535 || ($url['path'] ?? '/') !== '/'
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || strlen($key) < 32 || strlen($key) > 4096 || $timeoutMs < 100 || $timeoutMs > 2000 || $childrenWaitMs < 100 || $childrenWaitMs > 5000
            || array_keys($identity) !== ['id', 'bootId', 'revision', 'routerHash', 'generation', 'requiresSessionClose', 'allowedChildren']
            || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $identity['id'] ?? '')
            || !preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $identity['bootId'] ?? '')
            || !preg_match('/^[a-f0-9]{40}$/D', $identity['revision'] ?? '')
            || !preg_match('/^[a-f0-9]{64}$/D', $identity['routerHash'] ?? '')
            || !in_array($identity['generation'] ?? null, ['legacy', 'identity'], true)
            || !is_bool($identity['requiresSessionClose']) || !is_array($identity['allowedChildren']) || !array_is_list($identity['allowedChildren'])
            || count($identity['allowedChildren']) > 32
            || array_filter($identity['allowedChildren'], static fn ($id): bool => !is_string($id) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id) !== 1)) {
            throw new \RuntimeException('Admission configuration is incomplete.', 503);
        }
    }

    private static function env(string $name, string $default = ''): string
    {
        $v = $_ENV[$name] ?? getenv($name);
        return is_string($v) ? $v : $default;
    }

    public static function enabledFromEnvironment(): bool
    {
        $value = self::env('Y1_ADMISSION_ENABLED', 'false');
        if (!in_array($value, ['false', 'true'], true)) throw new \RuntimeException('Y1_ADMISSION_ENABLED must be true or false.', 503);
        return $value === 'true';
    }

    public static function fromEnvironment(string $root): self
    {
        if (!self::enabledFromEnvironment()) return new self();
        // Build metadata must come from the actual image, never a request or an
        // arbitrary self-reported revision. Deployment provenance is separate.
        $revisionPath = $root . '/GIT_COMMIT';
        $revisionStat = @lstat($revisionPath);
        if (!$revisionStat || !is_file($revisionPath) || is_link($revisionPath) || ($revisionStat['mode'] & 0222) !== 0
            || $revisionStat['nlink'] !== 1 || $revisionStat['size'] > 41) throw new \RuntimeException('Read-only actual build revision is required.', 503);
        $revision = trim((string) @file_get_contents($revisionPath));
        $keyPath = self::env('Y1_ADMISSION_KEY_FILE');
        $stat = $keyPath === '' ? false : @lstat($keyPath);
        if (!$stat || !is_file($keyPath) || is_link($keyPath) || ($stat['mode'] & 0077) !== 0 || $stat['nlink'] !== 1 || $stat['size'] > 4096) {
            throw new \RuntimeException('An existing private boot-scoped control key is required.', 503);
        }
        $handle = @fopen($keyPath, 'rb');
        if ($handle === false) throw new \RuntimeException('An existing private boot-scoped control key is required.', 503);
        try {
            $opened = fstat($handle);
            if ($opened === false || $opened['ino'] !== $stat['ino'] || $opened['dev'] !== $stat['dev'] || $opened['size'] !== $stat['size']
                || ($opened['mode'] & 0077) !== 0 || ($opened['mode'] & 0170000) !== 0100000 || $opened['nlink'] !== 1) throw new \RuntimeException('Admission control key changed.', 503);
            $key = stream_get_contents($handle, 4097);
        } finally { fclose($handle); }
        try { $children = json_decode(self::env('Y1_ADMISSION_ALLOWED_CHILDREN', '[]'), true, 8, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new \RuntimeException('Admission roster is invalid.', 503); }
        $session = self::env('Y1_ADMISSION_REQUIRES_SESSION_CLOSE', 'false');
        $timeout = self::env('Y1_ADMISSION_TIMEOUT_MS', '1000');
        $childrenWait = self::env('Y1_ADMISSION_CHILD_WAIT_MS', '1500');
        if (!in_array($session, ['false', 'true'], true) || !preg_match('/^[1-9][0-9]{2,3}$/D', $timeout)
            || !preg_match('/^[1-9][0-9]{2,3}$/D', $childrenWait)) throw new \RuntimeException('Admission configuration is incomplete.', 503);
        return new self(true, self::env('Y1_ADMISSION_CONTROL_URL'), $key, [
            'id' => self::env('Y1_ADMISSION_INSTANCE_ID'), 'bootId' => self::env('Y1_ADMISSION_BOOT_ID'),
            'revision' => $revision, 'routerHash' => self::env('Y1_ADMISSION_ROUTER_SHA256'),
            'generation' => self::env('Y1_ADMISSION_GENERATION'), 'requiresSessionClose' => $session === 'true', 'allowedChildren' => $children,
        ], (int) $timeout, (int) $childrenWait);
    }
}
