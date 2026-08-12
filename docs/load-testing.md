English | [Русский](load-testing.ru.md)

# Load testing: server behaviour under load

How to hammer the HTTP server with all async-I/O features at once while capturing
memory and CPU, plus the results of a reference run.

Tools:

- `/all` of the demo server (`tests/servers/http/http-server.php`) — per request it
  fans out (a nested `WaitGroup`) across MongoDB (insert + findOne), MySQL
  (`INSERT` + `SELECT 1`) and PostgreSQL (`INSERT` + `SELECT 1`). The HTTP client
  is deliberately excluded: its self-hit into the server's own `/` would make every
  `/all` serve a second request and skew the rps. Connections are created lazily,
  once per worker;
- `/all-nowg` — the same six operations sequentially, with no `WaitGroup`, so the
  only concurrency left is the cross-request one. Run it via
  `ROUTE=/all-nowg tests/benchmarks/http-load-stats.sh`;
- `tests/benchmarks/http-load-stats.sh` (`make bench-http-load-stats`) — brings up
  a pool of servers (`SO_REUSEPORT`, one process per core), runs `wrk`, and during
  the run samples `docker stats` (CPU%/MEM for the server and DB containers) plus
  the aggregate worker RSS from `/proc/<pid>/status` (leak detection);
- `rr-load-stats.sh` and `swoole-load-stats.sh` (`make bench-rr-load-stats` /
  `make bench-swoole-load-stats`) — the same harness against the RoadRunner and
  Swoole reference stacks on native drivers, with `ROUTE=/all-coro`
  (`make bench-swoole-coro-load-stats`) for Swoole's own in-request fan-out.

## How to run

You need `wrk` on the host and the services up (`make up`). Run from the host; the
servers run in the container, where the extension is built.

```sh
make bench-http-load-stats
# tuning via env:
SERVERS=12 WRK_THREADS=4 CONNECTIONS=256 DURATION=20 SAMPLE_INTERVAL=2 \
    tests/benchmarks/http-load-stats.sh

# baseline against the empty "/" route:
make bench-http-load-stats-empty

# soak mode: a long run with an RSS trend and slope (MiB/min) for slow-leak detection
make bench-http-load-soak               # 10 minutes by default
DURATION=3600 make bench-http-load-soak # hour-long soak
```

`MODE=soak` additionally prints a `trend (elapsed → RSS)` table and a least-squares
slope in MiB/min with a verdict: `stable` / `growth — possible leak` /
`declining (GC/memory return)`.

The methodology matches `http-throughput.sh`: the servers and the load generator
are pinned to non-overlapping cores (`taskset`), and `wrk` hits the container's
bridge IP directly, bypassing docker-proxy (NAT caps throughput).

## Baseline run (empty route)

An Intel i7-13620H laptop (16 threads), services in Docker, 12 servers / 4 wrk
cores, 256 connections, 20 s. `/` responds `ok` with no I/O and no noticeable CPU —
the ceiling of the HTTP-server + framework pairing, the floor on top of which the
feature tax of `/all` is laid.

| Metric | `/` (empty) | `/all` (all features) |
|---|---|---|
| Throughput | ≈133 500 req/sec | 2 667 req/sec |
| Latency | p50 1.8 · p90 7.1 · p99 30.1 ms | p50 87 · p90 162 · p99 269 ms |
| Servers CPU (`php`) | avg ~1218 % | avg 730 % |
| Worker RSS (sum of 12) | ~590 MiB (flat) | ~656 MiB |

Three runs held ~133k req/sec with 0 errors. The ~50× gap is the price of the
per-feature fan-out: `/all` does a 3-way concurrent fan-out across the PHP↔Go
boundary plus the fsync of 3 disk writes per request, and throughput hits exactly
that, not the cheap DB read. The empty route has none of it and is CPU-bound at
~1200 %.

## Reference run

Same machine and parameters, `wrk` against `/all` — a 3-way fan-out over 6 DB
operations, 3 of them disk writes.

| Metric | Value |
|---|---|
| Throughput | 2 667 req/sec (0 errors — all 3 features `ok`) |
| Latency | p50 87 · p90 162 · p99 269 ms |
| Worker RSS (sum of 12) | first 649.4 / peak 656.1 / last 656.1 MiB → drift +6.7 MiB |
| Servers CPU (`php`) | avg 730 % / peak 753 % (≈ 7–8 of 12 cores) |
| Backends CPU | MongoDB 113 %/138 peak · MySQL 70 %/74 · PostgreSQL 52 %/62 |
| MEM (containers) | php 260 · mongo 160 · mysql 496 · pg 126 MiB |

The RSS drift over 20 s is warm-up noise; the authoritative leak verdict comes from
the soak below.

## Soak run (10 minutes)

The same environment, a sustained load of 128 connections for 600 s — 1 738 813
requests (~5.2M feature operations).

| Metric | Value |
|---|---|
| Throughput | 2 897 req/sec · p50 40 · p90 72 · p99 127 ms |
| Worker RSS (trend over 10 min) | first 618.5 / peak 621.8 / last 620.6 MiB |
| Drift / slope | +2.1 MiB / +0.11 MiB/min → verdict stable |
| Servers CPU (`php`) | avg 741 % / peak 768 % |

RSS stayed flat (618–622 MiB) for the whole distance — the slope is within noise,
there is no slow leak. The `mongodb` container's MEM meanwhile grew to ~372 MiB
because of the unbounded inserts of `/all` into the `load_all` collection, while
the worker RSS did not budge: the data is accumulated by the DB, not by SConcur
(the collection can be dropped after the runs).

## Fan-out vs sequential calls (`/all` vs `/all-nowg`)

Same machine and parameters, disk-backed backends, state reset
(`make bench-reset`) between runs.

| Metric | `/all` (fan-out) | `/all-nowg` (sequential) |
|---|---|---|
| Throughput | 2 620 req/sec | 2 570 req/sec (−2 %) |
| Latency | p50 87.6 · p90 174.8 · p99 294.8 ms | p50 90.8 · p90 164.5 · p99 261.2 ms |
| Servers CPU (`php`) | avg 717 % | avg 503 % (−30 %) |
| Worker RSS drift (20 s) | +3.3 MiB | +0.0 MiB |

Single connection (1 server / 1 wrk thread / 1 connection / 5 s):

| Metric | `/all` (fan-out) | `/all-nowg` (sequential) |
|---|---|---|
| Latency avg | 9.9 ms | 12.2 ms (+23 %) |
| Throughput | 101 req/sec | 82 req/sec |

1. Under saturation the throughput is the same: the ceiling is the backends' disk
   commits, and 256 in-flight request coroutines keep them busy without the
   intra-request fan-out — features called outside a `WaitGroup` still suspend and
   yield.
2. What the fan-out buys is per-request latency — 9.9 vs 12.2 ms at a single
   connection, where the three feature blocks overlap their disk commits.
3. Sequential calls are cheaper in CPU: avg 503 % against 717 % for the same rps,
   roughly two cores of headroom (no `WaitGroup`, no 3 extra coroutines per
   request).
4. On tmpfs backends the picture flips: sub-millisecond operations make the
   fan-out machinery cost more than the parallelism returns, and the sequential
   route wins outright (6 200 vs 3 860 req/sec, 0.9 vs 1.5 ms at a single
   connection). Fan out when the request has genuinely slow I/O to overlap.

## Conclusions

1. Memory is stable — the main result. ~50 MiB RSS per worker, and a 10-minute
   soak (1.74M requests) held RSS flat at +0.11 MiB/min (= noise). For a long-lived
   server this is the key signal: the Go runtime + PHP fibers + connection pools +
   PHP↔Go boundary pairing accumulates nothing. Consistent with `MemLeakTest`.
2. Robustness: saturation with a 3-way fan-out per request → 0 errors, p99
   ≈ 130 ms under sustained soak load.
3. On disk backends the bottleneck is fsync, not CPU. The servers draw ~7–8 of 12
   cores versus ~0.5–1.5 on each DB — the ~2.7k rps ceiling is set by the 3 disk
   commits per request plus the framework overhead (msgpack, fiber
   spawn/scheduling, 3× PHP↔Go crossing), not by the `SELECT 1`/`findOne` reads.

Caveats: the runs are synthetic and on a laptop (a consumer CPU understates core
scaling); trivial queries understate the point of SConcur — the I/O-bound scenario
is covered by the separate `bench-http-server-io` benches; and for absolute
certainty about leaks in production a multi-hour soak is the answer
(`DURATION=3600 make bench-http-load-soak` and longer).

## WebSocket server under load

Same load + resources pairing, but `wrk` is HTTP-only, so the generator is
`ext/cmd/ws-load` (Go, on `coder/websocket`) — the WS analogue of `wrk`: it holds N
persistent connections, runs back-to-back round-trips and prints throughput and
p50/p90/p99.

The `all` command of the demo server (`tests/servers/ws/ws-server.php`) fans out
the same backend features per message, with `Sleeper` added to the mix.
`tests/benchmarks/ws-load-stats.sh` (`make bench-ws-load-stats`) brings up the pool
and samples the same metrics as `http-load-stats.sh`. The difference from the HTTP
variant: both the pool and the generator live in the `php` container, pinned to
non-overlapping cores, and the generator hits the pool over loopback.

```sh
make bench-ws-load-stats
SERVERS=12 CONNECTIONS=256 DURATION=20 SAMPLE_INTERVAL=2 tests/benchmarks/ws-load-stats.sh

make bench-ws-load-stats-empty  # baseline against "ping"
make bench-ws-load-soak         # soak, 10 minutes by default
```

The metrics read the same way as for HTTP: `ping` against `all` shows the price of
the feature fan-out, and the RSS slope in soak mode is the authoritative leak
verdict. The WS-server and HTTP-server sides are built the same way, so the
conclusions carry over.

See also: [HTTP server](http-server.md), [WebSocket server](websocket-server.md),
[Worker master](worker-master.md).
