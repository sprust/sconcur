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

- [Is SConcur for you?](#is-sconcur-for-you)
- [Environment](#environment)
- [Conversion overhead (the PHP↔Go boundary)](#conversion-overhead-the-phpgo-boundary)
- [Methodology](#methodology)
- [MongoDB](#mongodb)
- [MySQL](#mysql)
- [PostgreSQL](#postgresql)
- [Payload size](#payload-size)
- [Clients (HTTP / Socket / WebSocket)](#clients-http--socket--websocket)
- [Servers (HTTP / Socket / WebSocket)](#servers-http--socket--websocket)
  - [HTTP throughput: `/` vs `/all`](#http-throughput--vs-all)
  - [Comparison with RoadRunner (native drivers)](#comparison-with-roadrunner-native-drivers)
- [Conclusions](#conclusions)

## Is SConcur for you?

Match your workload against the measured effects; every number links to the section
that measured it.

| Your workload | Measured effect | Verdict |
| --- | --- | :---: |
| A high-concurrency I/O-bound HTTP/WS service | [~6× RoadRunner's rps](#comparison-with-roadrunner-native-drivers) on the same hardware; the same load takes ~5× less memory than RoadRunner, ~15–30× less than php-fpm — [positioning](positioning.md) | ✅ |
| A request/job fans out into several DB or network operations | SQL writes [~3–18×](#mysql) faster, heavy reads [~2–7.5×](#mongodb), network waits [~44×](#clients-http--socket--websocket) | ✅ |
| MongoDB with concurrency | the only concurrent MongoDB path in PHP — [tables](#mongodb) | ✅ |
| Cheap point queries, one at a time (as a library) | slower than the native driver: the [boundary](#conversion-overhead-the-phpgo-boundary) costs more than the query itself | ❌ |
| Megabyte payloads per operation | ~1.5–2.3 ms per MB each way, and a wide fan of large results holds them all in RAM — [payload size](#payload-size) | ❌ |
| CPU-bound handlers | no gain: PHP stays single-threaded, a busy handler blocks the process — [servers](#servers-http--socket--websocket) | ❌ |

The point-query ❌ is about the call price inside one process — a library call compared
to the native driver. As a server SConcur wins even with many small operations: every
handler runs in its own coroutine, so sequential feature calls overlap across requests
with no `WaitGroup` — the sequential `/all-nowg` handle holds ≈2 570 rps against
RoadRunner's ≈460 on the same operations
([load testing](load-testing.md#fan-out-vs-sequential-calls-all-vs-all-nowg)). Only on
microsecond cache hits does the edge reduce to the server layer itself (~1.4× on the
empty handle) — it never turns into a loss.

Three rules behind the table:

- The fan-out (`async`) wins wherever an operation has a real price — an fsync per
  write, server-side work over the dataset, a network wait. The higher that price, the
  bigger the win.
- Cheap point operations stay with the native driver: the PHP↔Go boundary costs more
  than the operation itself, and there is nothing to overlap.
- A single call through SConcur is always more expensive than the native one (the
  boundary conversion) — the gain comes from concurrency only.

How SConcur relates to php-fpm and RoadRunner as an execution model — resources,
limits, when to choose what — is a separate doc: [positioning](positioning.md).

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

The client and server numbers were taken on 2026-07-22, the DB numbers on
2026-07-23 (with the cold-dataset methodology below), on an idle machine.

## Conversion overhead (the PHP↔Go boundary)

Every feature call crosses the PHP↔Go boundary, and the data at that boundary is
converted: arguments are packed into MessagePack (msgpack-tagged DTOs,
`Transport/MessagePackTransport`), the result is unpacked back; for MongoDB documents
additionally go through BSON (de)serialization via `ext-mongodb`. This is a fixed CPU
price per operation, on top of the cgo call and goroutine dispatch.

On cheap reads from the DB cache this overhead is visible directly as the gap between
`native` and `sync` (both run sequentially, but `sync` goes through Go): for example,
`pgsql-selectOne` 3.8 → 10.2 ms over 100 calls, `mysql-selectOne` 3.9 → 15.2 ms. The
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

Each DB benchmark (MongoDB, MySQL, PostgreSQL) was run 5 times and each client/server
benchmark 3 times; the tables show the median. Every DB run starts cold: before the
measurement the benchmark table/collection is dropped and reseeded to a dataset of
100 000 rows/documents by the native driver in batches (multi-row `INSERT`s in one
transaction for SQL, ids 1..N, the PG sequence advanced past them; chunked `insertMany`
for MongoDB with an integer `_id` 1..N and fields matching the benchmark filters). The
runs are fully independent — nothing accumulates between runs or between modes.

Point operations work on distinct ids: each mode gets its own id range inside the seeded
dataset, and every call (warm-up included) hits its own row/document. So
`selectOne`/`findOne`/`updateOne`/`update`/`deleteOne`/`delete` never share a hot row,
every delete actually removes a row, and a shared-row lock cannot serialize the fan-out.
`selectMany` reads a 100-row window sliding over the mode's range;
`insert`/`insertOne`/`insertMany`/`transaction` insert into the table already holding the
dataset; `count`/`updateMany`/`aggregate` work over the whole dataset (the seeded
documents match their filters).

The number of calls (`count`) per mode: 100 by default, 50 for client I/O benchmarks
(each call is a 100 ms sleep on the server). Three MongoDB benchmarks are bounded by the
operation's nature: `createIndex` 20 (MongoDB caps a collection at 64 indexes, 3×20 = 60
per run fits), `bulkWrite` 20 (the bulk filters are unindexed — each call scans the
dataset several times) and `updateMany` 10 (each call rewrites all 100 000 documents).
Single runs are `make bench-<name> c=<count>`; the whole DB session (5 cold runs per
benchmark plus the median/min/max aggregation) is `make bench-db-runs`
(`tests/benchmarks/db-bench-runs.sh`).

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

The tables carry an `async vs native` comparison — the signed percent
`(native − async) / native`, with ✅ when the fan-out (`async`) is faster than the native
driver and ❌ when it is slower. In the client tables it is a separate column; in the DB
tables the percent sits in parentheses right in the cell it refers to — the median, min
and max columns each carry their own (computed on the medians and on the per-mode min and
max values over the runs), which shows the spread of the comparison across runs. In the
RoadRunner comparison the `vs RoadRunner` column is
`(SConcur − RoadRunner) / RoadRunner` on throughput (✅ = SConcur higher). The sub-50 ms
rows are noise-sensitive: their sign can flip between runs — a sign flip between the
`min` and `max` values of a row marks exactly that.

## MongoDB

In one line: fan out what makes the server work — `count` ~7×, `bulkWrite` ~6.5×,
`updateMany` ~5.5×, `createIndex` +26%; single-document operations stay with the
native driver.

Median of 5 runs against a cold dataset of 100 000 documents; 100 calls per mode (except
`bulkWrite` — 20, `createIndex` — 20 and `updateMany` — 10). In the median/min/max
cells — `native / sync / async`, ms (min and max are per mode over the 5 runs); in
parentheses — the `async vs native` percent for that cell. Memory — peak per mode, MB.

| Operation | count | native / sync / async, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | ---: | --- |
| insertOne | 100 | 7.7 / 43.5 / 23.6 (−207% ❌) | 5.2 / 11.3 / 5.1 (+3% ✅) | 14.1 / 96.9 / 31.5 (−123% ❌) | 10 / 10 / 10 |
| insertMany | 100 | 30.6 / 123 / 66.9 (−119% ❌) | 24.3 / 48.7 / 55.1 (−127% ❌) | 37.7 / 149 / 88.5 (−135% ❌) | 10 / 10 / 10 |
| bulkWrite | 20 | 3458 / 3559 / 536 (+85% ✅) | 3425 / 3461 / 521 (+85% ✅) | 3555 / 3656 / 537 (+85% ✅) | 8 / 8 / 8 |
| updateOne | 100 | 8.1 / 22.9 / 15.3 (−88% ❌) | 6.2 / 11.6 / 10.8 (−73% ❌) | 22.4 / 129 / 32.5 (−45% ❌) | 10 / 10 / 10 |
| updateMany | 10 | 1741 / 1695 / 317 (+82% ✅) | 1695 / 1664 / 310 (+82% ✅) | 1785 / 1729 / 323 (+82% ✅) | 8 / 8 / 8 |
| deleteOne | 100 | 6.9 / 12.9 / 9.3 (−36% ❌) | 5.6 / 12.7 / 8.9 (−58% ❌) | 31.5 / 46.5 / 36.1 (−15% ❌) | 10 / 10 / 10 |
| findOne | 100 | 9.2 / 13.9 / 10.0 (−8% ❌) | 6.1 / 9.6 / 6.3 (−4% ❌) | 20.1 / 61.2 / 28.0 (−39% ❌) | 10 / 10 / 10 |
| aggregate | 100 | 16.0 / 84.0 / 44.8 (−179% ❌) | 13.1 / 29.1 / 23.0 (−75% ❌) | 18.2 / 143 / 48.9 (−169% ❌) | 10 / 10 / 10 |
| count | 100 | 2282 / 2388 / 327 (+86% ✅) | 2242 / 2324 / 320 (+86% ✅) | 2295 / 2452 / 336 (+85% ✅) | 10 / 10 / 10 |
| command | 100 | 8.9 / 23.6 / 24.5 (−176% ❌) | 5.5 / 15.2 / 3.8 (+31% ✅) | 19.7 / 75.1 / 29.2 (−48% ❌) | 6 / 6 / 6 |
| createIndex | 20 | 2194 / 2209 / 1620 (+26% ✅) | 2128 / 2150 / 1571 (+26% ✅) | 2225 / 2217 / 1796 (+19% ✅) | 8 / 8 / 8 |

async beats native where a call makes the server chew through the dataset: `count`
(327 ms vs 2282, ~7×; every call counts all 100k documents), `updateMany` (317 vs 1741,
~5.5×; every call rewrites all 100k), `bulkWrite` (536 vs 3458, ~6.5×; the unindexed bulk
filters scan the collection several times per call) and `createIndex` (1620 vs 2194;
every index is built over the 100k documents) — heavy server-side work overlaps across
the connection pool and the server cores instead of queuing one call after another. The
point single-document operations stay with native — `insertOne`, `updateOne`,
`deleteOne`, `findOne` (each call hits its own `_id`), `insertMany`, `aggregate`
(`$limit 30` stops the scan early) and the no-op `command`: MongoDB pays no per-operation
fsync on the default write concern, so even a write is a fast in-memory operation with no
I/O wait to overlap, and the conversion at the boundary costs more than the operation
itself.

## MySQL

In one line: every disk write fanned out is ~10–16× faster (the fsyncs overlap),
`transaction` ~10×, `count` ~2×; cheap reads stay with PDO.

Median of 5 runs against a cold dataset of 100 000 rows, 100 calls per mode. Columns as
for MongoDB.

| Operation | count | native / sync / async, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | ---: | --- |
| insert | 100 | 642 / 718 / 45.8 (+93% ✅) | 608 / 669 / 40.7 (+93% ✅) | 653 / 723 / 54.2 (+92% ✅) | 6 / 6 / 6 |
| selectOne | 100 | 3.9 / 15.2 / 4.3 (−11% ❌) | 3.5 / 8.6 / 3.5 (0%) | 25.2 / 52.3 / 23.2 (+8% ✅) | 6 / 6 / 6 |
| selectMany | 100 | 8.5 / 72.7 / 54.8 (−546% ❌) | 7.7 / 30.3 / 19.0 (−147% ❌) | 23.8 / 150 / 57.2 (−141% ❌) | 6 / 6 / 8 |
| count | 100 | 147 / 164 / 76.2 (+48% ✅) | 142 / 150 / 75.5 (+47% ✅) | 166 / 167 / 93.8 (+44% ✅) | 6 / 6 / 6 |
| update | 100 | 624 / 678 / 41.1 (+93% ✅) | 609 / 667 / 27.5 (+95% ✅) | 660 / 725 / 42.7 (+94% ✅) | 6 / 6 / 6 |
| delete | 100 | 637 / 694 / 40.5 (+94% ✅) | 617 / 678 / 28.8 (+95% ✅) | 642 / 701 / 44.1 (+93% ✅) | 6 / 6 / 6 |
| transaction | 100 | 666 / 783 / 69.1 (+90% ✅) | 614 / 775 / 58.9 (+90% ✅) | 686 / 871 / 72.5 (+89% ✅) | 6 / 6 / 6 |

## PostgreSQL

In one line: writes fanned out are ~3–18× faster, `count` ~7.5×; point reads stay
with PDO.

Median of 5 runs against a cold dataset of 100 000 rows, 100 calls per mode. Columns as
above.

| Operation | count | native / sync / async, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | ---: | --- |
| insert | 100 | 132 / 165 / 23.9 (+82% ✅) | 101 / 122 / 6.4 (+94% ✅) | 142 / 206 / 27.4 (+81% ✅) | 6 / 6 / 6 |
| selectOne | 100 | 3.8 / 10.2 / 6.0 (−57% ❌) | 3.1 / 8.3 / 4.5 (−46% ❌) | 5.3 / 14.4 / 11.8 (−123% ❌) | 6 / 6 / 6 |
| selectMany | 100 | 8.8 / 55.6 / 47.3 (−436% ❌) | 6.9 / 28.0 / 12.7 (−84% ❌) | 12.7 / 92.1 / 50.6 (−298% ❌) | 6 / 6 / 8 |
| count | 100 | 314 / 314 / 41.6 (+87% ✅) | 284 / 305 / 40.4 (+86% ✅) | 359 / 337 / 45.2 (+87% ✅) | 6 / 6 / 6 |
| update | 100 | 126 / 162 / 7.0 (+94% ✅) | 102 / 112 / 5.9 (+94% ✅) | 137 / 190 / 34.5 (+75% ✅) | 6 / 6 / 6 |
| delete | 100 | 132 / 176 / 22.8 (+83% ✅) | 121 / 129 / 5.6 (+95% ✅) | 144 / 189 / 30.5 (+79% ✅) | 6 / 6 / 6 |
| transaction | 100 | 151 / 306 / 47.5 (+69% ✅) | 119 / 188 / 42.0 (+65% ✅) | 170 / 350 / 58.1 (+66% ✅) | 6 / 6 / 6 |

The disk flips the SQL picture on writes: every committed write pays an fsync, the
sequential modes sum it over all 100 calls, and the fan-out overlaps it. async is faster
than native on mysql `insert` ~14× (45.8 vs 642 ms), `update` ~15× (41.1 vs 624),
`delete` ~16× (40.5 vs 637), `transaction` ~10× (69.1 vs 666); on pgsql `insert` ~5.5×
(23.9 vs 132), `update` ~18× (7.0 vs 126), `delete` ~6× (22.8 vs 132), `transaction` ~3×
(47.5 vs 151). With every call hitting its own row, `update` and `delete` behave exactly
like `insert` — the same-row artifacts of the old methodology (a lock serializing the
fan-out, no-op deletes of an already-deleted id) are gone by construction. `count` over
the 100 000-row table also goes to async: mysql 76.2 vs 147 (~2×), pgsql 41.6 vs 314
(~7.5×; PostgreSQL's `COUNT(*)` scans the heap) — a read that makes the server do real
work is worth fanning out too. What stays with native are the cheap point reads from the
cache (`selectOne`: pgsql 6.0 vs 3.8, mysql 4.3 vs 3.9 — almost even) and `selectMany`
(100 rows per call — the row-set conversion at the boundary dominates: mysql 54.8 vs 8.5,
pgsql 47.3 vs 8.8).

## Payload size

In one line: up to ~64 KB per operation the boundary tax is negligible; megabyte
blobs belong to the native driver, and a wide fan of large results pays
RSS ≈ fan width × payload.

How the numbers scale with the size of the data one operation carries. On the async
path the payload crosses the PHP↔Go boundary twice — as MessagePack-packed bindings (or
a BSON document) on the way in and as the result buffer on the way out — so the boundary
tax grows with the payload while the fan-out gain does not.

Six dedicated benches (`tests/benchmarks/{mongodb,mysql,pgsql}-payload-{write,read}.php`)
move an incompressible base64 payload of `SCONCUR_BENCH_PAYLOAD_BYTES` bytes per call
(default 1024). Write inserts its own row/document per call into a fresh
table/collection (per-mode id ranges); read re-reads one hot row per mode, so the DB
serves it from cache and the measured path is transfer + decode, not disk. Single runs:
`make bench-mysql-payloadWrite p=65536 c=100` (`p` — payload bytes, `c` — calls).

The tables below: median of 5 cold runs on disk-backed backends, columns as in the DB
tables above; 1 MB uses 50 calls per mode (the async fan holds every in-flight result in
memory at once — see the reading below).

Payload 1 KB, 100 calls:

| Operation | median n/s/a, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | --- |
| mongodb insertOne | 20.6 / 81.9 / 32.5 (−58% ❌) | 10.7 / 15.4 / 8.2 (+23% ✅) | 27.9 / 129 / 36.5 (−31% ❌) | 6 / 6 / 6 |
| mongodb findOne | 13.8 / 48.3 / 28.5 (−106% ❌) | 6.7 / 11.1 / 15.2 (−127% ❌) | 25.8 / 73.2 / 32.6 (−26% ❌) | 6 / 6 / 6 |
| mysql insert | 753 / 812 / 125 (+83% ✅) | 704 / 787 / 119 (+83% ✅) | 762 / 874 / 137 (+82% ✅) | 6 / 6 / 6 |
| mysql selectOne | 8.3 / 17.9 / 16.9 (−102% ❌) | 3.2 / 12.6 / 3.3 (−5% ❌) | 26.2 / 52.1 / 26.1 (0%) | 6 / 6 / 6 |
| pgsql insert | 134 / 183 / 19.1 (+86% ✅) | 105 / 124 / 7.3 (+93% ✅) | 141 / 200 / 25.6 (+82% ✅) | 4 / 4 / 6 |
| pgsql selectOne | 3.2 / 9.9 / 7.0 (−122% ❌) | 2.9 / 8.4 / 4.2 (−46% ❌) | 9.5 / 23.5 / 18.9 (−99% ❌) | 6 / 6 / 6 |

Payload 64 KB, 100 calls:

| Operation | median n/s/a, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | --- |
| mongodb insertOne | 21.5 / 102 / 45.7 (−112% ❌) | 17.6 / 41.9 / 14.2 (+19% ✅) | 46.8 / 246 / 74.3 (−59% ❌) | 6 / 6 / 6 |
| mongodb findOne | 12.3 / 73.3 / 54.4 (−342% ❌) | 11.5 / 29.6 / 22.4 (−94% ❌) | 14.2 / 141 / 58.9 (−316% ❌) | 6 / 6 / 12 |
| mysql insert | 1658 / 1596 / 200 (+88% ✅) | 1192 / 1312 / 162 (+86% ✅) | 1814 / 1829 / 218 (+88% ✅) | 6 / 6 / 6 |
| mysql selectOne | 7.5 / 64.9 / 47.6 (−537% ❌) | 6.4 / 29.9 / 10.9 (−71% ❌) | 33.1 / 172 / 52.2 (−58% ❌) | 6 / 6 / 12 |
| pgsql insert | 249 / 388 / 81.9 (+67% ✅) | 164 / 329 / 52.8 (+68% ✅) | 373 / 555 / 116 (+69% ✅) | 6 / 6 / 6 |
| pgsql selectOne | 10.9 / 30.2 / 36.6 (−237% ❌) | 10.7 / 23.0 / 26.9 (−150% ❌) | 13.7 / 42.1 / 46.4 (−240% ❌) | 6 / 6 / 12 |

Payload 1 MB, 50 calls:

| Operation | median n/s/a, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | --- |
| mongodb insertOne | 95.5 / 258 / 84.7 (+11% ✅) | 90.3 / 208 / 79.7 (+12% ✅) | 132 / 489 / 96.5 (+27% ✅) | 6 / 10 / 10 |
| mongodb findOne | 78.2 / 153 / 111 (−42% ❌) | 74.8 / 149 / 107 (−43% ❌) | 79.4 / 180 / 119 (−50% ❌) | 8 / 10 / 108 |
| mysql insert | 1773 / 1886 / 650 (+63% ✅) | 1642 / 1741 / 296 (+82% ✅) | 1948 / 2093 / 690 (+65% ✅) | 10 / 10 / 10 |
| mysql selectOne | 36.7 / 153 / 99.6 (−172% ❌) | 36.1 / 133 / 88.5 (−145% ❌) | 67.9 / 219 / 114 (−68% ❌) | 12 / 16 / 114 |
| pgsql insert | 319 / 489 / 339 (−6% ❌) | 289 / 446 / 247 (+15% ✅) | 429 / 775 / 398 (+7% ✅) | 8 / 8 / 8 |
| pgsql selectOne | 84.9 / 179 / 93.5 (−10% ❌) | 81.4 / 171 / 89.6 (−10% ❌) | 90.2 / 346 / 96.2 (−7% ❌) | 8 / 10 / 108 |

What the sizes show:

1. Writes: the fan-out wins as long as fsync dominates, but the margin shrinks as the
   payload grows. mysql insert: +83% → +88% → +63%; pgsql insert: +86% → +67% → −6% at
   1 MB — PostgreSQL commits a large row relatively cheaply (TOAST), and the transfer
   cost catches up with the overlap gain. The mongo write has no per-operation fsync and
   is fast on its own, so there is little to overlap (−58% → −112% → +11%).
2. Reads: native leads at every size, as expected — a point read has nothing to
   overlap, and the payload pays the boundary both ways. The boundary tax by the
   sync−native gap: ~1.5–2.3 ms per 1 MB of payload (mongo 1.5, pgsql 1.9, mysql 2.3),
   i.e. ~0.1 ms at 64 KB — negligible on small data, visible on megabyte blobs.
3. **Memory is the main finding.** The async Memory column at 1 MB reads: 108–114 MB
   against 8–12 MB for native. A fan of 50 concurrent 1 MB reads holds every in-flight
   result at once — RSS grows as fan width × payload. Cap the fan width when fanning out
   large result sets.
4. The practical rule: SConcur pays off on many small-to-medium operations run
   concurrently (up to ~64 KB the boundary tax is near zero); moving megabyte blobs is a
   job for the native driver or for paths that never cross the boundary (like
   `HttpClient::download()`).

## Clients (HTTP / Socket / WebSocket)

In one line: network waits fan out almost perfectly — 50 calls of 100 ms complete in
~120 ms (~44×), a 4 MiB download to file ~6× faster with flat memory.

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

In one line: one cooperative process overlaps any number of I/O waits (100 × 1 s
sleeps finish in ~1 s); CPU-bound requests rely on the per-core pool, not on the
scheduler.

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

In one line: even on empty responses (~1.4× ahead), ~6× ahead once requests do real
I/O — the worker model waits serially, the fan-out overlaps.

To understand the price of the approach, the same two handles were measured on
[RoadRunner](https://roadrunner.dev) 2025.1.15 — a mature Go application server for PHP.
The conditions are identical: the same `php` container (PHP 8.4.15 NTS), 12 workers,
`wrk` 4 threads / 256 connections / 20 s, 3 runs, hitting the bridge IP. The RoadRunner
worker's `/all` deliberately does not use SConcur — the same functionality is built on
native drivers:

- `/` — the PSR-7 worker returns `200 "ok"`.
- `/all` — the same set of operations as SConcur, but natively and sequentially (the
  worker has no internal concurrency): MongoDB `insertOne`+`findOne`
  (`mongodb/mongodb`), MySQL `INSERT`+`SELECT 1` (`PDO`), PostgreSQL `INSERT`+`SELECT 1`
  (`PDO`), the response — the same JSON map of statuses.
- `/all-sconcur` — the same three features, but through SConcur fanned out in a nested
  `WaitGroup` inside the same RoadRunner worker (the worker loads the extension) —
  the "SConcur inside a RoadRunner worker" scenario; the run is
  `make bench-rr-sconcur-load-stats`.

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

The `/all-native` row is the SConcur server's handle: an exact copy of
the RoadRunner worker (the same native drivers, sequentially, without SConcur features)
inside the SConcur server. It isolates the server/transport layer from the driver stack.

| Handle | Server | Requests/sec | p50 / p90 / p99 | CPU avg / peak | MEM peak | vs RoadRunner |
| --- | --- | ---: | --- | --- | ---: | :---: |
| `/` (empty) | SConcur | ≈67 100 | 3.7 / 6.3 / 8.8 ms | ~1210% / ~1210% | ~256 MiB | +42% ✅ |
| `/` (empty) | RoadRunner | ≈47 100 | 5.3 / 5.9 / 6.7 ms | ~1060% / ~1075% | ~230 MiB | — |
| `/all` | SConcur (SConcur, async) | ≈2 680 | 87 / 165 / 267 ms | ~740% / ~765% | ~287 MiB | +483% ✅ |
| `/all` | RoadRunner (native drivers, sequential) | ≈460 | 561 / 589 / 603 ms | ~160% / ~175% | ~237 MiB | — |
| `/all-sconcur` | RoadRunner (SConcur, async) | ≈583 | 442 / 469 / 522 ms | ~540% / ~600% | ~363 MiB | +32% ✅ |
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

The `/all-sconcur` row is the reverse experiment — the SConcur fan-out inside the
RoadRunner worker. The fan-out overlaps the request's three fsyncs, so the request time
tends to the maximum of the three instead of their sum: +32% rps, p50 down from 586 to
442 ms. Further it cannot go: a RoadRunner worker serves one request at a time, so
unlike the cooperative SConcur server (≈2 680 rps) it cannot park dozens of requests on
I/O — the ceiling stays workers × request time. Worker memory rises to ≈363 MiB over 12
workers (the extension's Go runtime in every worker). This is the "complement
RoadRunner, not replace it" scenario: an existing RR application gains in-request
fan-out concurrency without changing its server model. (The `/all-sconcur`/`/all` pair
was measured in a separate session on 2026-07-24; native `/all` gave ≈443 rps / p50
586 ms there, and the row's percent is computed against that same-session baseline.)

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
- The fan-out gain (`async`) is directly proportional to the price of an operation — an
  I/O wait (fsync, a network RTT) or real server-side work — which it overlaps instead of
  summing. On disk-backed SQL every write wins: mysql `insert`/`update`/`delete` ~14–16×
  and `transaction` ~10×, pgsql `update` ~18×, `insert`/`delete` ~5–6× and `transaction`
  ~3×. On the 100k dataset the heavy reads win too: pgsql `count` ~7.5×, mongo `count`
  ~7×, `updateMany` ~5.5×, `bulkWrite` ~6.5×, mysql `count` ~2×. What stays with native
  are the cheap point operations with nothing to overlap: `selectOne`/`findOne`,
  `selectMany` (the row-set conversion at the boundary dominates) and MongoDB's
  single-document operations (`insertOne`, `updateOne`, `deleteOne` — no per-operation
  fsync on the default write concern): there the boundary overhead exceeds the operation.
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
  layer does not lose to the native stack (≈457 ≈ RoadRunner). Inside the RoadRunner
  worker the SConcur fan-out (`/all-sconcur`) gives +32% rps and −25% p50 — capped by the
  one-request-per-worker model. The fan-out only loses
  where operations have no real I/O price (memory/hot cache over loopback — the cheap read
  rows in the DB tables).
- Memory is practically flat across the modes: the DB tables show equal per-mode peaks
  (`selectMany` +2 MB for the result sets) — the coroutine fibers of a 100-wide fan-out
  do not move the peak noticeably.
