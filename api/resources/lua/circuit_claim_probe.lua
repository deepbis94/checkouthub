-- Atomically transition open -> half_open for a single probe.
-- KEYS[1] = state
-- KEYS[2] = opened_at
-- KEYS[3] = probe
-- ARGV[1] = now_ms
-- ARGV[2] = cooldown_ms
-- ARGV[3] = probe_ttl_ms
-- returns: 2 = closed (allow), 1 = probe claimed (allow), 0 = deny

local state = redis.call('GET', KEYS[1])
if state == false or state == 'closed' then
  return 2
end

if state == 'half_open' then
  return 0
end

local opened = tonumber(redis.call('GET', KEYS[2]) or '0')
local now = tonumber(ARGV[1])
if (now - opened) < tonumber(ARGV[2]) then
  return 0
end

local claimed = redis.call('SET', KEYS[3], '1', 'NX', 'PX', ARGV[3])
if claimed then
  redis.call('SET', KEYS[1], 'half_open')
  return 1
end

return 0
