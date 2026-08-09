English | [Русский](benchmarks.ru.md)

# Feature benchmarks

Per-feature measurements (except `Sleeper`): what a call across the PHP↔Go
boundary costs against the native driver, and what fanning it out concurrently
gains. Reference numbers, not a guarantee — they depend on hardware, DB settings
and load. The workload-matching verdict table is in
[positioning](positioning.md#is-sconcur-for-you).

> The `sync` column carries a fixed overhead that is not inherent to the approach:
> a synchronous call (outside a `WaitGroup` and outside the servers) still goes
> through the scheduler and the Fiber machinery. Cutting that is on the
> [roadmap](../README.md#roadmap). Until then the meaningful comparison is
> `native` vs `async`.

## Contents

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
  - [Comparison with RoadRunner and Swoole](#comparison-with-roadrunner-and-swoole)
  - [Point query: the worker-count ladder](#point-query-the-worker-count-ladder)
    - [Write and read: `/db-rw`](#write-and-read-db-rw)
- [Conclusions](#conclusions)

## Environment

Intel Core i7-13620H (16 threads), 15 GiB RAM, Linux, everything in Docker: the
benchmarks from the `php` container (`make bench-*`), the server pools from the
`servers` container (3 workers, `SO_REUSEPORT`). Component versions — see
[Tested versions](../README.md#tested-versions).

DB data lives on the host disk (SSD), as in a real deployment: writes pay a real
fsync, hot reads come from the DB cache. By default `docker-compose.yml` keeps the
data in `tmpfs`; for a benchmark session the named volumes are uncommented instead,
and the state is reset with `make bench-reset` — without it writes accumulate
between runs and the numbers drift.

Client and server numbers taken on 2026-07-22, DB numbers on 2026-07-23, the
three-stack comparisons on 2026-08-09, all on an idle machine.

## Conversion overhead (the PHP↔Go boundary)

Every call crosses the boundary and converts its data: arguments are packed into
MessagePack (`Transport/MessagePackTransport`), the result is unpacked back; Mongo
documents additionally go through BSON via `ext-mongodb`. This is a fixed CPU price
per operation, on top of the cgo call and goroutine dispatch. On cheap cached reads
it shows up as the `native` → `sync` gap (both sequential, but `sync` goes through
Go): `pgsql-selectOne` 3.8 → 10.2 ms over 100 calls, `mysql-selectOne` 3.9 →
15.2 ms. On a slow operation the same surcharge is a small fraction of the total.

## Methodology

Three modes per feature: `native` — the baseline without SConcur
(`mongodb/mongodb`, `PDO`, stream wrappers, raw sockets), sequential; `sync` —
SConcur outside a `WaitGroup` (the `Extension::wait` path), also sequential;
`async` — SConcur inside a `WaitGroup`, N coroutines fanned out.

Each DB benchmark ran 5 times, each client/server benchmark 3 times; the tables
show the median. Every DB run starts cold — the table/collection is dropped and
reseeded to 100 000 rows/documents before the measurement — and point operations
work on distinct ids per mode, so no two calls share a hot row and a row lock
cannot serialize the fan-out. A discarded warm-up precedes each measurement,
otherwise the fan-out would pay for spinning up the connection pool inside the
measured phase; the SQL pools are `maxOpenConns: 50` with `maxIdleConns` defaulting
to the same value. Memory is the peak RSS of the PHP process per mode.

Calls per mode: 100 by default, 50 for client I/O benchmarks. Three MongoDB
benchmarks are bounded by the operation's nature: `createIndex` and `bulkWrite` 20,
`updateMany` 10. Single runs: `make bench-<name> c=<count>`; the whole DB session
is `make bench-db-runs`.

`async vs native` is the signed percent `(native − async) / native`, ✅ when the
fan-out is faster; in the DB tables each of the median, min and max columns carries
its own, which shows the spread across runs. In the server comparison the
`vs RoadRunner`/`vs Swoole` columns compare throughput against that stack's row of
the same handle. Sub-50 ms rows are noise-sensitive — a sign flip between a row's
`min` and `max` marks exactly that.

## MongoDB

Fan out what makes the server work — `count` ~7×, `bulkWrite` ~6.5×, `updateMany`
~5.5×, `createIndex` +26%; single-document operations stay with the native driver.

Median of 5 runs against a cold dataset of 100 000 documents. Cells hold
`native / sync / async`, ms, with the `async vs native` percent in parentheses.

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

async wins where a call makes the server chew through the dataset — every `count`
scans all 100k documents, every `updateMany` rewrites them, the unindexed
`bulkWrite` filters scan the collection several times per call. Point
single-document operations stay with native: MongoDB pays no per-operation fsync on
the default write concern, so a write is a fast in-memory operation with no I/O wait
to overlap, and the boundary conversion costs more than the operation itself.

## MySQL

Every disk write fanned out is ~10–16× faster (the fsyncs overlap), `transaction`
~10×, `count` ~2×; cheap reads stay with PDO. Median of 5 runs against a cold
dataset of 100 000 rows, columns as for MongoDB.

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

Writes fanned out are ~3–18× faster, `count` ~7.5×; point reads stay with PDO.

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
sequential modes sum it over all 100 calls, and the fan-out overlaps it. `count`
over the 100 000-row table also goes to async (pgsql's `COUNT(*)` scans the heap):
a read that makes the server work is worth fanning out too. What stays with native
are cheap cached point reads (`selectOne` — almost even) and `selectMany`, where
the row-set conversion at the boundary dominates.

## Payload size

Up to ~64 KB per operation the boundary tax is negligible; megabyte blobs belong
to the native driver, and a wide fan of large results pays RSS ≈ fan width ×
payload.

On the async path the payload crosses the boundary twice — packed bindings (or a
BSON document) in, the result buffer out — so the boundary tax grows with the
payload while the fan-out gain does not. Six dedicated benches
(`tests/benchmarks/{mongodb,mysql,pgsql}-payload-{write,read}.php`) move an
incompressible base64 payload of `SCONCUR_BENCH_PAYLOAD_BYTES` bytes per call; read
re-reads one hot row per mode, so the measured path is transfer + decode, not disk.
Single runs: `make bench-mysql-payloadWrite p=65536 c=100`. Median of 5 cold runs,
columns as above.

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

1. Writes: the fan-out wins while fsync dominates, but the margin shrinks as the
   payload grows — pgsql insert goes +86% → +67% → −6% at 1 MB, because PostgreSQL
   commits a large row cheaply via TOAST and the transfer cost catches up.
2. Reads: native leads at every size — a point read has nothing to overlap, and
   the payload pays the boundary both ways. The tax by the `sync − native` gap is
   ~1.5–2.3 ms per 1 MB, i.e. ~0.1 ms at 64 KB.
3. **Memory is the main finding.** The async column at 1 MB reads 108–114 MB
   against 8–12 MB for native: a fan of 50 concurrent 1 MB reads holds every
   in-flight result at once. Cap the fan width on large result sets, and move
   megabyte blobs through the native driver or a path that never crosses the
   boundary (like `HttpClient::download()`).

## Clients (HTTP / Socket / WebSocket)

Network waits fan out almost perfectly: the `msleep` endpoint holds the connection
for 100 ms, so 50 sequential calls take ≈5 s while the fan-out takes ≈one call.

| Benchmark | count | native, ms | sync, ms | async, ms | Memory n/s/a, MB | async vs native |
| --- | ---: | ---: | ---: | ---: | --- | :---: |
| http-client (`/msleep/100`) | 50 | 5243 | 5222 | 120 | 4 / 4 / 4 | +98% ✅ |
| http-client-download (4 MiB) | 50 | 1105 | 844 | 192 | 4 / 4 / 4 | +83% ✅ |
| socket-client (`msleep:100`) | 50 | 5222 | 5287 | 119 | 4 / 4 / 4 | +98% ✅ |
| ws-client (`msleep:100`) | 50 | 5255 | 5345 | 131 | 4 / 4 / 4 | +98% ✅ |

On I/O latency async gives ~44× (5.2 s → 0.12 s). `download` writes a 4 MiB body
straight to a file on the Go side, so memory stays flat and the fan-out still
speeds it up ~6×.

## Servers (HTTP / Socket / WebSocket)

One cooperative process overlaps any number of I/O waits; CPU-bound requests rely
on the per-core pool, not on the scheduler. A pool of 3 workers (`SO_REUSEPORT`),
100 concurrent requests/connections per run (throughput — 50 connections × 2000
round-trips), median of 3 runs, all responses successful.

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

100 handlers each sleeping 1 s asynchronously finish in ≈one sleep regardless of
the worker count — a single cooperative process already overlaps all the waits. The
sha256 loop does not yield, but the `SO_REUSEPORT` pool spreads the 100 requests
across cores, so they still complete in ~0.7 s. Throughput measures the pure
round-trip price under concurrency. Behaviour under sustained load is in
[load testing](load-testing.md).

### HTTP throughput: `/` vs `/all`

Sustained throughput under `wrk` (the `http-load-stats.sh` script, methodology in
[load testing](load-testing.md)): 12 processes in the `php` container, `wrk` 4
threads / 256 connections / 20 s hitting the bridge IP directly, 3 runs per handle,
all responses `200`. `/` is an empty handle (the pure ceiling of the server and the
framework); `/all` fans out MongoDB (insert + findOne), MySQL and PostgreSQL
(`INSERT` + `SELECT 1` each) in a nested `WaitGroup`, with the SQL pools capped at
`maxOpenConns: 5` per process so 12–16 processes do not break through PostgreSQL's
`max_connections = 100`.

| Handle | Requests/sec | Latency p50 / p90 / p99 | CPU `php` avg / peak | MEM peak |
| --- | ---: | --- | --- | ---: |
| `/` (empty) | ≈67 100 | 3.7 / 6.3 / 8.8 ms | ~1210% / ~1210% | ~256 MiB |
| `/all` (all features) | ≈2 680 | 87 / 165 / 267 ms | ~740% / ~765% | ~287 MiB |

The pool ceiling is 12 pinned cores (~1200%). The empty handle hits CPU; `/all` on
disk backends hits not CPU (~740% of 1200) but fsync — 6 DB operations per request,
3 of them writes.

### Comparison with RoadRunner and Swoole

Three execution models on the same handles: the worker model (RoadRunner) loses ~6×
as soon as a request does real I/O, while the two concurrent models (SConcur and
Swoole) land together at the top; on empty responses Swoole's C event loop is ahead
of everything.

Two reference stacks are measured next to SConcur, both on native drivers and both
committed (`tests/servers/roadrunner/`, `tests/servers/swoole/`) with load
harnesses that are copies of `http-load-stats.sh`:
[RoadRunner](https://roadrunner.dev) 2025.1.15 (a request occupies a worker for its
whole duration) and [Swoole](https://swoole.com) 6.2.2 (a coroutine worker serves
many requests at once, blocking drivers become non-blocking via
`hook_flags => SWOOLE_HOOK_ALL`). Conditions are identical: the same `php`
container, 12 workers, `wrk` 4 threads / 256 connections / 20 s, 3 runs (median),
DB data on disk, all in one session (2026-08-09) — only in-session numbers are
comparable, cross-session drift reaches ±20%. Pools per worker process: SConcur 5
connections per SQL feature, Swoole a `PDOPool` of 5, RoadRunner one PDO per worker.

The handles are copies of each other; only the driver stack and the execution model
differ. `/` returns `200 "ok"`; `/all` runs the three features (SConcur fans them
out in a nested `WaitGroup`, RoadRunner and Swoole run them sequentially);
`/all-coro` is Swoole's own fan-out in a `Swoole\Coroutine\WaitGroup`;
`/all-sconcur` is the SConcur fan-out inside a RoadRunner worker that loads the
extension.

Honesty checks: every stack actually did the work (hundreds of thousands of inserts
per run, every response `200`). The one row that is not what it seems is Swoole's
`/`: there the load generator is the limit, not the server — with 8 generator
threads instead of 4 the same handle answered ≈865 000 rps, so the figure below is
a floor.

| Handle | Server | Requests/sec | p50 / p90 / p99 | CPU avg / peak | MEM peak | vs RoadRunner | vs Swoole |
| --- | --- | ---: | --- | --- | ---: | :---: | :---: |
| `/` (empty) | Swoole | ≈353 000 | 0.4 / 0.4 / 0.7 ms | ~257% / ~264% | ~72 MiB | — | — |
| `/` (empty) | SConcur | ≈64 100 | 3.9 / 6.7 / 9.1 ms | ~1210% / ~1212% | ~217 MiB | +38% ✅ | −82% ❌ |
| `/` (empty) | RoadRunner | ≈46 600 | 5.4 / 6.0 / 6.9 ms | ~1050% / ~1062% | ~228 MiB | — | — |
| `/all-coro` | Swoole (coroutine fan-out) | ≈3 030 | 83 / 204 / 303 ms | ~263% / ~275% | ~137 MiB | — | — |
| `/all` | SConcur (fan-out in a `WaitGroup`) | ≈2 681 | 86 / 166 / 269 ms | ~735% / ~751% | ~249 MiB | +499% ✅ | — |
| `/all` | Swoole (native drivers, sequential in-request) | ≈2 671 | 80 / 267 / 322 ms | ~237% / ~249% | ~126 MiB | — | — |
| `/all-sconcur` | RoadRunner (SConcur fan-out in the worker) | ≈586 | 435 / 462 / 589 ms | ~592% / ~647% | ~360 MiB | +31% ✅ | — |
| `/all` | RoadRunner (native drivers, sequential) | ≈448 | 573 / 611 / 711 ms | ~158% / ~167% | ~232 MiB | — | — |

The `vs Swoole` column is filled on the empty handle only: on `/all` the two stacks
do not run the same workload, because MongoDB has no coroutine path in Swoole
(`ext-mongodb`/libmongoc is outside the runtime hooks) and its call blocks the whole
worker.

- On the empty handle the ranking is the price of the transport: Swoole answers
  from a C event loop in the same process as the PHP closure, while SConcur is
  ~1.4× RoadRunner because crossing the PHP↔Go boundary is cheaper than
  RoadRunner's IPC hop proxy → worker per request.
- On `/all` with disk backends both concurrent servers are ~6× RoadRunner and land
  level with each other. The reason is fsync: 3 writes per request fold into a
  chain of disk commits in a sequential worker (p50 ≈ 0.57 s at ~158% CPU), while
  both concurrent models overlap those same fsyncs across dozens of parked
  requests — the ceiling stops being the server and becomes the disk.
- CPU and memory go to Swoole (~237% against ~735%, ~126 MiB against ~249 MiB at
  the same throughput; part of that saving lands on the backends, which burn
  ~1.5–2× more CPU under Swoole). SConcur pays for the boundary: MessagePack plus
  cgo on every operation.
- The in-request fan-out goes to SConcur: Swoole's own (`/all-coro`) adds only +13%
  over its sequential route, because the blocking MongoDB call lets it overlap the
  two SQL features only. That also shows in the tails — Swoole holds the better
  median and the worse p90/p99.
- `/all-sconcur` is the reverse experiment: the fan-out inside a RoadRunner worker
  overlaps the request's three fsyncs (+31% rps, p50 573 → 435 ms), but a worker
  serves one request at a time, so the ceiling stays workers × request time. This
  is the "complement RoadRunner, not replace it" scenario.

### Point query: the worker-count ladder

The same three stacks on the cheapest possible request (the `/db` handle of the demo
servers): one point SELECT of a random id per request, JSON response. SConcur runs
it through the MySQL feature with no `WaitGroup` (cross-request overlap only),
RoadRunner and Swoole through PDO. Disk-backed MySQL, default
`max_connections = 151`; the per-process pool of SConcur and Swoole is sized to fit
that limit, RoadRunner holds one connection per worker. Same wrk harness, one
session.

| Workers | RoadRunner rps / p50 / p99 | SConcur rps / p50 / p99 (pool) | Swoole rps / p50 / p99 (pool) | SConcur vs RR | SConcur vs Swoole |
| ---: | --- | --- | --- | :---: | :---: |
| 1 | 5 280 / 47.3 ms / 62.9 ms | 6 444 / 39.0 ms / 71.3 ms (150) | 46 225 / 5.4 ms / 7.2 ms (150) | +22% ✅ | −86% ❌ |
| 3 | 10 282 / 24.4 ms / 31.2 ms | 13 061 / 17.2 ms / 38.9 ms (50×3) | 100 686 / 2.5 ms / 3.8 ms (50×3) | +27% ✅ | −87% ❌ |
| 8 | 23 665 / 10.6 ms / 12.6 ms | 25 517 / 9.8 ms / 19.7 ms (18×8) | 123 359 / 1.9 ms / 6.3 ms (18×8) | +8% ✅ | −79% ❌ |
| 16 | 28 272 / 8.7 ms / 74.2 ms | 27 487 / 8.9 ms / 27.9 ms (9×16) | 113 880 / 2.0 ms / 7.2 ms (9×16) | −3% ❌ | −76% ❌ |

- Against RoadRunner the SConcur advantage tapers with pool size, from +22% on one
  worker to parity at the full per-core pool, where both meet the shared hardware
  ceiling (MySQL plus total CPU). SConcur saturates first — it spends more CPU per
  request.
- Swoole is 4–7× ahead of both, and this is where SConcur's price is most visible.
  Both saturate one PHP thread on a single worker (~101% CPU), but that thread buys
  ≈6.4k rps for SConcur (~156 µs of CPU per request) against ≈46k for Swoole
  (~21 µs): the hooked `mysqlnd` never leaves the process. The work moves to the
  database accordingly — at one worker the mysql container burns ~194% CPU under
  Swoole against ~35% under SConcur.
- Tails: RoadRunner is tight until the full pool and then breaks (p99 74 ms at 16
  workers); SConcur multiplexes dozens of in-flight requests per thread and holds
  p99 28 ms; Swoole stays under 8 ms everywhere.
- Pool sizing: the pool lives per process, so the DB connection budget is divided
  across processes — 16 processes × a 150-connection pool against
  `max_connections = 151` turns a third of the responses into "Too many connections".
  The useful size is the expected in-flight per process plus a small margin; raising
  `max_connections` to 500 and inflating the pools moved nothing (±1%).
- Open item: from 3 workers up, 0.06–0.24% of the SConcur responses on this handle
  came back non-2xx (0.8–2.6% on `/db-rw` below); RoadRunner and Swoole had none.
  Shrinking the pools changed neither the throughput nor the error share, so the
  connection limit is not the cause; the cause is not identified yet.

This is the same boundary the cheap-point-query row of the verdict table draws
([positioning](positioning.md#is-sconcur-for-you)): with nothing to overlap inside a
request, SConcur's remaining edge over the worker model is holding the same load on
fewer processes, and against a coroutine server on hooked native drivers there is no
edge at all.

#### Write and read: `/db-rw`

The same ladder with a write in the request. `/db-rw` does one INSERT, a COUNT(*)
and a point SELECT of a random id within that count, sequentially in all three
stacks. The `bench_rw` table is reset to its 10 000 seed rows before each run;
during a run it grows with the inserts, so the faster stack makes its own COUNT(*)
heavier — the comparison is conservative.

| Workers | RoadRunner rps / p50 / p99 | SConcur rps / p50 / p99 (pool) | Swoole rps / p50 / p99 (pool) | SConcur vs RR | SConcur vs Swoole |
| ---: | --- | --- | --- | :---: | :---: |
| 1 | 96 / 1.03 s / 1.98 s | 2 299 / 99 ms / 380 ms (150) | 2 657 / 87 ms / 337 ms (150) | ×24 ✅ | −13% ❌ |
| 3 | 204 / 1.24 s / 1.46 s | 2 523 / 89 ms / 376 ms (50×3) | 2 681 / 88 ms / 347 ms (50×3) | ×12 ✅ | −6% ❌ |
| 8 | 425 / 606 ms / 657 ms | 2 497 / 89 ms / 362 ms (18×8) | 2 654 / 87 ms / 304 ms (18×8) | ×5.9 ✅ | −6% ❌ |
| 16 | 754 / 343 ms / 433 ms | 2 520 / 91 ms / 347 ms (9×16) | 2 593 / 88 ms / 323 ms (9×16) | ×3.3 ✅ | −3% ❌ |

- One disk commit per request flips the ladder. Both concurrent stacks stand on the
  same ceiling from the first worker on (≈2.3–2.7k rps, flat across the ladder) —
  cross-request overlap folds the commits of dozens of in-flight requests into group
  commits, and the limit becomes MySQL itself (~1 300–1 380% CPU on the mysql
  container while php sits at 24–62% for Swoole and 93–211% for SConcur). Swoole's
  6–16% edge is the boundary tax, not a different ceiling.
- RoadRunner scales almost linearly with workers but even 16 stay 3.3× behind;
  parity would take around 55 processes. Its latencies at small pools are queueing,
  not work: 256 wrk connections share 1–3 workers.
- The applicability boundary is the fan-out's, one level up: as soon as the request
  contains an operation with real waiting, overlap decides — even with no `WaitGroup`
  inside the request.

## Conclusions

- A single call through SConcur is always more expensive than the native driver —
  the conversion at the boundary (MessagePack + BSON for MongoDB + cgo), most
  noticeable on cheap cached reads.
- The fan-out gain is proportional to the price of an operation — an I/O wait (fsync,
  a network RTT) or real server-side work — which it overlaps instead of summing: on
  disk-backed SQL every write wins, as do heavy reads on the 100k dataset, and 100 ms
  network waits fan out ~44×. Cheap point operations stay with native.
- The connection pool is decisive: a cold pool cost the fan-out 3–15×, which is why
  the methodology includes a warm-up and `maxIdleConns` defaults to `maxOpenConns`.
- The boundary is cheap on the fan-out itself: socket/ws throughput ~120–154k
  round-trips/s, the empty HTTP handle ~64k rps (~1.4× RoadRunner).
- Against RoadRunner and Swoole on disk backends: on `/all` both concurrent models
  are ~6× the sequential worker and level with each other. Swoole holds it on ~237%
  CPU against ~735%, but its own in-request fan-out adds only +13% because
  `ext-mongodb` is outside its runtime hooks. On cheap point queries the ranking
  reverses — ≈46k rps against ≈6.4k on the same core, which is the boundary tax.
- Memory is practically flat across the modes — the fibers of a 100-wide fan-out do
  not move the peak noticeably.
