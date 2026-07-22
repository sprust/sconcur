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
  running DBs;
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
| Throughput | **≈67 100 req/sec** | 2 680 req/sec |
| Latency | p50 3.7 ms · p90 6.3 ms · p99 8.8 ms | p50 87 · p90 165 · p99 267 ms |
| Servers CPU (`php`) | avg ~1207 % | avg 744 % |
| Worker RSS (sum of 12) | ~573 MiB (flat) | ~628 MiB |

(Three runs held ~67k req/sec, 0 errors — all responses `200`.) The ~25× gap is
the price of the per-feature fan-out: on `/all` each request does a 3-way concurrent fan-out
across the PHP↔Go boundary (msgpack + fiber spawn/scheduling) plus the fsync of 3 disk
writes, and throughput hits exactly that, not the cheap DB read. The empty route has none of
it — what is left is the server's pure ceiling (CPU-bound at ~1200 %). The detailed
`/all` run is [below](#reference-run).

## Reference run

Same machine and parameters (12 servers / 4 wrk cores, 256 connections, 20 s), but `wrk`
hits `/all`. `/all` runs the backend I/O features at once — MongoDB (insert + findOne),
MySQL (`INSERT` + `SELECT 1`), PostgreSQL (`INSERT` + `SELECT 1`) — so each request is a
3-way fan-out over 6 DB operations, 3 of them disk writes (each an fsync). The 2 680
req/sec below is that full fan-out on every request, not an empty route (the bare server
ceiling is in the [baseline run](#baseline-run-empty-route) above, ≈67k req/sec).

| Metric | Value |
|---|---|
| Throughput | **2 680 req/sec** (0 errors — all 3 features `ok`) |
| Latency | p50 87 ms · p90 165 ms · p99 267 ms |
| **Worker RSS (sum of 12)** | first **627.7** / peak **629.1** / last **629.1 MiB** → drift **+0.8 MiB** |
| Servers CPU (`php`) | avg **744 %** / peak 765 % (≈ 7–8 of 12 cores) |
| Backends CPU | MongoDB 112 %/138peak · MySQL 71 %/76peak · PostgreSQL 53 %/68peak |
| MEM (containers) | php 287 · mongo 338 · mysql 533 · pg 145 MiB |

(The RSS drift over 20 s is warm-up noise; the authoritative leak verdict comes from the
soak below.)

## Soak run (10 minutes)

The same environment, a sustained load of 128 connections for 600 s (slow-leak detection).
Over the run — 1 738 813 requests (~5.2M feature operations).

| Metric | Value |
|---|---|
| Throughput | **2 897 req/sec** · p50 40 ms · p90 72 ms · p99 127 ms |
| Worker RSS (trend over 10 min) | first **618.5** / peak **621.8** / last **620.6 MiB** |
| **Drift / slope** | **+2.1 MiB** / **+0.11 MiB/min** → verdict **stable** |
| Servers CPU (`php`) | avg 741 % / peak 768 % |

RSS stayed flat (618–622 MiB) for the whole distance — the slope of +0.11 MiB/min is within
noise. There is no slow leak. The `mongodb` container's MEM meanwhile grew to ~372 MiB — because of
the unbounded `insert`s of the `/all` route into the `load_all` collection; the SConcur
worker RSS did not budge. The data is accumulated by the DB, while the SConcur side is stable
(the `load_all` collection can be dropped after the runs).

## Conclusions

1. **Memory is stable — the main result.** ~50 MiB RSS per worker, and a 10-minute soak
   (1.74M requests) held RSS flat with a slope of +0.11 MiB/min (= noise) — there is no slow
   leak. For a long-lived server this is the key signal: the Go runtime + PHP fibers +
   connection pools + PHP↔Go boundary pairing accumulates nothing. Consistent with
   `MemLeakTest`.

2. Robustness. Saturation with a 3-way concurrent fan-out per request → 0 errors,
   p99 ≈ 130 ms under sustained soak load. The cooperative scheduler and nested `WaitGroup`s
   hold up under high concurrency.

3. On disk backends the bottleneck is fsync, not CPU. The servers draw ~7–8 of 12 cores
   (not saturated) versus ~0.5–1.5 on each DB — the ~2.7k rps ceiling is set by the 3 disk
   commits per request (overlapped by the fan-out) plus the framework overhead (msgpack,
   fiber spawn/scheduling, 3× PHP↔Go crossing), not by the `SELECT 1`/`findOne` reads. On
   cheap in-cache operations the framework tax would dominate instead, while under real load
   (slow queries, network latency) both are amortized and SConcur's strong side is
   revealed — fan-out I/O concurrency.

## About "CPU through the roof"

High CPU under load is saturation, not an anomaly. `wrk` drives the server to its maximum:
on the empty route ~1200 % (≈ all 12 cores at 100 %) means "we found the CPU ceiling", not a
bug or a leak. The meaningful metric is not the CPU % itself but throughput and CPU per
request: under saturation CPU % is at the ceiling, the differences show in throughput. In
production the server runs below saturation, and CPU is proportional to load rather than
pinned at 100 %.

`/all` is heavy by design: a 3-way fan-out, each feature a PHP↔Go round-trip (msgpack +
fiber spawn/scheduling) plus a disk write. On disk backends the ceiling is fsync-bound
(~2.7k rps at ~740 % CPU, not CPU-saturated); under an in-cache or slow-network profile the
picture shifts. The HTTP client is deliberately excluded from `/all` (see the intro): its
self-hit would double the served load and skew the rps (previously it made `/all` show only
~1.7k rps).

## Caveats

- Synthetic, on a laptop. A consumer CPU (P+E cores, HyperThreading, all-core throttling)
  understates core scaling; on a server CPU the numbers would be higher and more linear.
- Trivial queries understate the point of SConcur (concurrent I/O). For fair positioning the
  I/O-bound scenario is the separate `bench-http-server-io` benches.
- Distance. The 10-minute soak (1.74M requests) already confirmed the absence of a slow leak
  (slope +0.11 MiB/min). For absolute certainty in production — a multi-hour run:
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
