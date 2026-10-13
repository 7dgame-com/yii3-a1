<?php

declare(strict_types=1);

namespace App\Service;

use Predis\Client;

/** Read-only isolation of the reviewed Yii2 AR tree; this grants no AR validity or migration eligibility. */
final class LegacyRefreshNamespaceInspector
{
    // YiiRedisAR insert() skips null attributes. PHP's RefreshToken::attributes()
    // supplies this entire allow-list; A1's model supplies only its first three.
    private const AR_FIELDS = ['id', 'user_id', 'key', 'session_id', 'user_agent', 'ip',
        'created_at', 'expires_at', 'revoked_at', 'auth_method', 'device_sn_id'];

    private const CAPTURE = <<<'LUA'
local key = ARGV[1]
local kind = redis.call('TYPE', key).ok
local ttl = tostring(redis.call('PTTL', key))
if kind == 'none' then return {'none', ttl} end
if kind == 'string' then
    if redis.call('STRLEN', key) > 16384 then return {'oversized', ttl} end
    return {'string', ttl, redis.call('GET', key)}
end
if kind == 'hash' then
    if redis.call('HLEN', key) > 11 then return {'oversized', ttl} end
    local fields = redis.call('HGETALL', key)
    for i = 1, #fields, 2 do
        if #fields[i] > 64 or #fields[i + 1] > 16384 then return {'oversized', ttl} end
    end
    local answer = {'hash', ttl}
    for _, value in ipairs(fields) do answer[#answer + 1] = value end
    return answer
end
if kind == 'list' then
    if redis.call('LLEN', key) > 100000 then return {'oversized', ttl} end
    local values = redis.call('LRANGE', key, 0, 99999)
    local answer = {'list', ttl}
    for _, value in ipairs(values) do
        if #value > 19 then return {'oversized', ttl} end
        answer[#answer + 1] = value
    end
    return answer
end
return {kind, ttl}
LUA;

    private static function reject(): never
    {
        throw new \RuntimeException('Refresh namespace has an unreviewed type or changed during inspection.', 503);
    }

    private static function integer(string $value, bool $zero = false): bool
    {
        return preg_match($zero ? '/^(?:0|[1-9][0-9]{0,18})$/D' : '/^[1-9][0-9]{0,18}$/D', $value) === 1
            && (strlen($value) < 19 || strcmp($value, '9223372036854775807') <= 0);
    }

    private static function capture(Client $redis, string $name): array
    {
        try {
            $result = $redis->eval(self::CAPTURE, 0, $name);
            if (!is_array($result) || count($result) < 2 || !is_string($result[0]) || !is_string($result[1])) self::reject();
            return ['type' => $result[0], 'expiryKind' => $result[1] === '-1' ? 'permanent' : 'expiring', 'data' => array_slice($result, 2)];
        } catch (\Throwable) { self::reject(); }
    }

    private static function sibling(string $name, array $record): string
    {
        $data = $record['data'];
        if ($name === 'refresh_token') {
            if ($record['type'] !== 'list' || array_filter($data, static fn(mixed $id): bool => !is_string($id) || !self::integer($id))) self::reject();
        } elseif ($name === 'refresh_token:s:id') {
            if ($record['type'] !== 'string' || count($data) !== 1 || !self::integer($data[0], true)) self::reject();
        } elseif (preg_match('/^refresh_token:a:([1-9][0-9]{0,18})$/D', $name, $match) === 1 && self::integer($match[1])) {
            if ($record['type'] !== 'hash' || count($data) % 2 !== 0) self::reject();
            $fields = [];
            for ($i = 0; $i < count($data); $i += 2) $fields[$data[$i]] = $data[$i + 1];
            if (array_diff(array_keys($fields), self::AR_FIELDS)
                || !isset($fields['id'], $fields['user_id'], $fields['key']) || $fields['id'] !== $match[1] || !self::integer($fields['user_id'])) self::reject();
            // Optional AR values may be absent, empty or binary. Expiry/SN/
            // credential contents are deliberately not interpreted as validity.
            ksort($fields, SORT_STRING); $data = [];
            foreach ($fields as $field => $value) { $data[] = $field; $data[] = $value; }
        } else self::reject();
        $hash = hash_init('sha256');
        foreach ([$name, $record['type'], $record['expiryKind'], ...$data] as $part) {
            if (!is_string($part)) self::reject();
            hash_update($hash, pack('N', strlen($part)) . $part);
        }
        return hash_final($hash);
    }

    /**
     * Returned raw material is RAM only; callers never log or persist it.
     * @return array{tokens: array<string, array{raw: string, value: string, userId: int}>, siblingInventorySha256: string}
     */
    public static function scan(Client $redis): array
    {
        $cursor = '0'; $seen = []; $tokens = []; $siblings = []; $bytes = 0; $passes = 0;
        $store = new LegacyRefreshReplayStore($redis, new LegacyRefreshReplaySettings());
        do {
            if (++$passes > 10000) self::reject();
            try { [$cursor, $names] = $redis->scan($cursor, ['MATCH' => 'refresh_token:*', 'COUNT' => 100]); }
            catch (\Throwable) { self::reject(); }
            if (!is_string($cursor) || preg_match('/^(?:0|[1-9][0-9]{0,19})$/D', $cursor) !== 1 || !is_array($names)) self::reject();
            foreach ($names as $name) {
                if (!is_string($name) || strlen($name) > 128) self::reject();
                $seen[$name] = true; if (count($seen) > 100000) self::reject();
                $record = self::capture($redis, $name);
                if ($record['type'] === 'none') continue;
                $bytes += strlen($name) + array_sum(array_map('strlen', $record['data']));
                if ($bytes > 8 * 1024 * 1024) self::reject();
                $raw = substr($name, strlen('refresh_token:'));
                if (preg_match('/^(?:dsn_)?[a-f0-9]{64}$/D', $raw) !== 1) { $siblings[$name] = self::sibling($name, $record); continue; }
                if ($record['type'] !== 'string' || count($record['data']) !== 1) self::reject();
                $value = $record['data'][0];
                try {
                    $session = LegacyRefreshReplayStore::decodeSession($value, $raw);
                    $state = $store->inspect($raw);
                    if (isset($state['metadata']) && ($state['metadata']['user_id'] !== $session['user_id']
                        || $state['metadata']['session_value'] !== $value
                        || DeviceSnContext::fromArray($state['metadata']['auth_context']) !== DeviceSnContext::fromArray($session))) self::reject();
                    if ($state['status'] === 'unannotated' && $state['value'] !== $value) self::reject();
                } catch (\Throwable) { self::reject(); }
                $tokens[hash('sha256', $raw)] = ['raw' => $raw, 'value' => $value, 'userId' => $session['user_id']];
            }
        } while ($cursor !== '0');
        $list = self::capture($redis, 'refresh_token');
        if ($list['type'] !== 'none') {
            $bytes += array_sum(array_map('strlen', $list['data'])); if ($bytes > 8 * 1024 * 1024) self::reject();
            $siblings['refresh_token'] = self::sibling('refresh_token', $list);
        }
        ksort($tokens, SORT_STRING); ksort($siblings, SORT_STRING);
        return ['tokens' => $tokens, 'siblingInventorySha256' => hash('sha256', json_encode($siblings, JSON_THROW_ON_ERROR))];
    }
}
