English | [Русский](benchmarks.ru.md)

# Feature benchmarks

Performance measurements for each feature (except `Sleeper`): what a call across
the PHP↔Go boundary costs compared to the native driver, and what fanning out a
concurrent run gains. The numbers are a reference, not a guarantee: they were taken
on a specific machine and depend on hardware, DB settings and load.

> The `sync` column carries a fixed overhead that is not inherent to the approach: a
> synchronous call (outside a `WaitGroup`) still goes through the scheduler and the Fiber
> machinery. Cutting it is on the roadmap (see the [README](../README.md#roadmap)), after
> which a call outside a coroutine would go into Go directly. Until then the `sync` numbers
> are not representative — the meaningful comparison is `native` vs `async`.

## Contents

- [Environment](#environment)
- [Conversion overhead (the PHP↔Go boundary)](#conversion-overhead-the-phpgo-boundary)
- [Methodology](#methodology)
- [MongoDB](#mongodb)
- [MySQL](#mysql)
- [PostgreSQL](#postgresql)
- [Clients (HTTP / Socket / WebSocket)](#clients-http--socket--websocket)
- [Servers (HTTP / Socket / WebSocket)](#servers-http--socket--websocket)
  - [HTTP throughput: `/` vs `/all`](#http-throughput--vs-all)
  - [Comparison with RoadRunner (native drivers)](#comparison-with-roadrunner-native-drivers)
- [Conclusions](#conclusions)

## Environment

- CPU: Intel Core i7-13620H (16 threads), 15 GiB RAM, Linux.
- Everything runs in Docker (`docker-compose.yml`); the benchmarks were run from the
  `php` container (`make bench-*`), the server pools — from the `servers` container
  (3 workers, `SO_REUSEPORT`).
- PHP/Go/driver/DB-server versions — see [«Tested
  versions»](../README.md#tested-versions) in the README.

Benchmarks are taken with DB data on the host disk (SSD) — as in a real deployment:
writes pay a real fsync, reads over hot pages come from the DB cache (near-memory
latency). In `docker-compose.yml` the DB data lives in `tmpfs` by default (fast tests,
state reset on restart); for a benchmark session named volumes are uncommented in the
file (and the `tmpfs` blocks commented out):

| DB | Data directory | Volume |
| --- | --- | --- |
| MongoDB | `/data/db` | `mongodb-data` |
| MySQL | `/var/lib/mysql` | `mysql-data` |
| PostgreSQL | `/var/lib/postgresql/data` | `postgres-data` |

Before a benchmark session the DB state is reset with `make bench-reset`
(removes the volumes and recreates the containers): without a reset, writes accumulate
between runs and the numbers drift.

All numbers were taken on 2026-07-22 on an idle machine.

## Conversion overhead (the PHP↔Go boundary)

Every feature call crosses the PHP↔Go boundary, and the data at that boundary is
converted: arguments are packed into MessagePack (msgpack-tagged DTOs,
`Transport/MessagePackTransport`), the result is unpacked back; for MongoDB documents
additionally go through BSON (de)serialization via `ext-mongodb`. This is a fixed CPU
price per operation, on top of the cgo call and goroutine dispatch.

On cheap reads from the DB cache this overhead is visible directly as the gap between
`native` and `sync` (both run sequentially, but `sync` goes through Go): for example,
`pgsql-selectOne` 3.4 → 10.2 ms over 100 calls, `mysql-selectOne` 11.2 → 49.9 ms. The
query itself is almost instant, so the conversion price dominates. On a «slow» operation
(fsync, network, a heavy query) the same fixed surcharge becomes a small fraction of the
total time, and the gap in percent shrinks.

The point of SConcur is not to speed up a single call (here the native driver is always
faster), but to fan out many operations concurrently: one fixed per-call surcharge pays
off because dozens of I/O waits overlap instead of summing up.

## Methodology

Three modes were measured per feature:

- `native` — the baseline without SConcur: the native PHP driver/mechanism (the
  `mongodb/mongodb` driver, `PDO` for MySQL/PostgreSQL, `file_get_contents`/stream
  wrappers for HTTP, raw sockets for TCP/WebSocket). Calls run sequentially.
- `sync` — SConcur outside a `WaitGroup` (the synchronous `Extension::wait` path): the
  same path through Go, but operations run sequentially.
- `async` — SConcur inside a `WaitGroup`: N coroutines are fanned out, the scheduler
  collects results as they become ready.

Each DB benchmark (MongoDB, MySQL, PostgreSQL) was run 10 times and each client/server
benchmark 3 times; the tables show the median. Every DB run starts from a reset state —
the MongoDB `benchmark` collection is dropped before each run, and the SQL benchmark table
is recreated inside the run — so the runs are independent and the median is not skewed by
the harness accumulating data across runs. The number of calls (`count`) per mode: 100 for
DB operations, 50 for client I/O benchmarks (each call is a 100 ms sleep on the server),
20 for `mongodb-createIndex`. About that 20 specifically: `native`, `sync` and `async`
each create `count` indexes on the shared `u-test.benchmark` collection (dropped at the
run's start), and MongoDB's limit is 64 indexes per collection; 3×20 = 60 per run fits,
3×100 = 300 does not. All runs are `make bench-<name> c=<count>`.

Before measuring — a warm-up (discarded): a few sequential native/sync calls and one
full-size async fan-out. This levels the field: native enters the measurement with an
established connection and a prepared statement, and without a warm-up the async fan-out
would pay for spinning up the connection pool (the Go pool and the Mongo driver open
connections on demand up to the fan-out width) right inside the measured phase.
`createIndex` has no warm-up (the index limit). The benchmarks' SQL pools:
`maxOpenConns: 50`; `maxIdleConns` defaults to `maxOpenConns`, so the pool does not
collapse between fan-outs.

Memory — the peak RSS of the PHP process (`memory_get_peak_usage`) per mode, not per
call.

Each DB and client table carries an `async vs native` column — the signed percent
`(native − async) / native`, with ✅ when the fan-out (`async`) is faster than the native
driver and ❌ when it is slower. In the RoadRunner comparison the `vs RoadRunner` column is
`(SConcur − RoadRunner) / RoadRunner` on throughput (✅ = SConcur higher). The sub-50 ms
rows are noise-sensitive: their sign can flip between runs.

## MongoDB

Median of 10 runs, 100 calls per mode (except `createIndex` — 20). In the median/min/max
cells — `native / sync / async`, ms (min and max are per mode over the 10 runs). Memory —
peak per mode, MB.

| Operation | count | native / sync / async, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB | async vs native |
| --- | ---: | ---: | ---: | ---: | --- | :---: |
| insertOne | 100 | 16.1 / 87.3 / 30.3 | 5.8 / 11.0 / 6.2 | 53.6 / 133 / 37.2 | 6 / 6 / 6 | −88% ❌ |
| insertMany | 100 | 30.3 / 72.3 / 32.2 | 23.7 / 33.3 / 12.4 | 53.4 / 225 / 96.3 | 6 / 6 / 6 | −6% ❌ |
| bulkWrite | 100 | 196 / 465 / 57.5 | 193 / 299 / 46.2 | 320 / 732 / 106 | 6 / 6 / 6 | +71% ✅ |
| updateOne | 100 | 10.6 / 25.7 / 18.8 | 6.6 / 15.7 / 8.0 | 34.7 / 162 / 41.8 | 6 / 6 / 6 | −77% ❌ |
| updateMany | 100 | 2389 / 2423 / 339 | 2291 / 2368 / 317 | 2418 / 2482 / 350 | 6 / 6 / 6 | +86% ✅ |
| deleteOne | 100 | 24.4 / 63.5 / 52.2 | 21.2 / 25.7 / 22.4 | 32.5 / 178 / 94.9 | 6 / 6 / 6 | −114% ❌ |
| findOne | 100 | 11.8 / 75.5 / 29.5 | 7.6 / 18.3 / 5.3 | 74.0 / 133 / 37.6 | 6 / 6 / 6 | −150% ❌ |
| aggregate | 100 | 15.4 / 51.8 / 36.5 | 11.7 / 28.9 / 7.6 | 19.3 / 212 / 50.8 | 6 / 6 / 6 | −137% ❌ |
| count | 100 | 342 / 377 / 51.0 | 330 / 362 / 49.5 | 393 / 606 / 54.3 | 6 / 6 / 6 | +85% ✅ |
| command | 100 | 5.9 / 14.9 / 12.3 | 3.6 / 9.2 / 3.3 | 24.7 / 84.5 / 29.3 | 6 / 6 / 6 | −108% ❌ |
| createIndex | 20 | 1211 / 1117 / 1063 | 1084 / 1042 / 938 | 1271 / 1204 / 1277 | 4 / 4 / 4 | +12% ✅ |

async beats native on the server-bound bulk operations: `count` (51.0 ms vs 342, ~7×),
`updateMany` (339 vs 2389, ~7×), `bulkWrite` (57.5 vs 196, ~3.4×), `createIndex` (1063 vs
1211): 100 concurrent operations load the connection pool and the server cores in
parallel, while native runs them strictly one after another. The cheap single-document
operations stay with native — `insertOne`, `updateOne`, `deleteOne`, `findOne`,
`aggregate`, `insertMany` and the no-op `command`: there the operation is a fast in-memory
one, and spawning a coroutine with the msgpack exchange costs more than it (there is no I/O
wait to overlap — each call is independent and quick). The dataset for the heavy operations
grows within a run (the warm-up and the earlier modes fill the collection before
`updateMany`/`count` run), so their absolutes are comparable only within a row; native goes
first — on the smaller collection.

## MySQL

100 calls per mode, median of 10 runs. Columns as for MongoDB.

| Operation | count | native / sync / async, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB | async vs native |
| --- | ---: | ---: | ---: | ---: | --- | :---: |
| insert | 100 | 631 / 694 / 48.5 | 590 / 617 / 28.9 | 670 / 734 / 62.6 | 4 / 4 / 6 | +92% ✅ |
| selectOne | 100 | 11.2 / 49.9 / 23.1 | 3.2 / 9.4 / 3.2 | 21.0 / 90.8 / 29.4 | 4 / 4 / 6 | −106% ❌ |
| selectMany | 100 | 6.7 / 67.2 / 43.7 | 6.2 / 23.7 / 11.5 | 43.2 / 180 / 54.5 | 6 / 6 / 8 | −552% ❌ |
| count | 100 | 24.6 / 63.8 / 55.6 | 13.2 / 20.9 / 19.2 | 81.0 / 167 / 66.5 | 4 / 4 / 6 | −126% ❌ |
| update | 100 | 655 / 733 / 633 | 602 / 676 / 597 | 702 / 751 / 644 | 4 / 4 / 6 | +3% ✅ |
| delete | 100 | 7.8 / 12.2 / 4.3 | 3.1 / 7.4 / 2.7 | 21.6 / 67.3 / 26.3 | 4 / 4 / 6 | +45% ✅ |
| transaction | 100 | 670 / 832 / 77.9 | 641 / 786 / 34.9 | 705 / 874 / 88.4 | 4 / 4 / 6 | +88% ✅ |

## PostgreSQL

100 calls per mode, median of 10 runs. Columns as above.

| Operation | count | native / sync / async, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB | async vs native |
| --- | ---: | ---: | ---: | ---: | --- | :---: |
| insert | 100 | 131 / 184 / 16.5 | 114 / 165 / 8.2 | 177 / 197 / 27.1 | 4 / 4 / 6 | +87% ✅ |
| selectOne | 100 | 3.4 / 10.2 / 8.7 | 2.7 / 7.9 / 4.1 | 6.1 / 22.0 / 17.2 | 4 / 4 / 6 | −156% ❌ |
| selectMany | 100 | 6.0 / 37.4 / 34.5 | 5.6 / 21.8 / 11.1 | 9.5 / 80.8 / 60.5 | 6 / 6 / 8 | −475% ❌ |
| count | 100 | 3.3 / 9.4 / 6.4 | 2.9 / 6.7 / 3.3 | 8.9 / 20.1 / 17.0 | 4 / 4 / 6 | −94% ❌ |
| update | 100 | 136 / 194 / 155 | 101 / 116 / 140 | 153 / 210 / 163 | 4 / 4 / 6 | −14% ❌ |
| delete | 100 | 3.0 / 7.0 / 4.4 | 2.6 / 5.7 / 3.2 | 4.4 / 12.4 / 14.9 | 4 / 4 / 6 | −47% ❌ |
| transaction | 100 | 169 / 248 / 47.8 | 106 / 147 / 9.7 | 176 / 339 / 57.3 | 6 / 6 / 6 | +72% ✅ |

The disk flips the SQL picture on writes: every committed write pays an fsync,
sequential modes sum it over all 100 calls, and the fan-out overlaps it. async is faster
than native on mysql `insert` by ~13× (48.5 vs 631 ms), `transaction` by ~9× (77.9 vs
670); on pgsql `insert` by ~8× (16.5 vs 131), `transaction` by ~4× (47.8 vs 169). The
exception is `update`: the benchmark hits the same row, the lock on it serializes even
the fan-out (mysql 633 vs 655 — almost no gain). Cheap reads from the cache stay with
native (`selectOne` pgsql 8.7 vs 3.4): there is nothing to overlap, and `sync` shows the
pure per-call price of the boundary. `delete` hits a fixed id: the row is deleted by the
first call, the other 99 are no-ops without a write, so the row is cheap in all modes.

## Clients (HTTP / Socket / WebSocket)

Here the point of the concurrent mode is visible. `native` and `sync` hit the delayed
endpoint sequentially, `async` — fanned out. The `msleep` endpoint holds the connection
for 100 ms; 50 calls in sequence ≈ 5 s, while fanned out ≈ one call.

| Benchmark | count | native, ms | sync, ms | async, ms | Memory n/s/a, MB | async vs native |
| --- | ---: | ---: | ---: | ---: | --- | :---: |
| http-client (`/msleep/100`) | 50 | 5243 | 5222 | 120 | 4 / 4 / 4 | +98% ✅ |
| http-client-download (4 MiB) | 50 | 1105 | 844 | 192 | 4 / 4 / 4 | +83% ✅ |
| socket-client (`msleep:100`) | 50 | 5222 | 5287 | 119 | 4 / 4 / 4 | +98% ✅ |
| ws-client (`msleep:100`) | 50 | 5255 | 5345 | 131 | 4 / 4 / 4 | +98% ✅ |

On I/O latency async gives ~44× (5.2 s → 0.12 s): 50 waits of 100 ms each overlap into
one. `download` downloads a 4 MiB body straight to a file on the Go side (not buffered
in PHP), so memory is flat, and the fan-out still speeds it up ~6×.

## Servers (HTTP / Socket / WebSocket)

A pool of 3 workers (`SO_REUSEPORT`), 100 concurrent requests/connections per run
(throughput — 50 connections × 2000 round-trips). Median of 3 runs, all responses
successful (100/100 or 100000 round-trips).

| Benchmark | Load | elapsed, s | Throughput |
| --- | --- | ---: | --- |
| http-server-io | 100 × `GET /msleep/1000` (1 s async sleep) | 1.03 | — |
| http-server-cpu | 100 × `GET /cpu/100000` (sha256 loop) | 0.76 | — |
| socket-server-io | 100 × `msleep:1000` round-trip | 1.01 | — |
| socket-server-cpu | 100 × `cpu:100000` round-trip | 0.70 | — |
| socket-throughput | 50 conn × 2000 × `ping` | 0.65 | ≈154 000 rt/s |
| ws-server-io | 100 × `msleep:1000` round-trip | 1.01 | — |
| ws-server-cpu | 100 × `cpu:100000` round-trip | 0.72 | — |
| ws-throughput | 50 conn × 2000 × `ping` | 0.84 | ≈120 000 rt/s |

I/O benchmarks: 100 handlers, each sleeps 1 s asynchronously — a single cooperative
process already overlaps all the waits, so the total time ≈ one sleep (~1 s) regardless
of the number of workers. CPU benchmarks: the sha256 loop does not yield control, but the
`SO_REUSEPORT` pool spreads the 100 requests across processes/cores, so 100
«non-yielding» requests complete in ~0.7 s. Throughput measures the pure round-trip price
under concurrency (per-frame PHP↔Go overhead plus framing): ~154k round-trips/s for
socket and ~120k for WebSocket on the pool. Behaviour under sustained load with all
features at once is in [docs/load-testing.md](load-testing.md).

### HTTP throughput: `/` vs `/all`

A separate measurement of sustained throughput under `wrk` (methodology in
[docs/load-testing.md](load-testing.md), the `http-load-stats.sh` script): the script
itself brings up a pool of `nproc − WRK_THREADS` = 12 processes in the `php` container
(cores 0–11 for the servers, wrk — on the rest), `wrk` hits the bridge IP directly
(bypassing NAT). Run parameters: 4 wrk threads, 256 connections, 20 s, 3 runs per handle,
all responses `200`. This is not the same pool of 3 workers as in the table above — here
it is the ceiling of the stack across all the server cores.

- `/` — an empty handle (responds `ok`, no I/O and no noticeable CPU): the pure ceiling
  of the HTTP server and the framework.
- `/all` — on each request a nested `WaitGroup` runs the backend I/O features fanned out:
  MongoDB (insert + findOne), MySQL (`INSERT` + `SELECT 1`), PostgreSQL
  (`INSERT` + `SELECT 1`). The SQL pools in the handle are capped (`maxOpenConns: 5` per
  process): 12–16 processes with unlimited pools would break through PostgreSQL's
  `max_connections = 100`, and some requests would respond 500 «too many clients».

| Handle | Requests/sec | Latency p50 / p90 / p99 | CPU `php` avg / peak | MEM peak |
| --- | ---: | --- | --- | ---: |
| `/` (empty) | ≈67 100 | 3.7 / 6.3 / 8.8 ms | ~1210% / ~1210% | ~256 MiB |
| `/all` (all features) | ≈2 680 | 87 / 165 / 267 ms | ~740% / ~765% | ~287 MiB |

Median of 3 runs; CPU `php` in percent, the pool ceiling is 12 pinned cores (~1200%). On
the empty handle the ceiling hits CPU; `/all` on disk backends already hits not CPU
(~740% of 1200) but fsync: each request is a fan-out of 3 features (6 DB operations, 3 of
them writes) with the pool capped at 5 connections per process.

### Comparison with RoadRunner (native drivers)

To understand the price of the approach, the same two handles were measured on
[RoadRunner](https://roadrunner.dev) 2025.1.15 — a mature Go application server for PHP.
The conditions are identical: the same `php` container (PHP 8.4.15 NTS), 12 workers,
`wrk` 4 threads / 256 connections / 20 s, 3 runs, hitting the bridge IP. The RoadRunner
worker deliberately does not use SConcur — the same functionality is built on native
drivers:

- `/` — the PSR-7 worker returns `200 "ok"`.
- `/all` — the same set of operations as SConcur, but natively and sequentially (the
  worker has no internal concurrency): MongoDB `insertOne`+`findOne`
  (`mongodb/mongodb`), MySQL `INSERT`+`SELECT 1` (`PDO`), PostgreSQL `INSERT`+`SELECT 1`
  (`PDO`), the response — the same JSON map of statuses.

The reference RoadRunner server is committed in `tests/servers/roadrunner/` (config,
PSR-7 worker with native copies of both handles); the `rr` binary is installed when the
`php` container is built, launch is `make rr-serve`. The load measurement on it is
`tests/benchmarks/rr-load-stats.sh` (`make bench-rr-load-stats`, for `/` —
`make bench-rr-load-stats-empty`): the same harness as `http-load-stats.sh` (core
pinning, wrk over the bridge IP, CPU/memory sampling and worker RSS), so the numbers are
directly comparable at the same number of workers.

Honesty check: under load both stacks actually did the work (hundreds of thousands of
inserts into MongoDB/MySQL/PostgreSQL per run, all responses `200` — since 2026-07-04 a
failed feature turns the response into a 500, and there were none) — rather than
responding for nothing.

The third `/all` row is the `/all-native` handle of the SConcur server: an exact copy of
the RoadRunner worker (the same native drivers, sequentially, without SConcur features)
inside the SConcur server. It isolates the server/transport layer from the driver stack.

| Handle | Server | Requests/sec | p50 / p90 / p99 | CPU avg / peak | MEM peak | vs RoadRunner |
| --- | --- | ---: | --- | --- | ---: | :---: |
| `/` (empty) | SConcur | ≈67 100 | 3.7 / 6.3 / 8.8 ms | ~1210% / ~1210% | ~256 MiB | +42% ✅ |
| `/` (empty) | RoadRunner | ≈47 100 | 5.3 / 5.9 / 6.7 ms | ~1060% / ~1075% | ~230 MiB | — |
| `/all` | SConcur (fan-out) | ≈2 680 | 87 / 165 / 267 ms | ~740% / ~765% | ~287 MiB | +483% ✅ |
| `/all` | RoadRunner (sequential) | ≈460 | 561 / 589 / 603 ms | ~160% / ~175% | ~237 MiB | — |
| `/all-native` | SConcur (native drivers, sequential) | ≈457 | 556 / 745 / 832 ms | ~140% / ~160% | ~265 MiB | — |

On the empty handle SConcur is ~1.4× faster: the price of a request across the PHP↔Go
boundary is low (tasks go into Go off the scheduler stack, the result channel is
buffered), while RoadRunner pays the IPC hop proxy → worker on every request.

On `/all` with disk backends the picture reversed radically: **SConcur is ~6× faster than
RoadRunner** (≈2 680 vs ≈460 rps). The reason is fsync: 3 writes per request in the
sequential worker fold into a chain of disk commits (p50 ≈ 0.56 s, CPU idles at ~160%),
and all 12 workers hit that chain at once. SConcur's fan-out overlaps those same fsyncs
onto each other and onto neighbouring requests — a cooperative worker serves dozens of
requests parked on I/O while the disk works.

The `/all-native` row confirms it is not about the server layer: the same native
sequential code inside the SConcur server gives the same ≈457 rps as RoadRunner (≈460),
with the same latencies — SConcur's server/transport does not lose to the native stack,
and the sixfold gap is created exactly by the execution model (sequential fsyncs vs ones
overlapped by the fan-out).

The applicability boundary of the fan-out runs along the price of waiting for an
operation. Where there is none (data in memory or a hot cache over local loopback), the
sequential native code has nothing to overlap and it wins — this is visible in the cheap
read rows in the DB tables. As soon as an operation gains a delay — an fsync on disk, as
here, or a network RTT — sequential code sums it, the fan-out overlaps it, and the
advantage goes to SConcur.

## Conclusions

- A single call through SConcur (`sync`) is always more expensive than the native
  driver — the price of conversion at the PHP↔Go boundary (MessagePack + BSON for MongoDB
  + cgo). It is most noticeable on cheap reads from the cache, where the query itself is
  almost instant.
- The fan-out gain (`async`) is directly proportional to the price of waiting for an
  operation — it overlaps it rather than summing it. On disk backends this is first of all
  fsync: async beats native on writes — mysql `insert` ~13× and `transaction` ~9×, pgsql
  `insert` ~8× and `transaction` ~4×, the server-bound mongo `count`/`updateMany` ~7× and
  `bulkWrite` ~3×. What stays with native are the cheap in-memory reads (`selectOne`,
  `findOne`, `count`), the single-document mongo operations (`insertOne`, `updateOne`,
  `deleteOne`, `aggregate`, `command`) and lock-bound writes to a single row (`update` of a
  fixed id — the lock serializes even the fan-out): there the boundary overhead exceeds the
  operation, with no I/O wait to overlap.
- The connection pool is decisive: a cold pool cost the fan-out 3–15× (opening connections
  inside the measurement), which is why the methodology includes a warm-up, and
  `maxIdleConns` defaults to `maxOpenConns` — otherwise Go keeps 2 idle and the pool
  collapses between fan-outs.
- With network latency the picture is the same as with fsync: on clients with a 100 ms
  wait the fan-out gives ~44×.
- The PHP↔Go boundary on the fan-out is cheap (tasks go into Go off the scheduler stack,
  the result channel is buffered): socket/ws server throughput — ~120–154k round-trips/s,
  the empty HTTP handle — ~67k rps (~1.4× faster than RoadRunner).
- Comparison with RoadRunner on disk backends: `/all` fanned out — ~6× faster than the
  sequential native worker (≈2 680 vs ≈460 rps); `/all-native` shows that SConcur's server
  layer does not lose to the native stack (≈457 ≈ RoadRunner). The fan-out only loses
  where operations have no real I/O price (memory/hot cache over loopback — the cheap read
  rows in the DB tables).
- async memory stays 2–4 MB above the synchronous path (live coroutine fibers) and grows
  with the result size (`selectMany` — 8 MB), but stays modest.
