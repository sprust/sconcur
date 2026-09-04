English | [Русский](load-testing.ru.md)

# Load testing: server behaviour under load

How to load the HTTP server with all async-I/O features at once while capturing
memory and CPU, plus the results of a reference run.

Tools:

- `/all` of the demo server (`tests/servers/http/http-server.php`) — per request
  it runs six operations at the same time (a nested `WaitGroup`): MongoDB
  (insert + findOne), MySQL (`INSERT` + `SELECT 1`) and PostgreSQL (`INSERT` +
  `SELECT 1`). The HTTP client is deliberately excluded: its self-hit into the
  server's own `/` would make every `/all` serve a second request and skew the
  rps. Connections are created lazily, once per worker;
- `/all-nowg` — the same six operations one after another, with no `WaitGroup`,
  so the only concurrency left is between different requests. Run it via
  `ROUTE=/all-nowg tests/benchmarks/http/load-stats.sh`;
- `tests/benchmarks/http/load-stats.sh` (`make bench-http-load-stats`) — brings up
  a pool of servers (`SO_REUSEPORT`, one process per core), runs `wrk`, and during
  the run samples `docker stats` (CPU%/MEM for the server and DB containers) plus
  the aggregate worker RSS from `/proc/<pid>/status` (leak detection);
- `rr-load-stats.sh` and `swoole-load-stats.sh` (`make bench-rr-load-stats` /
  `make bench-swoole-load-stats`) — the same test rig against the RoadRunner and
  Swoole reference stacks on native drivers, with `ROUTE=/all-coro`
  (`make bench-swoole-coro-load-stats`) for the variant where Swoole runs the
  six operations concurrently in its own coroutines.

## How to run

You need `wrk` on the host and the services up (`make up`). Run from the host; the
servers run in the container, where the extension is built.

```sh
make bench-http-load-stats
# tuning via env:
SERVERS=12 WRK_THREADS=4 CONNECTIONS=256 DURATION=20 SAMPLE_INTERVAL=2 \
    tests/benchmarks/http/load-stats.sh

# baseline against the empty "/" endpoint:
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

## CPU pinning

Normally the kernel decides which core a process runs on and may move it. Pinning
tells a process to stay on the core it was given — `taskset` on Linux.

The harness pins for one reason: repeatability. Twelve servers and `wrk` on one
machine otherwise fight for the same cores, the load generator steals CPU from the
thing being measured, and the number depends on who won that round. Splitting
them across non-overlapping sets — servers on `0..SERVERS-1`, `wrk` on the rest —
is what makes a run comparable to the next one.

Comparability against the other stacks is weaker than that, and the mode table
below says why: `rr-load-stats.sh` and `swoole-load-stats.sh` give the whole
server `taskset -c 0-$((WORKERS-1))` and let the scheduler place the workers
inside it, which is the `group` placement, while this harness defaults to `1`.
The core budget is the same in both, the placement is not, and the placement is
worth about twenty percent.

`PIN_SERVERS` selects the placement. Every mode draws from the same budget
(`cpu 0..SERVERS-1`), so the modes differ only in where inside it the workers sit:

| `PIN_SERVERS` | placement |
|---|---|
| `1` (default) | one logical CPU per worker — `taskset -c $i` |
| `physical` | one physical core per worker: the whole sibling pair, read from `/sys/devices/system/cpu/cpuN/topology/thread_siblings_list` |
| `group` | the pool confined to the budget, the scheduler placing workers inside it |
| `0` | unpinned, the way the worker master actually runs them |

```shell
PIN_SERVERS=group ROUTE=/ tests/benchmarks/http/load-stats.sh
```

### What was measured, and why the library has no pinning option

The empty endpoint, 12 workers, the same `cpu0-11` budget in every arm, `wrk` on
12-19, three interleaved rounds of 20 s:

| placement | rps per round | median |
|---|---|---:|
| `1` — one logical CPU each | 121 446 / 125 284 / 123 185 | 123 185 |
| `physical` — a sibling pair each | 123 055 / 120 984 / 115 566 | 120 984 |
| `group` — the scheduler places them | 151 761 / 146 644 / 147 654 | **147 654** |

`physical` does not differ from `1` — the ranges overlap and its median is
slightly lower. `group` beats both by 19.9%, and its range does not overlap
theirs at all (its worst round, 146 644, is above their best, 125 284).

So the gap is not a detail of naive pinning that a smarter placement would fix.
It comes from pinning as such. The explanation that fits both measurements: each
worker has two threads — PHP and a runtime thread — so twelve workers put about
twenty-four runnable threads on twelve logical CPUs. A static placement cannot
rebalance uneven load, and the scheduler can: a pinned idle worker has no way to
lend its core to a busy neighbour.

That is why there is no `cpuAffinity` setting. Shipping a knob that, on an equal
core budget, enables something twenty percent slower is not a choice. The current
behaviour — `WorkerMaster` pins nothing — is the measured optimum.

Two things this does not say anything about, because they were not measured: one
worker per physical core with no neighbour (that is a different worker count, so a
different experiment), and a machine given over to a single pool.

### The catch worth knowing

A pinned process sees only its own cores:

```
unpinned:            nproc → 16
under taskset -c 3:  nproc → 1
```

The extension sizes its runtime from that number. Under the harness it was told
"one" and built one thread; in production, where nothing pins, it was told
"sixteen" and each of twelve processes built sixteen. The number was right in
every measurement and wrong in every deployment, and nothing in the numbers could
show it. It now uses one thread regardless, and `SCONCUR_RUNTIME_THREADS` raises
it for a process that wants the extension on more than one core.

The wider consequence: every figure in [benchmarks](benchmarks.md) is taken with
pinning, and production does not pin. For comparing stacks against each other that
is the correct methodology, but it is not the configuration the library runs in,
and the difference is not zero. Comparisons are only valid between runs with the
same `PIN_SERVERS`.

## Baseline run (empty endpoint)

An Intel i7-13620H laptop (16 threads), services in Docker, 12 servers / 4 wrk
cores, 256 connections, 20 s. `/` responds `ok` with no I/O and no noticeable
CPU — the ceiling of the HTTP-server + framework pairing, and the floor on top
of which the cost of the `/all` feature calls is added.

| Metric | `/` (empty) | `/all` (all features) |
|---|---|---|
| Throughput | ≈133 500 req/sec | ≈3 010 req/sec |
| Latency | p50 1.8 · p90 7.1 · p99 30.1 ms | p50 76 · p90 155 · p99 267 ms |
| Servers CPU (`php`) | avg ~1218 % | avg ~563 % |
| Worker RSS (sum of 12) | ~590 MiB (flat) | ~660 MiB |

Three runs held ~133k req/sec with 0 errors. The ~44× gap is the price of the
feature calls: `/all` crosses the PHP↔extension boundary for three feature blocks at
once and pays the fsync of 3 disk writes per request. Throughput hits exactly
that, not the cheap DB read. The empty endpoint has none of it and is CPU-bound
at ~1200 %.

## Reference run

Same machine and parameters, `wrk` against `/all` — 6 DB operations running at
the same time in three feature blocks, 3 of them disk writes.

| Metric | Value |
|---|---|
| Throughput | ≈3 010 req/sec (0 errors — all 3 features `ok`) |
| Latency | p50 76 · p90 155 · p99 267 ms |
| Worker RSS (sum of 12) | first 652.6 / peak 659.7 / last 659.7 MiB → drift +7.0 MiB |
| Servers CPU (`php`) | avg 561 % / peak 582 % (≈ 5–6 of 12 cores) |
| Backends CPU | MongoDB 189 %/222 peak · MySQL 120 %/124 · PostgreSQL 84 %/93 |
| MEM (containers) | php 279 · mongo 178 · mysql 667 · pg 139 MiB |

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

## Concurrent vs sequential calls (`/all` vs `/all-nowg`)

Same machine and parameters, disk-backed backends, state reset
(`make bench-reset`) between runs.

| Metric | `/all` (concurrent) | `/all-nowg` (sequential) |
|---|---|---|
| Throughput | 2 620 req/sec | 2 570 req/sec (−2 %) |
| Latency | p50 87.6 · p90 174.8 · p99 294.8 ms | p50 90.8 · p90 164.5 · p99 261.2 ms |
| Servers CPU (`php`) | avg 717 % | avg 503 % (−30 %) |
| Worker RSS drift (20 s) | +3.3 MiB | +0.0 MiB |

Single connection (1 server / 1 wrk thread / 1 connection / 5 s):

| Metric | `/all` (concurrent) | `/all-nowg` (sequential) |
|---|---|---|
| Latency avg | 9.9 ms | 12.2 ms (+23 %) |
| Throughput | 101 req/sec | 82 req/sec |

1. Under saturation the throughput is the same: the ceiling is the backends'
   disk commits, and the 256 request coroutines being served keep them busy even
   without running the calls of a single request concurrently — a feature called
   outside a `WaitGroup` still suspends its coroutine, and the process serves
   other requests in the meantime.
2. What running them concurrently improves is per-request latency — 9.9 vs
   12.2 ms at a single connection, where the three feature blocks wait for their disk
   commits at the same time.
3. Sequential calls are cheaper in CPU: avg 503 % against 717 % for the same
   rps, roughly two cores of headroom (no `WaitGroup`, no 3 extra coroutines per
   request).
4. On tmpfs backends the picture flips: with sub-millisecond operations the
   coordination costs more than the parallelism returns, and the sequential
   endpoint wins outright (6 200 vs 3 860 req/sec, 0.9 vs 1.5 ms at a single
   connection). Run the calls of one request concurrently when it has genuinely
   slow I/O to wait for.

## Conclusions

1. Memory is stable — the main result. ~50 MiB RSS per worker, and a 10-minute
   soak (1.74M requests) held RSS flat at +0.11 MiB/min (= noise). For a
   long-lived server this is the key signal: the extension's runtime + PHP fibers +
   connection pools + PHP↔extension boundary pairing accumulates nothing. Consistent
   with `MemLeakTest`.
2. Robustness: saturation with three concurrent feature blocks per request → 0
   errors, p99 ≈ 130 ms under sustained soak load.
3. On disk backends the bottleneck is fsync, not CPU. The servers draw ~7–8 of 12
   cores versus ~0.5–1.5 on each DB — the ~2.7k rps ceiling is set by the 3 disk
   commits per request plus the framework overhead (msgpack, fiber
   spawn/scheduling, 3× boundary crossing), not by the `SELECT 1`/`findOne` reads.

Caveats: the runs are synthetic and on a laptop (a consumer CPU understates core
scaling); trivial queries understate the point of SConcur — the I/O-bound scenario
is covered by the separate `bench-http-server-io` benches; and for absolute
certainty about leaks in production a multi-hour soak is the answer
(`DURATION=3600 make bench-http-load-soak` and longer).

## WebSocket server under load

Same load + resources pairing, but `wrk` is HTTP-only, so the WS side has a
generator of its own: `tests/benchmarks/ws/ws-load/`, which holds N persistent
connections, runs back-to-back round-trips and prints throughput with
p50/p90/p99. The harness builds it before a run.

It is a crate separate from the core on purpose — the core is built with fat LTO
on every `make ext-build`, and a benchmark tool has no business making that
slower. Latencies go into a fixed histogram (0.1 ms per bucket) rather than a
list of samples: at a few hundred thousand round-trips the list would be the
largest allocation in the generator, and a percentile does not need it.

The `all` command of the demo server (`tests/servers/ws/ws-server.php`) runs the
same backend features concurrently for every message, with `Sleeper` added to
the mix. `tests/benchmarks/ws/load-stats.sh` (`make bench-ws-load-stats`) brings
up the pool and samples the same metrics as `http-load-stats.sh`. The difference
from the HTTP variant: both the pool and the generator live in the `php`
container, pinned to non-overlapping cores, and the generator hits the pool over
loopback.

```sh
make bench-ws-load-stats
SERVERS=12 CONNECTIONS=256 DURATION=20 SAMPLE_INTERVAL=2 tests/benchmarks/ws/load-stats.sh

make bench-ws-load-stats-empty  # baseline against "ping"
make bench-ws-load-soak         # soak, 10 minutes by default
```

The metrics read the same way as for HTTP: `ping` against `all` shows the price
of the feature calls, and the RSS slope in soak mode is the authoritative leak
verdict. The WS-server and HTTP-server sides are built the same way, so the
conclusions carry over.

See also: [HTTP server](http-server.md), [WebSocket server](websocket-server.md),
[Worker master](worker-master.md).
