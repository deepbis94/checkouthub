-- Atomic token bucket.
-- KEYS[1] = hash key
-- ARGV[1] = capacity
-- ARGV[2] = refill_per_ms
-- ARGV[3] = now_ms
-- ARGV[4] = cost
-- ARGV[5] = ttl_ms
-- returns {allowed (1|0), remaining}

local key = KEYS[1]
local capacity = tonumber(ARGV[1])
local refill_per_ms = tonumber(ARGV[2])
local now = tonumber(ARGV[3])
local cost = tonumber(ARGV[4])
local ttl = tonumber(ARGV[5])

local data = redis.call('HMGET', key, 'tokens', 'ts')
local tokens = tonumber(data[1])
local ts = tonumber(data[2])

if tokens == nil then
  tokens = capacity
  ts = now
end

local delta = now - ts
if delta < 0 then
  delta = 0
end

tokens = math.min(capacity, tokens + (delta * refill_per_ms))
ts = now

local allowed = 0
if tokens >= cost then
  tokens = tokens - cost
  allowed = 1
end

redis.call('HSET', key, 'tokens', tokens, 'ts', ts)
redis.call('PEXPIRE', key, ttl)

return {allowed, tokens}
