<?php

declare(strict_types=1);

namespace App\Admission;

use Psr\Container\ContainerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Completion belongs outside the real runner, never to response/connection events. */
final class AdmissionHttpLifecycle
{
    public function __construct(private readonly AdmissionLeaseScope $scope) {}
    public function enabled(): bool { return $this->scope->settings->enabled; }
    public static function fromEnvironment(string $root): self
    {
        $settings = AdmissionSettings::fromEnvironment($root);
        return new self(new AdmissionLeaseScope($settings, new AdmissionControlClient($settings)));
    }
    public static function incomingCapability(array $server): ?string
    {
        $value = $server['HTTP_X_IDENTITY_ADMISSION_LEASE'] ?? null;
        if ($value !== null && !is_string($value)) throw new AdmissionDenied('INVALID_INGRESS_CAPABILITY');
        if (function_exists('getallheaders')) {
            $values = [];
            foreach (getallheaders() ?: [] as $name => $header) if (strcasecmp($name, 'X-Identity-Admission-Lease') === 0) $values[] = $header;
            if (($value !== null || $values !== []) && (count($values) !== 1 || $values[0] !== $value)) throw new AdmissionDenied('INVALID_INGRESS_CAPABILITY');
        }
        return $value;
    }
    public static function stripServerMarkers(array $server): array
    {
        foreach (array_keys($server) as $name) if (str_starts_with($name, 'HTTP_')
            && \App\Middleware\AdmissionMiddleware::privateMarker(str_replace('_', '-', substr($name, 5)))) unset($server[$name]);
        return $server;
    }
    public static function settled(ContainerInterface $container): bool
    {
        $db = $container->get(ConnectionInterface::class);
        if ($db->getTransaction()?->isActive()) return false;
        if (session_status() === PHP_SESSION_ACTIVE && !session_write_close()) return false;
        return session_status() !== PHP_SESSION_ACTIVE;
    }
    public function execute(callable $runner, ?callable $settled = null, ?string $incoming = null): void
    {
        $previous = AdmissionLeaseScope::install($this->scope); $returned = false;
        $oldAbort = $this->scope->settings->enabled ? ignore_user_abort(true) : null;
        if ($this->scope->settings->enabled) register_shutdown_function(function () use (&$returned): void {
            // exit/fatal never reaches a real runner return. SIGKILL cannot run
            // this callback at all, so the coordinator's active row remains.
            if (!$returned) $this->scope->abandon();
        });
        try {
            $this->scope->begin($incoming);
            $runner(); // includes startup/handle/emit/afterEmit/shutdown
            $returned = true;
        } catch (\Throwable $error) { $this->scope->markUnknown(); throw $error; }
        finally {
            try {
                if ($returned) {
                    try {
                        $clean = !$this->scope->settings->enabled || (($settled === null || $settled()) && connection_aborted() === 0);
                        $this->scope->finish($clean);
                    } catch (\Throwable) {
                        // The response may already be emitted. Never re-emit it
                        // or pretend a lost completion ACK proved zero work.
                        $this->scope->abandon(true);
                    }
                } else $this->scope->abandon();
            } finally {
                AdmissionLeaseScope::install($previous);
                if ($oldAbort !== null) ignore_user_abort((bool) $oldAbort);
            }
        }
    }
}
