English | [Русский](load-testing.ru.md)

# Load testing: server behaviour under load

How to load the HTTP server with all async-I/O features at once (a feature is one
of the library's integrations in `src/Features/`) while capturing memory and CPU,
plus the results of a reference run.

Tools:

- `/all` of the demo server (`tests/servers/http/http-server.php`) — per request
  it runs three blocks at the same time (a nested `WaitGroup`), each making its
  two calls one after another: MongoDB (insert + findOne), MySQL (`INSERT` +
  `SELECT 1`) and PostgreSQL (`INSERT` + `SELECT 1`). The HTTP client is
  deliberately excluded: its self-hit into the server's own `/` would make every
  `/all` serve a second request and skew the rps. Connections are created
  lazily, once per worker;
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
  three blocks concurrently in its own coroutines.

## How to run

You need `wrk` on the host and the services up (`make up`). Run from the host; the
servers run in the container, where the extension is built.

```sh
make bench-http-load-stats
# tuning via env:
SERVERS=12 WRK_THREADS=4 CONNECTIONS=256 DURATION=20 SAMPLE_INTERVAL=2 \
    tests/benchmarks/http/load-stats.sh

# baseline against the empty "/" endpoint (PIN_SERVERS=group unless set):
make bench-http-load-stats-empty

# soak mode: a long run with an RSS trend and slope (MiB/min) for slow-leak detection
make bench-http-load-soak               # 10 minutes by default
DURATION=3600 make bench-http-load-soak # hour-long soak

# one shared socket instead of SO_REUSEPORT, a connection per request,
# requests counted per worker
SHARED_SOCKET=1 CONNECTION_CLOSE=1 WORKER_LOGS=1 ROUTE=/ PIN_SERVERS=group \
    tests/benchmarks/http/load-stats.sh

# the workers as forks of one php, the way a worker master with launch=fork starts them
LAUNCH=fork make bench-http-load-stats-empty
```

`LAUNCH` says how the workers start: `exec` (the default) runs a `php` of its own per
worker, `fork` makes every worker a fork of one `php` that loaded the library
(`tests/benchmarks/http/fork-pool.php`, through the same `ForkedWorker` a
[worker master](worker-master.md#launch-exec-or-fork) group uses). Placement, ports
and output are the same in both, so a pair of runs differs in the launch alone. A
forked worker pinned to its own CPUs gets them before its threads exist: the parent
sets its own affinity before each fork. The workers' output goes to `/dev/null` in
both, not through a master's journal. `WORKER_LOGS=1` needs `exec`, since forks of one
process share its stdout.

`MODE=soak` additionally prints a `trend (elapsed → RSS)` table, and the
least-squares slope in MiB/min gets a verdict: `stable` / `growing — possible leak` /
`falling (GC / memory returned)`. A short run prints the slope without a verdict:
its first RSS sample is taken before the load starts, so the slope over 20 s is
mostly warm-up and would read as a leak every time.

The methodology matches `tests/benchmarks/http/throughput.sh`: the servers and
the load generator are pinned to non-overlapping cores (`taskset`), and `wrk`
hits the container's bridge IP directly, bypassing docker-proxy (NAT caps
throughput).

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
worth about 6.5% on the empty endpoint.

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
12–15, median of three interleaved rounds of 20 s:

| placement | rps | p50 | p90 | p99 |
|---|---:|---:|---:|---:|
| `1` — one logical CPU each | 232 864 | 1.01 ms | 1.60 ms | 3.86 ms |
| `group` — the scheduler places them | 248 002 | 0.86 ms | 2.09 ms | 4.19 ms |

`group` is 6.5% ahead on throughput and median; the pinned arm has the slightly
shorter tail (p90 1.6 against 2.1 ms, p99 within 0.3 ms). `physical` lands on
`1` rather than between the two — in an arm of its own it holds 228 720 rps
against 229 267 for `1`.

The gap is not a detail of naive pinning that a smarter placement would fix; it
comes from pinning as such. The explanation that fits every measurement: each
worker has two threads — PHP and a runtime thread — so twelve workers put about
twenty-four runnable threads on twelve logical CPUs. A static placement cannot
rebalance uneven load, and the scheduler can: a pinned idle worker has no way to
lend its core to a busy neighbour.

That is why there is no `cpuAffinity` setting. Shipping a knob that, on an equal
core budget, enables something 6–7% slower is not a choice. The current
behaviour — `WorkerMaster` pins nothing — is the measured optimum.

Confining a pool to part of a machine does not need one either. `group` is what a
mask on the master gives you for free, because the workers inherit it:
`taskset -c 0-11 sconcur-server …`, or `--cpuset-cpus` on the container, or
`CPUAffinity=` in the unit. The placements that would need per-worker support
from the library are `1` and `physical`, and those are the two that measured
worse.

Two things this does not say anything about, because they were not measured: one
worker per physical core with no neighbour (that is a different worker count, so a
different experiment), and a machine given over to a single pool.

### The catch worth knowing

A pinned process sees only its own cores:

```
unpinned:            nproc → 16
under taskset -c 3:  nproc → 1
```

Sizing the runtime from that number would give one thread under the harness and
sixteen in production, where nothing pins — right in every measurement and wrong
in every deployment, with nothing in the numbers to show it. So the extension
builds one runtime thread regardless of the core count, and
`SCONCUR_RUNTIME_THREADS` raises it for a process that wants the extension on
more than one core.

The wider consequence: every figure in [benchmarks](benchmarks.md) is taken with
pinning, and production does not pin. For comparing stacks against each other that
is the correct methodology, but it is not the configuration the library runs in,
and the difference is not zero. Comparisons are only valid between runs with the
same `PIN_SERVERS`.

## Baseline run (empty endpoint)

An Intel i7-13620H laptop (16 threads) on the Performance power profile,
services in Docker on disk-backed volumes, 12 servers / 4 wrk cores, 256 connections, 20 s, `PIN_SERVERS=group`.
`/` responds `ok` with no I/O and no noticeable
CPU — the ceiling of the HTTP-server + framework pairing, and the floor on top
of which the cost of the `/all` feature calls is added.

| Metric | `/` (empty) | `/all` (all features) |
|---|---|---|
| Throughput | ≈252 800 req/sec | ≈2 950 req/sec |
| Latency | p50 0.83 · p90 2.1 · p99 4.2 ms | p50 83 · p90 120 · p99 190 ms |
| Servers CPU (`php`) | avg ~1184 % | avg ~205 % |
| Worker RSS (sum of 12) | ~450 MiB (flat) | ~549 MiB |

The runs held ~250k req/sec with 0 errors. The ~85× gap is the price of the
feature calls: `/all` crosses the PHP↔extension boundary for three feature blocks
at once and pays the fsync of 3 disk writes per request. Throughput hits exactly
that, not the cheap DB read. The empty endpoint has none of it and is CPU-bound
at ~1200 %.

## Reference run

Same machine and parameters, `wrk` against `/all` — 6 DB operations in three
feature blocks running at the same time, 3 of them disk writes.

| Metric | Value |
|---|---|
| Throughput | ≈2 950 req/sec (0 errors — all 3 features `ok`) |
| Latency | p50 83 · p90 120 · p99 190 ms |
| Worker RSS (sum of 12) | ~549 MiB |
| Servers CPU (`php`) | avg 205 % / peak 292 % (≈ 2–3 of 12 cores) |
| Backends CPU | MongoDB ~78 % · MySQL ~67 % · PostgreSQL ~61 % |
| MEM (containers) | php 244 MiB |

The RSS drift over 20 s is warm-up noise; the authoritative leak verdict comes from
the soak below.

## Soak run (10 minutes)

The same environment, a sustained load of 128 connections for 600 s — 1 431 343
requests (~4.3M feature operations).

| Metric | Value |
|---|---|
| Throughput | 2 385 req/sec · p50 52 · p90 88 · p99 149 ms |
| Worker RSS (trend over 10 min) | first 532.0 / peak 533.7 / last 533.6 MiB |
| Drift / slope | +1.6 MiB / +0.06 MiB/min → verdict stable |
| Servers CPU (`php`) | avg 157 % / peak 239 % |

RSS stayed flat (532–534 MiB) for the whole distance — the slope is within noise,
there is no slow leak. The `mongodb` container's MEM meanwhile grew to ~234 MiB
because of the unbounded inserts of `/all` into the `load_all` collection, while
the worker RSS did not budge: the data is accumulated by the DB, not by SConcur.

The harness empties those tables around every run, and that is not tidiness. The
three databases keep their data on a 1 GiB tmpfs (`docker-compose.yml`), which a
ten-minute soak at a few thousand rps fills — after which everything that needs
space fails with "the table is full". What breaks then is not the next benchmark
but the next test run, with an error that points nowhere near the benchmark that
caused it.

## Concurrent vs sequential calls (`/all` vs `/all-nowg`)

Same machine and parameters, disk-backed backends, state reset
(`make bench-reset`) between runs.

| Metric | `/all` (concurrent) | `/all-nowg` (sequential) |
|---|---|---|
| Throughput | 2 950 req/sec | 2 768 req/sec (−6 % vs `/all`) |
| Latency | p50 83.1 · p90 120.0 · p99 189.7 ms | p50 91.8 · p90 123.5 · p99 161.6 ms |
| Servers CPU (`php`) | avg 205 % | avg 136 % (−34 % vs `/all`) |
| Worker RSS drift (20 s) | +1.6 MiB | +1.4 MiB |

Single connection (1 server / 1 wrk thread / 1 connection / 5 s):

| Metric | `/all` (concurrent) | `/all-nowg` (sequential) |
|---|---|---|
| Latency avg | 6.3 ms | 7.6 ms (+20 % vs `/all`) |
| Throughput | 158 req/sec | 132 req/sec |

1. Under saturation the throughput is nearly the same: the ceiling is the
   backends' disk commits, and the 256 requests being served keep them busy even
   without running the calls of a single request concurrently. The server runs
   every request in a coroutine of its own (a PHP Fiber suspended while it waits
   on the extension), so a feature called outside a `WaitGroup` still suspends
   only that coroutine, and the process serves other requests in the meantime.
2. What running them concurrently improves is per-request latency — 6.3 vs
   7.6 ms at a single connection, where the three feature blocks wait for their disk
   commits at the same time.
3. Sequential calls are cheaper in CPU: avg 136 % against 205 % for about the
   same rps, roughly two thirds of a core of headroom (no `WaitGroup`, no 3 extra
   coroutines per request).
4. On tmpfs backends the saturation picture flips: with sub-millisecond
   operations the coordination costs more than the parallelism returns, and the
   sequential endpoint serves more (7 920 vs 7 090 req/sec, +12 %). A single
   connection still answers faster with the calls running at the same time
   (0.46 vs 0.66 ms). Run the calls of one request concurrently when it has
   genuinely slow I/O to wait for.

## Shared socket vs `SO_REUSEPORT`

`SHARED_SOCKET=1` runs the same pool on one socket, bound once and inherited by
every worker as `--listenFd`, the way a [worker master](worker-master.md) group
with `listen` runs it: `tests/benchmarks/http/shared-socket.php` binds the socket
and runs the spawn loop under it. `CONNECTION_CLOSE=1` sends `Connection: close`,
so every request opens a connection of its own and goes through `accept`.

12 workers with `PIN_SERVERS=group`, `wrk` with 4 threads on the other cores, the
empty endpoint `/`, 15 s per run, three runs of each arm with the arms
alternating, the medians below. The spread is the requests served by the busiest
worker over those of the idlest (`WORKER_LOGS=1`); CPU per request is the average
CPU of the `php` container over the rps. Profiles: 256 and 32 keep-alive
connections, a connection per request, and the mixed one of
`mixed-profile.lua` (10% of the requests on `/cpu/20000`, 256 connections).

Three ways of accepting:

1. `SO_REUSEPORT`: a socket per worker, the kernel picks one by a hash of the
   client's address and port.
2. A shared socket accepted on the runtime, like a socket of its own: every
   worker wakes for every connection, and the first to run takes whatever is
   queued. The first version did this, and on systems other than Linux the
   server still does.
3. A shared socket accepted by a thread of the server (Linux): the thread waits
   with `EPOLLEXCLUSIVE`, and only while the server asks for a connection, so a
   connection wakes one waiting worker and the workers take turns.

Accepting on the runtime, against `SO_REUSEPORT` in the same series:

| Profile | Accepting | rps | p99 | CPU per request | Spread |
|---|---|---:|---:|---:|---|
| 256 connections | `SO_REUSEPORT` | 239 848 | 4.6 ms | 46 µs | 1.36–1.71× |
| 256 connections | shared, runtime | 233 877 | 4.2 ms | 45 µs | 7.4–9.0×, 2 workers idle in one run |
| 32 connections | `SO_REUSEPORT` | 180 143 | 1.2 ms | 59 µs | 6.1–6.5×, 2 workers idle in one run |
| 32 connections | shared, runtime | 176 957 | 0.5 ms | 52 µs | 6 to 9 of 12 workers idle |
| connection per request | `SO_REUSEPORT` | 67 936 | 3.8 ms | 97 µs | 1.08–1.10× |
| connection per request | shared, runtime | 62 942 | 3.9 ms | 105 µs | 8.2–9.6× |
| mixed | `SO_REUSEPORT` | 21 530 | 33.6 ms | 550 µs | 1.06–1.09× |
| mixed | shared, runtime | 21 312 | 46.6 ms | 552 µs | 1.16–1.23×, 1 worker idle in one run |

The accept thread, against `SO_REUSEPORT` in a second series:

| Profile | Accepting | rps | p99 | CPU per request | Spread |
|---|---|---:|---:|---:|---|
| 256 connections | `SO_REUSEPORT` | 244 515 | 4.4 ms | 45 µs | 1.41–1.60× |
| 256 connections | shared, thread | 247 418 | 4.3 ms | 45 µs | 1.22–1.53× |
| 32 connections | `SO_REUSEPORT` | 189 918 | 1.2 ms | 59 µs | 6.3×, 1 worker idle in two runs |
| 32 connections | shared, thread | 182 740 | 1.1 ms | 63 µs | 2.9–6.7× |
| connection per request | `SO_REUSEPORT` | 68 765 | 3.3 ms | 93 µs | 1.08–1.11× |
| connection per request | shared, thread | 67 455 | 3.6 ms | 103 µs | 1.02× |
| mixed | `SO_REUSEPORT` | 21 500 | 34.2 ms | 559 µs | 1.08–1.16× |
| mixed | shared, thread | 21 602 | 35.5 ms | 558 µs | 1.06–1.15× |

1. Accepting a shared socket on the runtime does not work. The workers that wake
   first take the connections: 7–9× between the busiest and the idlest at 256
   connections, half the pool or more idle at 32. With a connection per request
   every worker wakes for every connection, which costs 7% of the rps and 9%
   more CPU per request, and in the mixed profile the p99 is 40% worse.
2. With the accept thread a shared socket keeps up with `SO_REUSEPORT`: the rps
   within 2% at 256 connections, with a connection per request and in the mixed
   profile, the spread no worse. With a connection per request it is even
   exactly even, where the hash of `SO_REUSEPORT` gives 1.08–1.11×.
3. The price is CPU when connections are short: with a connection per request
   about 10% more per request, most likely the hand-off of every connection from
   the thread to the runtime. With keep-alive connections it does not show.
4. Neither spreads 32 connections evenly over 12 workers. The kernel hands out
   connections, not requests, and wrk opens all of them at once. `SO_REUSEPORT`
   left a worker without a connection in two runs of three. The shared socket
   gave every worker at least one, at 2.9–6.7× and 4% fewer rps. For a handful
   of long-lived connections the answer is a proxy, see
   [a proxy in front of the pool](http-server.md#a-proxy-in-front-of-the-pool).

## Conclusions

1. Memory is stable — the main result. ~45 MiB RSS per worker, and a 10-minute
   soak (1.43M requests) held RSS flat at +0.06 MiB/min (= noise). For a
   long-lived server this is the key signal: the extension's runtime + PHP fibers +
   connection pools + PHP↔extension boundary pairing accumulates nothing. Consistent
   with `MemLeakTest`.
2. Robustness: saturation with three concurrent feature blocks per request → 0
   errors, p99 ≈ 150 ms under sustained soak load.
3. On disk backends the bottleneck is fsync, not CPU. The servers draw ~2 of 12
   cores and each DB under one — the ~2.9k rps ceiling is set by the 3 disk
   commits per request plus the framework overhead (fiber spawn/scheduling,
   3× boundary crossing with its value conversion), not by the `SELECT 1`/`findOne` reads.

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
up the pool and samples the same metrics as
`tests/benchmarks/http/load-stats.sh`. The difference from the HTTP variant:
both the pool and the generator live in the `php` container, pinned to
non-overlapping cores, and the generator hits the pool over loopback.

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
