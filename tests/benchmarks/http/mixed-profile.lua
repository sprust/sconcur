-- Mixed load profile for the dispatch experiment (.ai/plans/dispatch-experiment.md).
--
-- The profile the dispatch proposal is built around: most requests cheap, a few
-- expensive, mixed inside the same keep-alive connections. Two wrk instances
-- would not reproduce that — a connection would carry only one kind — so the
-- mixing has to happen here, in request().
--
--   HEAVY_PATH   the expensive path      (default /cpu/20000)
--   FAST_PATH    the cheap path          (default /)
--   HEAVY_RATIO  share of expensive ones (default 0.10)
--
-- The choice is random rather than every-Nth on purpose: a deterministic stride
-- lines up with a round-robin balancer over N workers and would park every
-- expensive request on the same few of them — an artefact of the generator, not
-- a property of the balancing strategy.
--
-- Usage (through the load harness):
--   HEAVY_PATH=/cpu/20000 WRK_SCRIPT=tests/benchmarks/http/mixed-profile.lua \
--     tests/benchmarks/http/load-stats.sh

local fast_path   = os.getenv("FAST_PATH") or "/"
local heavy_path  = os.getenv("HEAVY_PATH") or "/cpu/20000"
local heavy_ratio = tonumber(os.getenv("HEAVY_RATIO") or "0.10")

local threads = {}

function setup(thread)
    table.insert(threads, thread)
    thread:set("tid", #threads)
end

function init(args)
    fast  = 0
    heavy = 0

    -- Per-thread seed: without it every thread draws the same sequence and the
    -- expensive requests arrive in lockstep across connections.
    math.randomseed(os.time() * 1000 + tid * 7919)
end

function request()
    if math.random() < heavy_ratio then
        heavy = heavy + 1

        return wrk.format("GET", heavy_path)
    end

    fast = fast + 1

    return wrk.format("GET", fast_path)
end

function done(summary, latency, requests)
    local fast_total  = 0
    local heavy_total = 0

    for _, thread in ipairs(threads) do
        fast_total  = fast_total + (thread:get("fast") or 0)
        heavy_total = heavy_total + (thread:get("heavy") or 0)
    end

    local total = fast_total + heavy_total

    if total == 0 then
        return
    end

    io.write(string.format(
        "\n  profile: fast %s = %d (%.1f%%), heavy %s = %d (%.1f%%)\n",
        fast_path, fast_total, 100 * fast_total / total,
        heavy_path, heavy_total, 100 * heavy_total / total))
end
