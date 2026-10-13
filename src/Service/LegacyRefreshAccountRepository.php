<?php

declare(strict_types=1);

namespace App\Service;

use Yiisoft\Db\Connection\ConnectionInterface;

/** The row lock serializes Redis admission with every SQL account update. */
class LegacyRefreshAccountRepository
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public static function fingerprint(array $user): string
    {
        return hash('sha256', json_encode([
            'yii-ar-account-v1', $user['password_hash'] ?? null, $user['auth_key'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS));
    }

    public static function accountEpoch(array $user): string
    {
        $epoch = $user['_identity_account_epoch'] ?? null;
        if (!is_string($epoch) || preg_match('/^[1-9][0-9]{0,19}$/D', $epoch) !== 1
            || (strlen($epoch) === 20 && strcmp($epoch, '18446744073709551615') > 0)) {
            throw new \RuntimeException('Account security version is unavailable.', 503);
        }
        return $epoch;
    }

    public function withLockedUser(int $userId, callable $operation, bool $requireActive = true): mixed
    {
        $transaction = $this->db->beginTransaction();
        try {
            $command = $this->db->createCommand('SELECT * FROM `user` WHERE `id` = :id FOR UPDATE');
            $command->bindValue(':id', $userId);
            $row = $command->queryOne();
            $user = is_array($row) ? $row : null;
            if ($requireActive && ($user === null || (int) ($user['status'] ?? 0) !== 10
                || ($user['deleted_at'] ?? null) !== null)) {
                throw new \RuntimeException('Refresh token is invalid.', 401);
            }
            try {
                $ledger = $this->db->createCommand('SELECT CAST(`epoch` AS CHAR) AS `epoch` FROM `identity_legacy_security_versions` WHERE `user_id` = :id FOR UPDATE');
                $ledger->bindValue(':id', $userId);
                $version = $ledger->queryOne();
                $epoch = self::accountEpoch(['_identity_account_epoch' => is_array($version) ? $version['epoch'] ?? null : null]);
            } catch (\Throwable) {
                throw new \RuntimeException('Account security version is unavailable.', 503);
            }
            if ($user !== null) $user['_identity_account_epoch'] = $epoch;
            $result = $operation($user);
            $transaction->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($transaction->isActive()) $transaction->rollBack();
            throw $error;
        }
    }
}
