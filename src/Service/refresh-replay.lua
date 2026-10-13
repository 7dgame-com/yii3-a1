-- Standalone Redis only. All execution paths validate before changing state.
local op, p = ARGV[1], cjson.decode(ARGV[2])
local prefix = 'y1:refresh-replay:v1:'
local clock = redis.call('TIME')
local now = tonumber(clock[1]) * 1000 + math.floor(tonumber(clock[2]) / 1000)
local function result(status, data)
    data = data or {}; data.status = status; return cjson.encode(data)
end
local function decode(value)
    if not value then return nil end
    local ok, data = pcall(cjson.decode, value)
    if not ok or type(data) ~= 'table' then error('Invalid replay metadata') end
    return data
end
local function epoch(user)
    local value = tonumber(redis.call('GET', prefix .. 'user:' .. user .. ':epoch') or '0')
    if not value or value < 0 or value > 9007199254740990 or value ~= math.floor(value) then error('Invalid revocation epoch') end
    return value
end
local function metaKey(hash) return prefix .. 'credential:' .. hash end
local function familyKey(id) return prefix .. 'family:' .. id end
local function replayKey(hash) return prefix .. 'response:' .. hash end
local function integer(value, minimum)
    return type(value) == 'number' and value >= minimum and value <= 9007199254740991 and value == math.floor(value)
end
local function digest(value, length)
    return type(value) == 'string' and #value == length and string.match(value, '^[a-f0-9]+$') ~= nil
end
local function fields(value, allowed, count)
    local seen = 0
    for key, _ in pairs(value) do if not allowed[key] then error('Unknown replay metadata field') end; seen = seen + 1 end
    if seen ~= count then error('Missing replay metadata field') end
end
local function accountEpoch(value)
    return type(value) == 'string' and #value >= 1 and #value <= 20 and string.match(value, '^[1-9][0-9]*$') ~= nil
        and (#value < 20 or value <= '18446744073709551615')
end
local function context(value)
    if type(value) ~= 'table' then error('Invalid replay context') end
    local count = 0; for _, _ in pairs(value) do count = count + 1 end
    if count == 0 then return end
    if count ~= 2 or value.auth_method ~= 'device_sn' or not integer(value.device_sn_id, 1) then error('Invalid replay context') end
end
local function metadata(value, hash)
    fields(value, {version=true,credential_hash=true,user_id=true,family_id=true,user_epoch=true,account_fingerprint=true,account_epoch=true,
        session_value=true,auth_context=true,issued_at_ms=true,expires_at_ms=true,state=true,successor_hash=true,rotated_at_ms=true,revoked_at_ms=true}, 15)
    if value.version ~= 1 or value.credential_hash ~= hash or not digest(hash, 64) or not integer(value.user_id, 1)
        or not digest(value.family_id, 32) or not integer(value.user_epoch, 0) or not digest(value.account_fingerprint, 64) or not accountEpoch(value.account_epoch)
        or type(value.session_value) ~= 'string' or #value.session_value > 1024 or not integer(value.issued_at_ms, 1)
        or not integer(value.expires_at_ms, 1) or value.expires_at_ms - value.issued_at_ms ~= 2592000000 then error('Invalid replay metadata') end
    context(value.auth_context)
    if value.state == 'active' then
        if value.successor_hash ~= cjson.null or value.rotated_at_ms ~= cjson.null or value.revoked_at_ms ~= cjson.null then error('Invalid active metadata') end
    elseif value.state == 'rotated' then
        if not digest(value.successor_hash, 64) or not integer(value.rotated_at_ms, 1) or value.revoked_at_ms ~= cjson.null then error('Invalid rotation metadata') end
    elseif value.state == 'revoked' then
        if (value.successor_hash ~= cjson.null and not digest(value.successor_hash, 64))
            or (value.rotated_at_ms ~= cjson.null and not integer(value.rotated_at_ms, 1)) or not integer(value.revoked_at_ms, 1) then error('Invalid revoked metadata') end
    else error('Invalid replay state') end
end
local function family(value)
    fields(value, {version=true,user_id=true,user_epoch=true,account_fingerprint=true,account_epoch=true,current_hash=true,current_key=true,
        expires_at_ms=true,revoked_at_ms=true}, 9)
    if value.version ~= 1 or not integer(value.user_id, 1) or not integer(value.user_epoch, 0)
        or not digest(value.account_fingerprint, 64) or not accountEpoch(value.account_epoch) or not digest(value.current_hash, 64)
        or type(value.current_key) ~= 'string' or not integer(value.expires_at_ms, 1)
        or (value.revoked_at_ms ~= cjson.null and not integer(value.revoked_at_ms, 1)) then error('Invalid replay family') end
    local raw = string.sub(value.current_key, 15)
    if string.sub(value.current_key, 1, 14) ~= 'refresh_token:' or not
        (digest(raw, 64) or (string.sub(raw, 1, 4) == 'dsn_' and digest(string.sub(raw, 5), 64))) then error('Invalid family credential type') end
end
local function response(value)
    fields(value, {version=true,predecessor_hash=true,successor_hash=true,user_id=true,family_id=true,user_epoch=true,
        account_fingerprint=true,account_epoch=true,request_hash=true,created_at_ms=true,expires_at_ms=true,encrypted_response=true}, 12)
    if value.version ~= 1 or not digest(value.predecessor_hash, 64) or not digest(value.successor_hash, 64)
        or not integer(value.user_id, 1) or not digest(value.family_id, 32) or not integer(value.user_epoch, 0)
        or not digest(value.account_fingerprint, 64) or not accountEpoch(value.account_epoch) or (value.request_hash ~= cjson.null and not digest(value.request_hash, 64))
        or not integer(value.created_at_ms, 1) or not integer(value.expires_at_ms, 1) or value.expires_at_ms <= value.created_at_ms
        or value.expires_at_ms > value.created_at_ms + 30000 or type(value.encrypted_response) ~= 'string'
        or #value.encrypted_response < 39 or #value.encrypted_response > 32768 then error('Invalid replay response') end
end
local function storeUntil(key, value, deadline)
    if deadline <= now then error('Invalid metadata expiry') end
    redis.call('SET', key, cjson.encode(value), 'PX', math.floor(deadline - now))
end
local function storeCredential(hash, value)
    -- Retain type/tombstone evidence for the final Native import. This never
    -- extends the real refresh key; expiry is enforced from expires_at_ms.
    redis.call('SET', metaKey(hash), cjson.encode(value))
end
local function read(raw, hash, request)
    local value = redis.call('GET', 'refresh_token:' .. raw)
    local encoded = redis.call('GET', metaKey(hash))
    if not encoded then
        return {status = value and 'unannotated' or 'miss', value = value or cjson.null}
    end
    local meta = decode(encoded)
    metadata(meta, hash)
    local currentFamily = decode(redis.call('GET', familyKey(meta.family_id)))
    if currentFamily then family(currentFamily) end
    if not currentFamily or currentFamily.revoked_at_ms ~= cjson.null or meta.state == 'revoked'
        or meta.expires_at_ms <= now or meta.user_epoch ~= epoch(meta.user_id)
        or currentFamily.user_epoch ~= meta.user_epoch or currentFamily.user_id ~= meta.user_id
        or currentFamily.account_fingerprint ~= meta.account_fingerprint or currentFamily.account_epoch ~= meta.account_epoch then
        return {status = 'invalid', metadata = meta, encoded = encoded}
    end
    if meta.state == 'active' and value and value == meta.session_value and currentFamily.current_hash == hash
        and currentFamily.current_key == 'refresh_token:' .. raw then
        return {status = 'active', metadata = meta, encoded = encoded, value = value}
    end
    if meta.state ~= 'rotated' or value then return {status = 'invalid', metadata = meta, encoded = encoded} end
    local replay = decode(redis.call('GET', replayKey(hash)))
    if replay then response(replay) end
    if not replay or replay.expires_at_ms <= now or currentFamily.current_hash ~= replay.successor_hash
        or redis.call('GET', currentFamily.current_key) ~= meta.session_value or replay.user_epoch ~= epoch(meta.user_id)
        or replay.predecessor_hash ~= hash or replay.successor_hash ~= meta.successor_hash or replay.user_id ~= meta.user_id
        or replay.family_id ~= meta.family_id or replay.account_fingerprint ~= meta.account_fingerprint
        or replay.account_epoch ~= meta.account_epoch then
        return {status = 'invalid', metadata = meta, encoded = encoded}
    end
    local same = request ~= cjson.null and request ~= '' and replay.request_hash == request
    if not same and now - replay.created_at_ms > 5000 then
        return {status = 'invalid', metadata = meta, encoded = encoded}
    end
    return {status = 'replay', metadata = meta, encoded = encoded, replay = replay}
end
if op == 'inspect' then return cjson.encode(read(p.raw, p.hash, p.request_hash)) end
if op == 'epoch' then return result('ok', {epoch = epoch(p.user_id)}) end
if op == 'advance-epoch' then
    local current = epoch(p.user_id)
    redis.call('SET', prefix .. 'user:' .. p.user_id .. ':epoch', tostring(current + 1))
    redis.call('SET', prefix .. 'user:' .. p.user_id .. ':revoked-at-ms', tostring(now))
    return result('ok', {epoch = current + 1, revoked_at_ms = now})
end
if op == 'create' or op == 'backfill' then
    if not integer(p.user_id, 1) or not integer(p.user_epoch, 0) or not accountEpoch(p.account_epoch)
        or not digest(p.hash, 64) or not digest(p.family_id, 32) or not digest(p.account_fingerprint, 64)
        or type(p.raw) ~= 'string' or type(p.value) ~= 'string' then error('Invalid creation metadata') end
    if epoch(p.user_id) ~= p.user_epoch then return result('conflict') end
    local key, mkey = 'refresh_token:' .. p.raw, metaKey(p.hash)
    local value, existing = redis.call('GET', key), redis.call('GET', mkey)
    local expiry, issued = now + 2592000000, now
    if op == 'backfill' then
        local ttl = redis.call('PTTL', key)
        if existing or not value or value ~= p.value or ttl <= 0 or ttl > 2592000000
            or math.abs(now + ttl - p.expires_at_ms) > 2 then return result('conflict') end
        expiry = math.min(now + ttl, p.expires_at_ms)
        issued = expiry - 2592000000
    elseif value or existing then return result('conflict') end
    if redis.call('EXISTS', familyKey(p.family_id)) ~= 0 then return result('conflict') end
    local meta = {version = 1, credential_hash = p.hash, user_id = p.user_id, family_id = p.family_id,
        user_epoch = p.user_epoch, account_fingerprint = p.account_fingerprint, account_epoch = p.account_epoch,
        session_value = p.value, auth_context = p.auth_context, issued_at_ms = issued, expires_at_ms = expiry,
        state = 'active', successor_hash = cjson.null, rotated_at_ms = cjson.null, revoked_at_ms = cjson.null}
    local familyRecord = {version = 1, user_id = p.user_id, user_epoch = p.user_epoch,
        account_fingerprint = p.account_fingerprint, account_epoch = p.account_epoch, current_hash = p.hash, current_key = key,
        expires_at_ms = expiry, revoked_at_ms = cjson.null}
    metadata(meta, p.hash); family(familyRecord)
    if op == 'create' then redis.call('SETEX', key, 2592000, p.value) end
    storeCredential(p.hash, meta); storeUntil(familyKey(p.family_id), familyRecord, expiry)
    return result('ok', {metadata = meta})
end
if op == 'rotate' then
    local current = read(p.raw, p.hash, p.request_hash)
    if current.status == 'replay' then return cjson.encode(current) end
    if current.status ~= 'active' or current.encoded ~= p.expected_metadata
        or current.metadata.account_fingerprint ~= p.account_fingerprint or current.metadata.account_epoch ~= p.account_epoch then return result('invalid') end
    local old = current.metadata
    if redis.call('EXISTS', 'refresh_token:' .. p.successor) ~= 0
        or redis.call('EXISTS', metaKey(p.successor_hash)) ~= 0 then return result('conflict') end
    local expiry = now + 2592000000
    local next = {version = 1, credential_hash = p.successor_hash, user_id = old.user_id, family_id = old.family_id,
        user_epoch = old.user_epoch, account_fingerprint = old.account_fingerprint, account_epoch = old.account_epoch,
        session_value = old.session_value, auth_context = old.auth_context, issued_at_ms = now, expires_at_ms = expiry,
        state = 'active', successor_hash = cjson.null, rotated_at_ms = cjson.null, revoked_at_ms = cjson.null}
    local familyRecord = {version = 1, user_id = old.user_id, user_epoch = old.user_epoch,
        account_fingerprint = old.account_fingerprint, account_epoch = old.account_epoch, current_hash = p.successor_hash,
        current_key = 'refresh_token:' .. p.successor, expires_at_ms = expiry, revoked_at_ms = cjson.null}
    local replay = {version = 1, predecessor_hash = p.hash, successor_hash = p.successor_hash,
        user_id = old.user_id, family_id = old.family_id, user_epoch = old.user_epoch,
        account_fingerprint = old.account_fingerprint, account_epoch = old.account_epoch, request_hash = p.request_hash,
        created_at_ms = now, expires_at_ms = math.min(now + 30000, old.expires_at_ms), encrypted_response = p.encrypted_response}
    old.state = 'rotated'; old.successor_hash = p.successor_hash; old.rotated_at_ms = now
    metadata(next, p.successor_hash); metadata(old, p.hash); family(familyRecord); response(replay)
    -- All known type/collision/state failures are checked before writes.
    redis.call('SETEX', 'refresh_token:' .. p.successor, 2592000, old.session_value)
    storeCredential(p.successor_hash, next)
    storeUntil(familyKey(old.family_id), familyRecord, expiry)
    storeCredential(p.hash, old)
    storeUntil(replayKey(p.hash), replay, replay.expires_at_ms)
    redis.call('DEL', 'refresh_token:' .. p.raw)
    return result('replay', {metadata = old, replay = replay})
end
if op == 'revoke' then
    local encoded = redis.call('GET', metaKey(p.hash))
    if not encoded then redis.call('DEL', 'refresh_token:' .. p.raw); return result('ok') end
    local meta = decode(encoded)
    metadata(meta, p.hash)
    local currentFamily = decode(redis.call('GET', familyKey(meta.family_id)))
    if currentFamily then
        family(currentFamily)
        if currentFamily.user_id ~= meta.user_id or currentFamily.account_fingerprint ~= meta.account_fingerprint or currentFamily.account_epoch ~= meta.account_epoch then error('Conflicting replay family') end
        currentFamily.revoked_at_ms = currentFamily.revoked_at_ms ~= cjson.null and currentFamily.revoked_at_ms or now
        if currentFamily.expires_at_ms > now then storeUntil(familyKey(meta.family_id), currentFamily, currentFamily.expires_at_ms) end
        redis.call('DEL', currentFamily.current_key)
    end
    meta.state = 'revoked'; meta.revoked_at_ms = meta.revoked_at_ms ~= cjson.null and meta.revoked_at_ms or now
    storeCredential(p.hash, meta)
    redis.call('DEL', 'refresh_token:' .. p.raw, replayKey(p.hash))
    return result('ok')
end
if op == 'capture' then
    local value = redis.call('GET', 'refresh_token:' .. p.raw)
    local ttl = redis.call('PTTL', 'refresh_token:' .. p.raw)
    return result('ok', {value = value or cjson.null, ttl_ms = ttl,
        observed_at_ms = now, expires_at_ms = now + ttl, metadata = redis.call('GET', metaKey(p.hash)) or cjson.null})
end
error('Unknown replay operation')
