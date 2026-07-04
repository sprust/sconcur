English | [Русский](load-testing.ru.md)

# Load testing: server behaviour under load

This document describes how to hammer the HTTP server with a set of async-I/O features at
once and capture memory and CPU consumption while doing so, and records the results of a
reference run and the conclusions drawn from them.

Tools:
- the `/all` route of the demo server (`tests/servers/http/http-server.php`) — for each
  request it fans out (nested `WaitGroup`) across the backend I/O features concurrently:
  MongoDB (insert + findOne), MySQL (`INSERT` + `SELECT 1`), PostgreSQL
  (`INSERT` + `SELECT 1`). The HTTP client is deliberately excluded: its self-hit into the
  server's own `/` would make every `/all` serve a second request and skew the rps (it is
  covered by its own benches). Connections are created lazily, once per worker (the Go side
  pools them by URI/DSN), so the other demo routes do not pay for them and do not require
  running DBs. The results recorded below were captured with the older route composition:
  the SQL part without `INSERT` (only `SELECT 1`) and with `Sleeper::usleep(1000)`, which
  has since been removed;
- the `tests/benchmarks/http-load-stats.sh` script (`make bench-http-load-stats`) — brings
  up a pool of servers (`SO_REUSEPORT`, one process per core), runs `wrk` against `/all`,
  and during the run samples `docker stats` (CPU%/MEM for the server and DB containers)
  plus the aggregate worker RSS from `/proc/<pid>/status` (leak detection);
- the `tests/benchmarks/rr-load-stats.sh` script (`make bench-rr-load-stats`) — the same
  harness, but the server is a RoadRunner reference on native drivers
  (`tests/servers/roadrunner/`): a single `rr serve` with its own worker pool, the same
  pinning and sampling rules, its numbers are directly comparable with `http-load-stats.sh`.

## How to run

You need `wrk` on the host and the services up (`make up`: `php`, `mongodb`, `mysql`,
`postgres`). Run from the host (that is where `wrk` and `docker` live), the servers run in
the container (that is where the extension is built).

```sh
make bench-http-load-stats
# tuning via env:
SERVERS=12 WRK_THREADS=4 CONNECTIONS=256 DURATION=20 SAMPLE_INTERVAL=2 \
    tests/benchmarks/http-load-stats.sh

# baseline run against the empty "/" route (the framework's own ceiling, no I/O):
make bench-http-load-stats-empty

# soak mode: a long sustained run with an RSS-over-time trend and slope
# (MiB/min) — for detecting a SLOW leak. Defaults: 10 minutes, 15 s sample.
make bench-http-load-soak               # 10 minutes by default
DURATION=3600 make bench-http-load-soak # hour-long soak
```

`MODE=soak` additionally prints a `trend (elapsed → RSS)` table and a least-squares slope
in MiB/min with a verdict: `stable` / `growth — possible leak` /
`declining (GC/memory return)`.

The methodology is the same as [`http-throughput.sh`](../tests/benchmarks/http-throughput.sh):
the servers and the load generator are pinned to non-overlapping cores (`taskset`), and
`wrk` hits the container's bridge IP directly, bypassing docker-proxy (NAT caps throughput).

## Baseline run (empty route)

Environment: an Intel i7-13620H laptop (6 P-cores/12 HT + 4 E-cores = 16 threads), services
in Docker, 12 servers / 4 wrk cores, 256 connections, 20 s. `wrk` hits `/` (response `ok`,
no I/O and no noticeable CPU) — this is the ceiling of the HTTP-server + framework pairing,
the floor on top of which the feature tax of `/all` is laid. Launch:
`make bench-http-load-stats-empty`.

| Metric | `/` (empty) | `/all` (all features) |
|---|---|---|
| Throughput | **≈25 500 req/sec** | 2 910 req/sec |
| Latency | p50 10 ms · p90 14 ms · p99 20 ms | p50 81 · p90 119 · p99 196 ms |
| Servers CPU (`php`) | avg ~1180 % | avg 976 % |
| Worker RSS (sum of 12) | ~552 MiB (flat) | ~610 MiB |

(Two runs gave 25.4k and 25.6k req/sec, 0 errors — all responses `200`.) The ~9× gap is
the price of the per-feature fan-out: on `/all` each request does a 4-way concurrent fan-out
across the PHP↔Go boundary (msgpack + fiber spawn/scheduling), and throughput hits exactly
that overhead, not the DB. The empty route has none of it — what is left is the server's
pure ceiling (CPU is even higher there: many times more requests per second). The detailed
`/all` run is [below](#reference-run).

## Reference run

Same machine and parameters (12 servers / 4 wrk cores, 256 connections, 20 s), but `wrk`
hits `/all`. The DB queries are trivial (`SELECT 1`, `findOne`) — this measures the ceiling
of the framework itself, not the DB. `/all` runs all async-I/O features at once — `Sleeper`,
MongoDB (insert + findOne), MySQL (`SELECT 1`), PostgreSQL (`SELECT 1`) — i.e. the 2 910
req/sec below is the full 4-way fan-out on each request, not an empty route (the bare server
ceiling is in the [baseline run](#baseline-run-empty-route) above, ≈25.5k req/sec).

| Metric | Value |
|---|---|
| Throughput | **2 910 req/sec** (0 errors — all 4 features `ok`) |
| Latency | p50 81 ms · p90 119 ms · p99 196 ms |
| **Worker RSS (sum of 12)** | first **608.9** / peak **610.6** / last **610.6 MiB** → drift **+1.6 MiB** |
| Servers CPU (`php`) | avg **976 %** / peak 1053 % (≈ 10 of 12 cores) |
| Backends CPU | PostgreSQL 141 %/234peak · MongoDB 112 %/141peak · MySQL 27 % |
| MEM (containers) | php 326 · mongo 472 · mysql 143 · pg 106 MiB |

(The RSS drift over 20 s is warm-up noise; the authoritative leak verdict comes from the
soak below.)

## Soak run (10 minutes)

The same environment, a sustained load of 128 connections for 600 s (slow-leak detection).
Over the run — 2 058 645 requests (~8.2M feature operations).

| Metric | Value |
|---|---|
| Throughput | **3 431 req/sec** · p50 37 ms · p99 61 ms |
| Worker RSS (trend over 10 min) | first **606.6** / peak **606.6** / last **603.1 MiB** |
| **Drift / slope** | **−3.5 MiB** / **−0.08 MiB/min** → verdict **stable** |
| Servers CPU (`php`) | avg 1120 % / peak 1179 % |

RSS stayed flat (602–607 MiB) for the whole distance — the slope of −0.08 MiB/min is within
noise (the short 20 s run above gave a false "growth" due to warm-up — the soak removes it).
There is no slow leak. The `mongodb` container's MEM meanwhile grew to ~596 MiB — because of
the unbounded `insert`s of the `/all` route into the `load_all` collection; the SConcur
worker RSS did not budge. The data is accumulated by the DB, while the SConcur side is stable
(the `load_all` collection can be dropped after the runs).

## Conclusions

1. **Memory is stable — the main result.** ~50 MiB RSS per worker, and a 10-minute soak
   (2.06M requests) held RSS flat with a slope of −0.08 MiB/min (= noise) — there is no slow
   leak. For a long-lived server this is the key signal: the Go runtime + PHP fibers +
   connection pools + PHP↔Go boundary pairing accumulates nothing. Consistent with
   `MemLeakTest`.

2. Robustness. Saturation with a 4-way concurrent fan-out per request → 0 errors,
   p99 < 200 ms. The cooperative scheduler and nested `WaitGroup`s hold up under high
   concurrency.

3. The bottleneck is SConcur's own CPU, not the DB. ~10 cores on the servers versus ~1–2 on
   each DB. The cost of a request is the framework overhead (msgpack, fiber
   spawn/scheduling, 4× PHP↔Go crossing), not the `SELECT 1`/`findOne` themselves. On cheap
   backend operations the framework tax dominates (hence ~2.9k rps), while under real load
   (slow queries, network latency) this tax is amortized and SConcur's strong side is
   revealed — fan-out I/O concurrency.

## About "CPU through the roof"

High CPU under load is saturation, not an anomaly. `wrk` drives the server to its maximum,
so ~1000 % (≈ all 12 cores at 100 %) means "we found the ceiling", not a bug or a leak. The
meaningful metric is not the CPU % itself but throughput and CPU per request: under
saturation CPU % is always at the ceiling, the differences show in throughput. In production
the server runs below saturation, and CPU is proportional to load rather than pinned at
100 %.

`/all` is heavy by design: a 4-way fan-out, each feature a PHP↔Go round-trip (msgpack +
fiber spawn/scheduling). On cheap backend operations this is precisely the ceiling
(~2.9k rps), under real (slow) load it is amortized. The HTTP client is deliberately
excluded from `/all` (see the intro): its self-hit would double the served load and skew the
rps (previously it made `/all` show only ~1.7k rps).

## Caveats

- Synthetic, on a laptop. A consumer CPU (P+E cores, HyperThreading, all-core throttling)
  understates core scaling; on a server CPU the numbers would be higher and more linear.
- Trivial queries understate the point of SConcur (concurrent I/O). For fair positioning the
  I/O-bound scenario is the separate `bench-http-server-io` benches.
- Distance. The 10-minute soak (2.06M requests) already confirmed the absence of a slow leak
  (slope −0.08 MiB/min). For absolute certainty in production — a multi-hour run:
  `make bench-http-load-soak` (`MODE=soak`), e.g. `DURATION=3600` and longer.

## WebSocket server under load

The WebSocket server has the same load + resources pairing, but `wrk` does not fit here (it
is HTTP-only), so the load generator is a custom one: `ext/cmd/ws-load` (Go, on
`coder/websocket`), the WS analogue of `wrk`. It holds N persistent connections, runs
back-to-back round-trips over them and prints throughput and p50/p90/p99 latency.

Tools:
- the `all` command of the demo server (`tests/servers/ws/ws-server.php`) — for each message
  it fans out (nested `WaitGroup`) across the backend I/O features concurrently: `Sleeper`,
  MongoDB (insert + findOne), MySQL (`INSERT` + `SELECT 1`), PostgreSQL
  (`INSERT` + `SELECT 1`) — like HTTP `/all`, but with `Sleeper` in the mix (it was removed
  from the HTTP route). DB connections are created lazily, once per worker;
- the `tests/benchmarks/ws-load-stats.sh` script (`make bench-ws-load-stats`) — brings up a
  pool of ws-servers (`SO_REUSEPORT`, one process per core) in the `php` container, runs
  `ext/cmd/ws-load` against it and during the run samples `docker stats` plus the aggregate
  worker RSS (leak detection), with the same logic as `http-load-stats.sh`.

The difference from the HTTP variant: both the pool and the generator live in the `php`
container (WebSocket needs no host utilities), are pinned to non-overlapping cores
(`taskset`), and the generator hits the pool over loopback (`127.0.0.1`, no NAT).

```sh
make bench-ws-load-stats
# tuning via env:
SERVERS=12 CONNECTIONS=256 DURATION=20 SAMPLE_INTERVAL=2 tests/benchmarks/ws-load-stats.sh

# baseline run against "ping" (the framework's own ceiling, no I/O):
make bench-ws-load-stats-empty

# soak mode (a long sustained run with an RSS trend and MiB/min slope):
make bench-ws-load-soak               # 10 minutes by default
DURATION=3600 make bench-ws-load-soak # hour-long soak
```

The metrics read the same way as for HTTP: `ping` against `all` shows the price of the
feature fan-out (a PHP↔Go round-trip per feature), and the RSS slope in soak mode is the
authoritative leak verdict (a short run gives warm-up noise). The WS-server and HTTP-server
sides are built the same way here, so the conclusions carry over too.

See also: [HTTP server](http-server.md), [WebSocket server](websocket-server.md),
[Worker master](worker-master.md),
[SO_REUSEPORT throughput](../tests/benchmarks/http-throughput.sh).
</content>
</invoke>
