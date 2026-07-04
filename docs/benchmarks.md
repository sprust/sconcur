English | [Русский](benchmarks.ru.md)

# Feature benchmarks

Performance measurements for each feature (except `Sleeper`): what a call across
the PHP↔Go boundary costs compared to the native driver, and what fanning out a
concurrent run gains. The numbers are a reference, not a guarantee: they were taken
on a specific machine and depend on hardware, DB settings and load.

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

All numbers were taken on 2026-07-04 on an idle machine.

## Conversion overhead (the PHP↔Go boundary)

Every feature call crosses the PHP↔Go boundary, and the data at that boundary is
converted: arguments are packed into MessagePack (msgpack-tagged DTOs,
`Transport/MessagePackTransport`), the result is unpacked back; for MongoDB documents
additionally go through BSON (de)serialization via `ext-mongodb`. This is a fixed CPU
price per operation, on top of the cgo call and goroutine dispatch.

On cheap reads from the DB cache this overhead is visible directly as the gap between
`native` and `sync` (both run sequentially, but `sync` goes through Go): for example,
`pgsql-selectOne` 3.2 → 8.7 ms over 100 calls, `mongodb-findOne` 13.7 → 62.3 ms. The
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

Each benchmark was run 3 times; the tables show the median. The number of calls
(`count`) per mode: 100 for DB operations, 50 for client I/O benchmarks (each call is a
100 ms sleep on the server), 20 for `mongodb-createIndex`. About that 20 specifically:
`sync` and `async` each create `count` indexes on the shared `u-test.benchmark`
collection, and MongoDB's limit is 64 indexes per collection; 3×20 = 60 fits, 3×100 does
not. All runs are `make bench-<name> c=<count>`.

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

## MongoDB

Median of 3 runs, 100 calls per mode (except `createIndex` — 20). In the time cell —
`native / sync / async`, ms. Memory — peak per mode, MB.

| Operation | count | native / sync / async, ms | Memory n/s/a, MB |
| --- | ---: | ---: | --- |
| insertOne | 100 | 10.4 / 46.3 / 9.1 | 6 / 6 / 6 |
| insertMany | 100 | 31.4 / 190 / 73.3 | 6 / 6 / 6 |
| bulkWrite | 100 | 7268 / 7620 / 1093 | 6 / 6 / 6 |
| updateOne | 100 | 10.2 / 16.2 / 8.1 | 6 / 6 / 6 |
| updateMany | 100 | 7350 / 7250 / 1060 | 6 / 6 / 6 |
| deleteOne | 100 | 18.9 / 39.6 / 30.6 | 6 / 6 / 6 |
| findOne | 100 | 13.7 / 62.3 / 33.8 | 6 / 6 / 6 |
| aggregate | 100 | 12.8 / 34.9 / 17.7 | 6 / 6 / 6 |
| count | 100 | 1228 / 1283 / 174 | 6 / 6 / 6 |
| command | 100 | 6.3 / 16.9 / 18.0 | 6 / 6 / 6 |
| createIndex | 20 | 1524 / 1492 / 1173 | 4 / 4 / 4 |

async beats native on point writes and all server-bound operations: `insertOne`
(9.1 ms vs 10.4), `updateOne` (8.1 vs 10.2), `count` (174 vs 1228, ~7×), `bulkWrite`
(1093 vs 7268, ~6.6×), `updateMany` (1060 vs 7350, ~7×), `createIndex` (1173 vs 1524):
100 concurrent operations load the connection pool and the server cores in parallel,
while native runs them strictly one after another. What stays with native are the cheap
reads and no-op commands (`findOne`, `command`, `aggregate`, `deleteOne`): there the
operation itself is a read from the cache, and spawning a coroutine with the msgpack
exchange costs more than it. The dataset for the heavy operations (`bulkWrite`,
`updateMany`, `count`) grows over the run (the warm-up and the previous modes fill the
collection), so their absolutes are comparable only within a row; native goes first — on
the smaller collection.

## MySQL

100 calls per mode, median of 3 runs. Columns as for MongoDB.

| Operation | count | native / sync / async, ms | Memory n/s/a, MB |
| --- | ---: | ---: | --- |
| insert | 100 | 647 / 668 / 54.5 | 4 / 4 / 6 |
| selectOne | 100 | 19.9 / 70.9 / 23.7 | 6 / 6 / 6 |
| selectMany | 100 | 6.3 / 40.1 / 52.6 | 6 / 6 / 8 |
| count | 100 | 28.9 / 80.3 / 59.1 | 6 / 6 / 6 |
| update | 100 | 658 / 673 / 621 | 4 / 4 / 6 |
| delete | 100 | 9.8 / 27.2 / 17.9 | 4 / 4 / 6 |
| transaction | 100 | 701 / 832 / 76.2 | 4 / 4 / 6 |

## PostgreSQL

100 calls per mode, median of 3 runs. Columns as above.

| Operation | count | native / sync / async, ms | Memory n/s/a, MB |
| --- | ---: | ---: | --- |
| insert | 100 | 129 / 178 / 24.9 | 4 / 4 / 6 |
| selectOne | 100 | 3.2 / 8.7 / 7.8 | 4 / 4 / 6 |
| selectMany | 100 | 5.9 / 36.7 / 44.1 | 6 / 6 / 8 |
| count | 100 | 3.1 / 10.5 / 7.8 | 4 / 4 / 6 |
| update | 100 | 126 / 176 / 140 | 4 / 4 / 6 |
| delete | 100 | 2.7 / 12.7 / 9.2 | 4 / 4 / 6 |
| transaction | 100 | 154 / 275 / 49.7 | 6 / 6 / 6 |

The disk flips the SQL picture on writes: every committed write pays an fsync,
sequential modes sum it over all 100 calls, and the fan-out overlaps it. async is faster
than native on mysql `insert` by ~12× (54.5 vs 647 ms), `transaction` by ~9× (76.2 vs
701); on pgsql `insert` by ~5× (24.9 vs 129), `transaction` by ~3× (49.7 vs 154). The
exception is `update`: the benchmark hits the same row, the lock on it serializes even
the fan-out (mysql 621 vs 658 — almost no gain). Cheap reads from the cache stay with
native (`selectOne` pgsql 7.8 vs 3.2): there is nothing to overlap, and `sync` shows the
pure per-call price of the boundary. `delete` hits a fixed id: the row is deleted by the
first call, the other 99 are no-ops without a write, so the row is cheap in all modes.

## Clients (HTTP / Socket / WebSocket)

Here the point of the concurrent mode is visible. `native` and `sync` hit the delayed
endpoint sequentially, `async` — fanned out. The `msleep` endpoint holds the connection
for 100 ms; 50 calls in sequence ≈ 5 s, while fanned out ≈ one call.

| Benchmark | count | native, ms | sync, ms | async, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | ---: | --- |
| http-client (`/msleep/100`) | 50 | 5247 | 5228 | 112 | 4 / 4 / 4 |
| http-client-download (4 MiB) | 50 | 1040 | 786 | 197 | 4 / 4 / 4 |
| socket-client (`msleep:100`) | 50 | 5229 | 5296 | 118 | 4 / 4 / 4 |
| ws-client (`msleep:100`) | 50 | 5256 | 5355 | 119 | 4 / 4 / 4 |

On I/O latency async gives ~45× (5.2 s → 0.12 s): 50 waits of 100 ms each overlap into
one. `download` downloads a 4 MiB body straight to a file on the Go side (not buffered
in PHP), so memory is flat, and the fan-out still speeds it up ~5×.

## Servers (HTTP / Socket / WebSocket)

A pool of 3 workers (`SO_REUSEPORT`), 100 concurrent requests/connections per run
(throughput — 50 connections × 2000 round-trips). Median of 3 runs, all responses
successful (100/100 or 100000 round-trips).

| Benchmark | Load | elapsed, s | Throughput |
| --- | --- | ---: | --- |
| http-server-io | 100 × `GET /msleep/1000` (1 s async sleep) | 1.06 | — |
| http-server-cpu | 100 × `GET /cpu/100000` (sha256 loop) | 0.68 | — |
| socket-server-io | 100 × `msleep:1000` round-trip | 1.01 | — |
| socket-server-cpu | 100 × `cpu:100000` round-trip | 0.70 | — |
| socket-throughput | 50 conn × 2000 × `ping` | 0.67 | ≈150 000 rt/s |
| ws-server-io | 100 × `msleep:1000` round-trip | 1.01 | — |
| ws-server-cpu | 100 × `cpu:100000` round-trip | 0.68 | — |
| ws-throughput | 50 conn × 2000 × `ping` | 0.89 | ≈113 000 rt/s |

I/O benchmarks: 100 handlers, each sleeps 1 s asynchronously — a single cooperative
process already overlaps all the waits, so the total time ≈ one sleep (~1 s) regardless
of the number of workers. CPU benchmarks: the sha256 loop does not yield control, but the
`SO_REUSEPORT` pool spreads the 100 requests across processes/cores, so 100
«non-yielding» requests complete in ~0.7 s. Throughput measures the pure round-trip price
under concurrency (per-frame PHP↔Go overhead plus framing): ~150k round-trips/s for
socket and ~113k for WebSocket on the pool. Behaviour under sustained load with all
features at once is in [docs/load-testing.md](load-testing.md).

### HTTP throughput: `/` vs `/all`

A separate measurement of sustained throughput under `wrk` (methodology in
[docs/load-testing.md](load-testing.md), the `http-load-stats.sh` script): the script
itself brings up a pool of `nproc − WRK_THREADS` = 12 processes in the `php` container
(cores 0–11 for the servers, wrk — on the rest), `wrk` hits the bridge IP directly
(bypassing NAT). Run parameters: 4 wrk threads, 256 connections, 15 s, 3 runs per handle,
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
| `/` (empty) | ≈67 600 | 3.7 / 6.3 / 8.4 ms | ~1210% / ~1210% | ~235 MiB |
| `/all` (all features) | ≈2 680 | 87 / 161 / 263 ms | ~740% / ~760% | ~265 MiB |

Median of 3 runs; CPU `php` in percent, the pool ceiling is 12 pinned cores (~1200%). On
the empty handle the ceiling hits CPU; `/all` on disk backends already hits not CPU
(~740% of 1200) but fsync: each request is a fan-out of 3 features (6 DB operations, 4 of
them writes) with the pool capped at 5 connections per process.

### Comparison with RoadRunner (native drivers)

To understand the price of the approach, the same two handles were measured on
[RoadRunner](https://roadrunner.dev) 2025.1.15 — a mature Go application server for PHP.
The conditions are identical: the same `php` container (PHP 8.4.15 NTS), 12 workers,
`wrk` 4 threads / 256 connections / 15 s, 3 runs, hitting the bridge IP. The RoadRunner
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

| Handle | Server | Requests/sec | p50 / p90 / p99 | CPU avg / peak | MEM peak |
| --- | --- | ---: | --- | --- | ---: |
| `/` (empty) | SConcur | ≈67 600 | 3.7 / 6.3 / 8.4 ms | ~1210% / ~1210% | ~235 MiB |
| `/` (empty) | RoadRunner | ≈46 900 | 5.3 / 5.9 / 6.9 ms | ~1050% / ~1070% | ~205 MiB |
| `/all` | SConcur (fan-out) | ≈2 680 | 87 / 161 / 263 ms | ~740% / ~760% | ~265 MiB |
| `/all` | RoadRunner (sequential) | ≈446 | 576 / 611 / 650 ms | ~170% / ~195% | ~212 MiB |
| `/all-native` | SConcur (native drivers, sequential) | ≈451 | 557 / 720 / 867 ms | ~140% / ~160% | ~244 MiB |

On the empty handle SConcur is ~1.4× faster: the price of a request across the PHP↔Go
boundary is low (tasks go into Go off the scheduler stack, the result channel is
buffered), while RoadRunner pays the IPC hop proxy → worker on every request.

On `/all` with disk backends the picture reversed radically: **SConcur is ~6× faster than
RoadRunner** (≈2 680 vs ≈446 rps). The reason is fsync: 4 writes per request in the
sequential worker fold into a chain of disk commits (p50 ≈ 0.6 s, CPU idles at ~170%),
and all 12 workers hit that chain at once. SConcur's fan-out overlaps those same fsyncs
onto each other and onto neighbouring requests — a cooperative worker serves dozens of
requests parked on I/O while the disk works.

The `/all-native` row confirms it is not about the server layer: the same native
sequential code inside the SConcur server gives the same ≈451 rps as RoadRunner (≈446),
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
  fsync: async beats native on writes — mysql `insert` ~12× and `transaction` ~9×, pgsql
  `insert` ~5×, mongo `insertOne`/`updateOne`, the server-bound
  `count`/`bulkWrite`/`updateMany` ~7×. What stays with native are the cheap reads from
  the cache (`findOne`, `selectOne`, `command`) and lock-bound writes to a single row
  (`update` of a fixed id — the lock serializes even the fan-out).
- The connection pool is decisive: a cold pool cost the fan-out 3–15× (opening connections
  inside the measurement), which is why the methodology includes a warm-up, and
  `maxIdleConns` defaults to `maxOpenConns` — otherwise Go keeps 2 idle and the pool
  collapses between fan-outs.
- With network latency the picture is the same as with fsync: on clients with a 100 ms
  wait the fan-out gives ~45×.
- The PHP↔Go boundary on the fan-out is cheap (tasks go into Go off the scheduler stack,
  the result channel is buffered): socket/ws server throughput — ~113–150k round-trips/s,
  the empty HTTP handle — ~68k rps (~1.4× faster than RoadRunner).
- Comparison with RoadRunner on disk backends: `/all` fanned out — ~6× faster than the
  sequential native worker (≈2 680 vs ≈446 rps); `/all-native` shows that SConcur's server
  layer does not lose to the native stack (≈451 ≈ RoadRunner). The fan-out only loses
  where operations have no real I/O price (memory/hot cache over loopback — the cheap read
  rows in the DB tables).
- async memory stays 2–4 MB above the synchronous path (live coroutine fibers) and grows
  with the result size (`selectMany` — 8 MB), but stays modest.
