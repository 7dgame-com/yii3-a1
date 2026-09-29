<?php

declare(strict_types=1);

namespace App\Service;

use PDOException;
use RuntimeException;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\Exception as DatabaseException;
use Yiisoft\Db\Transaction\TransactionInterface;

/** Device authorization against the same primary device_sn table as the management API. */
class DeviceSnService
{
    private const UUID_PATTERN = '/^[a-z0-9][a-z0-9._:-]{0,254}$/D';
    private const UNAVAILABLE = 'Device login is temporarily unavailable.';

    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public static function normalizeUuid(string $uuid): string
    {
        $uuid = strtolower(trim($uuid));
        if (preg_match(self::UUID_PATTERN, $uuid) !== 1) {
            throw new RuntimeException('Invalid device UUID.', 400);
        }

        return $uuid;
    }

    /**
     * Binding commits before token issuance, so the same pair can retry after an issuance failure.
     *
     * @return array{user_id: int, device_sn_id: int, nickname: string, username: string}
     */
    public function authenticate(string $sn, string $uuid, bool $activate): array
    {
        $digest = DeviceSnCredential::digest($sn);
        $uuid = self::normalizeUuid($uuid);
        $transaction = null;

        try {
            $transaction = $this->db->beginTransaction();
            $row = $this->queryOne(
                'SELECT `id`, `user_id`, `enabled`, `device_uuid`, `activated_at` '
                . 'FROM `device_sn` WHERE `sn_hash` = :digest FOR UPDATE',
                [':digest' => $digest],
            );
            if ($row === null || (int) ($row['user_id'] ?? 0) <= 0 || (int) ($row['enabled'] ?? 0) !== 1) {
                throw new RuntimeException('SN credentials are invalid or disabled.', 401);
            }

            $userId = (int) $row['user_id'];
            $user = $this->eligibleUser($userId);
            $snId = (int) $row['id'];

            if ($row['activated_at'] !== null || $row['device_uuid'] !== null) {
                // Damaged or partially written bindings never become eligible for a replacement device.
                if ($row['activated_at'] === null || $row['device_uuid'] !== $uuid) {
                    throw new RuntimeException('SN is already bound to a different or unavailable device.', 409);
                }
            } else {
                if (!$activate) {
                    throw new RuntimeException('SN is not activated. Call sn-activate first.', 409);
                }

                $now = gmdate('Y-m-d H:i:s');
                // device_sn.device_uuid remains globally unique, including disabled and revoked SNs.
                $this->execute(
                    'UPDATE `device_sn` SET `device_uuid` = :uuid, `activated_at` = :activated, '
                    . '`updated_at` = :updated WHERE `id` = :id',
                    [':uuid' => $uuid, ':activated' => $now, ':updated' => $now, ':id' => $snId],
                );
                $this->execute(
                    'INSERT INTO `audit_log` (`event_type`, `user_id`, `ip_address`, `action`, '
                    . '`resource`, `context`, `created_at`) VALUES (:event, :user, NULL, :action, :resource, :context, :created)',
                    [
                        ':event' => 'device_sn', ':user' => $userId, ':action' => 'activate',
                        ':resource' => 'device_sn/' . $snId,
                        ':context' => json_encode(['device_uuid' => $uuid], JSON_THROW_ON_ERROR), ':created' => $now,
                    ],
                );
            }

            $transaction->commit();

            return [
                'user_id' => $userId, 'device_sn_id' => $snId,
                'nickname' => (string) ($user['nickname'] ?? ''), 'username' => (string) ($user['username'] ?? ''),
            ];
        } catch (Throwable $exception) {
            $this->rollback($transaction);
            throw $this->safeException($exception);
        }
    }

    /** Temporary SN disablement lets existing access tokens expire; account revocation never does. */
    public function authorizeSession(int $snId, int $userId, bool $requireEnabled = true): void
    {
        if ($snId <= 0 || $userId <= 0) {
            throw new RuntimeException('SN authorization is invalid or disabled.', 401);
        }

        try {
            $row = $this->queryOne(
                'SELECT `sn`.`device_uuid` FROM `device_sn` AS `sn` '
                . 'INNER JOIN `user` AS `u` ON `u`.`id` = `sn`.`user_id` '
                . 'WHERE `sn`.`id` = :sn AND `sn`.`user_id` = :user AND `u`.`status` = 10 '
                . 'AND `sn`.`activated_at` IS NOT NULL '
                . ($requireEnabled ? 'AND `sn`.`enabled` = 1 ' : '')
                . 'AND NOT EXISTS (SELECT 1 FROM `auth_assignment` AS `a` '
                . "WHERE `a`.`user_id` = `u`.`id` AND `a`.`item_name` IN ('root', 'admin', 'manager'))",
                [':sn' => $snId, ':user' => $userId],
            );
            if ($row === null || !is_string($row['device_uuid'] ?? null)
                || preg_match(self::UUID_PATTERN, $row['device_uuid']) !== 1) {
                throw new RuntimeException('SN authorization is invalid or disabled.', 401);
            }
        } catch (Throwable $exception) {
            throw $this->safeException($exception);
        }
    }

    public function recordLogin(int $snId): void
    {
        try {
            $this->execute('UPDATE `device_sn` SET `last_login_at` = :now WHERE `id` = :id', [
                ':now' => gmdate('Y-m-d H:i:s'), ':id' => $snId,
            ]);
        } catch (Throwable) {
            throw new RuntimeException(self::UNAVAILABLE, 503);
        }
    }

    private function eligibleUser(int $userId): array
    {
        $row = $this->queryOne(
            'SELECT `u`.`id`, `u`.`username`, `u`.`nickname` FROM `user` AS `u` '
            . 'WHERE `u`.`id` = :user AND `u`.`status` = 10 '
            . 'AND NOT EXISTS (SELECT 1 FROM `auth_assignment` AS `a` '
            . "WHERE `a`.`user_id` = `u`.`id` AND `a`.`item_name` IN ('root', 'admin', 'manager'))",
            [':user' => $userId],
        );
        if ($row === null) {
            throw new RuntimeException('This account is not eligible for device SN login.', 401);
        }

        return $row;
    }

    private function queryOne(string $sql, array $parameters): ?array
    {
        $command = $this->db->createCommand($sql);
        // Match LoginCodeStore: bind by reference after creation to keep credential digests out of query metadata.
        foreach ($parameters as $name => &$value) {
            $command->bindParam($name, $value);
        }
        unset($value);
        $row = $command->queryOne();

        return is_array($row) ? $row : null;
    }

    private function execute(string $sql, array $parameters): void
    {
        $command = $this->db->createCommand($sql);
        foreach ($parameters as $name => &$value) {
            $command->bindParam($name, $value);
        }
        unset($value);
        $command->execute();
    }

    private function rollback(?TransactionInterface $transaction): void
    {
        try {
            if ($transaction?->isActive()) {
                $transaction->rollBack();
            }
        } catch (Throwable) {
            // Neither a failed rollback nor the original database error may expose bound credentials.
        }
    }

    private function safeException(Throwable $exception): RuntimeException
    {
        if ($exception instanceof DatabaseException || $exception instanceof PDOException) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [1062, 1205, 1213], true)) {
                return new RuntimeException('SN or device was bound concurrently. Retry with the same credentials.', 409);
            }
        } elseif ($exception instanceof RuntimeException && in_array($exception->getMessage(), [
            'SN credentials are invalid or disabled.',
            'SN is already bound to a different or unavailable device.',
            'SN is not activated. Call sn-activate first.',
            'SN authorization is invalid or disabled.',
            'This account is not eligible for device SN login.',
        ], true)) {
            return $exception;
        }

        // Do not chain driver exceptions: their message, trace arguments and SQL may contain secrets.
        return new RuntimeException(self::UNAVAILABLE, 503);
    }
}
