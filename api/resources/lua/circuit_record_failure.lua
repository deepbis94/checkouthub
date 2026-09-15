-- Increment failures in a sliding window and open the circuit at threshold.
-- KEYS[1] = failures
-- KEYS[2] = state
-- KEYS[3] = opened_at
-- KEYS[4] = probe
-- ARGV[1] = threshold
-- ARGV[2] = window_ms
-- ARGV[3] = now_ms
-- ARGV[4] = force_open (1 when already half-open)
-- returns failure count

local failures = redis.call('INCR', KEYS[1])
if failures == 1 then
  redis.call('PEXPIRE', KEYS[1], ARGV[2])
end

local force = tonumber(ARGV[4])
local threshold = tonumber(ARGV[1])

if force == 1 or failures >= threshold then
  redis.call('SET', KEYS[2], 'open')
  redis.call('SET', KEYS[3], ARGV[3])
  redis.call('DEL', KEYS[4])
end

return failures
