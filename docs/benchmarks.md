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
  - [Empty handle: the worker-count ladder](#empty-handle-the-worker-count-ladder)
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

Client and server numbers taken on 2026-07-22, DB and payload numbers on
2026-08-13, the three-stack comparisons on 2026-08-09, all on an idle machine. The SConcur rows
of the server tables and of the three-stack comparison were re-measured on
2026-08-12, after the 0.9.1 hot-path work (fiber pool, request-body chunk
sizing, fiber-stack cgo dispatch); the RoadRunner and Swoole rows of the `/db` tables are kept from
2026-08-09 — same machine, same setup, idle both times. The empty-handle
worker ladder measured all three stacks in one session on 2026-08-12.

## Conversion overhead (the PHP↔Go boundary)

Every call crosses the boundary and converts its data: arguments are packed into
MessagePack (`Transport/MessagePackTransport`), the result is unpacked back; Mongo
documents additionally go through BSON via `ext-mongodb`. This is a fixed CPU price
per operation, on top of the cgo call and goroutine dispatch. On cheap cached reads
it shows up as the `native` → `sync` gap (both sequential, but `sync` goes through
Go): `pgsql-selectOne` 3.6 → 9.0 ms over 100 calls, `mysql-selectOne` 7.7 →
21.9 ms. On a slow operation the same surcharge is a small fraction of the total.

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

Fan out what makes the server work — `count` ~7×, `bulkWrite` ~7×, `updateMany`
~5.5×, `createIndex` +28%; single-document operations stay with the native driver.

Median of 5 runs against a cold dataset of 100 000 documents. Cells hold
`native / sync / async`, ms, with the `async vs native` percent in parentheses.

| Operation | count | native / sync / async, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | ---: | --- |
| insertOne | 100 | 13.5 / 40.8 / 12.2 (+10% ✅) | 6.4 / 17.6 / 5.8 (+10% ✅) | 21.6 / 92.9 / 28.0 (−30% ❌) | 10 / 10 / 10 |
| insertMany | 100 | 45.7 / 92.0 / 65.8 (−44% ❌) | 22.1 / 37.4 / 11.9 (+46% ✅) | 146 / 258 / 73.3 (+50% ✅) | 10 / 10 / 10 |
| bulkWrite | 20 | 3434 / 3521 / 501 (+85% ✅) | 3405 / 3469 / 479 (+86% ✅) | 3449 / 3535 / 514 (+85% ✅) | 8 / 8 / 8 |
| updateOne | 100 | 7.6 / 10.1 / 9.6 (−27% ❌) | 6.3 / 9.7 / 6.1 (+3% ✅) | 10.6 / 72.2 / 35.3 (−231% ❌) | 10 / 10 / 10 |
| updateMany | 10 | 1760 / 1703 / 313 (+82% ✅) | 1740 / 1697 / 306 (+82% ✅) | 1780 / 1739 / 336 (+81% ✅) | 8 / 8 / 8 |
| deleteOne | 100 | 6.5 / 22.7 / 7.3 (−11% ❌) | 6.0 / 10.1 / 4.6 (+23% ✅) | 36.3 / 58.7 / 31.9 (+12% ✅) | 10 / 10 / 10 |
| findOne | 100 | 11.9 / 21.3 / 12.9 (−8% ❌) | 8.2 / 10.4 / 4.2 (+49% ✅) | 16.6 / 88.9 / 52.8 (−218% ❌) | 10 / 10 / 10 |
| aggregate | 100 | 20.0 / 38.6 / 30.3 (−51% ❌) | 12.1 / 21.1 / 12.9 (−7% ❌) | 26.8 / 63.2 / 45.6 (−71% ❌) | 10 / 10 / 10 |
| count | 100 | 2269 / 2304 / 316 (+86% ✅) | 2236 / 2264 / 308 (+86% ✅) | 2316 / 2381 / 324 (+86% ✅) | 10 / 10 / 10 |
| command | 100 | 8.5 / 15.0 / 13.4 (−58% ❌) | 6.5 / 12.6 / 3.2 (+52% ✅) | 14.7 / 46.6 / 22.9 (−56% ❌) | 6 / 6 / 6 |
| createIndex | 20 | 2164 / 2202 / 1566 (+28% ✅) | 2127 / 2160 / 1510 (+29% ✅) | 2179 / 2248 / 1618 (+26% ✅) | 8 / 8 / 8 |

async wins where a call makes the server chew through the dataset — every `count`
scans all 100k documents, every `updateMany` rewrites them, the unindexed
`bulkWrite` filters scan the collection several times per call. Point
single-document operations stay with native: MongoDB pays no per-operation fsync on
the default write concern, so a write is a fast in-memory operation with no I/O wait
to overlap, and the boundary conversion costs more than the operation itself.

## MySQL

Every disk write fanned out is ~12–15× faster (the fsyncs overlap), `transaction`
~11×, `count` ~2×; cheap reads stay with PDO. Median of 5 runs against a cold
dataset of 100 000 rows, columns as for MongoDB.

| Operation | count | native / sync / async, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | ---: | --- |
| insert | 100 | 622 / 671 / 50.5 (+92% ✅) | 589 / 662 / 27.5 (+95% ✅) | 662 / 696 / 55.5 (+92% ✅) | 6 / 6 / 6 |
| selectOne | 100 | 7.7 / 21.9 / 15.6 (−103% ❌) | 3.9 / 9.4 / 4.0 (−5% ❌) | 25.9 / 70.3 / 24.5 (+5% ✅) | 6 / 6 / 6 |
| selectMany | 100 | 9.4 / 80.5 / 45.7 (−388% ❌) | 8.0 / 28.1 / 14.0 (−74% ❌) | 23.9 / 230 / 52.9 (−121% ❌) | 6 / 6 / 10 |
| count | 100 | 148 / 158 / 77.4 (+48% ✅) | 138 / 144 / 62.5 (+55% ✅) | 163 / 198 / 92.1 (+44% ✅) | 6 / 6 / 6 |
| update | 100 | 637 / 680 / 41.3 (+94% ✅) | 610 / 654 / 28.0 (+95% ✅) | 643 / 702 / 47.3 (+93% ✅) | 6 / 6 / 6 |
| delete | 100 | 639 / 706 / 42.3 (+93% ✅) | 617 / 656 / 41.0 (+93% ✅) | 640 / 716 / 43.2 (+93% ✅) | 6 / 6 / 6 |
| transaction | 100 | 641 / 820 / 56.3 (+91% ✅) | 578 / 734 / 28.4 (+95% ✅) | 656 / 828 / 68.9 (+89% ✅) | 6 / 6 / 6 |

## PostgreSQL

Writes fanned out are ~3–8× faster, `count` ~7×; point reads stay with PDO.

| Operation | count | native / sync / async, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | ---: | --- |
| insert | 100 | 128 / 179 / 16.5 (+87% ✅) | 123 / 114 / 5.7 (+95% ✅) | 133 / 187 / 25.5 (+81% ✅) | 6 / 6 / 6 |
| selectOne | 100 | 3.6 / 9.0 / 9.6 (−169% ❌) | 2.9 / 7.6 / 3.7 (−31% ❌) | 4.8 / 18.0 / 17.9 (−270% ❌) | 6 / 6 / 6 |
| selectMany | 100 | 7.1 / 37.4 / 43.3 (−510% ❌) | 6.9 / 25.6 / 12.7 (−85% ❌) | 8.2 / 47.1 / 49.1 (−502% ❌) | 6 / 6 / 10 |
| count | 100 | 287 / 306 / 41.1 (+86% ✅) | 281 / 293 / 39.5 (+86% ✅) | 290 / 312 / 43.1 (+85% ✅) | 6 / 6 / 6 |
| update | 100 | 130 / 190 / 28.7 (+78% ✅) | 121 / 177 / 19.7 (+84% ✅) | 133 / 204 / 30.4 (+77% ✅) | 6 / 6 / 6 |
| delete | 100 | 138 / 185 / 18.7 (+86% ✅) | 126 / 151 / 15.3 (+88% ✅) | 141 / 194 / 24.2 (+83% ✅) | 6 / 6 / 6 |
| transaction | 100 | 161 / 306 / 49.8 (+69% ✅) | 131 / 181 / 11.0 (+92% ✅) | 177 / 330 / 50.7 (+71% ✅) | 6 / 6 / 6 |

The disk flips the SQL picture on writes: every committed write pays an fsync, the
sequential modes sum it over all 100 calls, and the fan-out overlaps it. `count`
over the 100 000-row table also goes to async (pgsql's `COUNT(*)` scans the heap):
a read that makes the server work is worth fanning out too. What stays with native
are cheap cached point reads (`selectOne`) and `selectMany`, where the row-set
conversion at the boundary dominates.

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
| mongodb insertOne | 13.6 / 32.5 / 30.3 (−123% ❌) | 9.0 / 9.6 / 4.5 (+50% ✅) | 37.0 / 138 / 36.1 (+2% ✅) | 6 / 6 / 6 |
| mongodb findOne | 10.4 / 20.4 / 9.8 (+6% ✅) | 6.3 / 8.8 / 4.9 (+23% ✅) | 16.5 / 78.7 / 18.6 (−13% ❌) | 6 / 6 / 6 |
| mysql insert | 739 / 813 / 132 (+82% ✅) | 736 / 746 / 128 (+83% ✅) | 753 / 841 / 143 (+81% ✅) | 6 / 6 / 6 |
| mysql selectOne | 10.1 / 15.0 / 13.2 (−30% ❌) | 9.6 / 10.7 / 4.4 (+55% ✅) | 12.5 / 68.6 / 25.4 (−103% ❌) | 6 / 6 / 6 |
| pgsql insert | 123 / 179 / 28.0 (+77% ✅) | 111 / 165 / 21.1 (+81% ✅) | 143 / 195 / 28.6 (+80% ✅) | 6 / 6 / 6 |
| pgsql selectOne | 3.1 / 9.5 / 4.2 (−35% ❌) | 2.7 / 7.5 / 3.8 (−42% ❌) | 5.5 / 16.3 / 18.5 (−233% ❌) | 6 / 6 / 6 |

Payload 64 KB, 100 calls:

| Operation | median n/s/a, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | --- |
| mongodb insertOne | 29.8 / 51.8 / 38.3 (−29% ❌) | 24.9 / 46.4 / 14.5 (+42% ✅) | 50.8 / 259 / 72.2 (−42% ❌) | 6 / 6 / 6 |
| mongodb findOne | 12.5 / 45.5 / 38.5 (−209% ❌) | 11.6 / 31.0 / 19.3 (−66% ❌) | 26.9 / 138 / 61.5 (−129% ❌) | 6 / 6 / 14 |
| mysql insert | 1629 / 1700 / 192 (+88% ✅) | 1307 / 1171 / 118 (+91% ✅) | 1782 / 1744 / 213 (+88% ✅) | 6 / 6 / 6 |
| mysql selectOne | 6.1 / 26.4 / 42.5 (−599% ❌) | 5.6 / 23.4 / 14.5 (−158% ❌) | 6.4 / 79.4 / 48.8 (−660% ❌) | 8 / 8 / 16 |
| pgsql insert | 255 / 349 / 66.8 (+74% ✅) | 141 / 169 / 57.5 (+59% ✅) | 314 / 423 / 91.2 (+71% ✅) | 6 / 6 / 6 |
| pgsql selectOne | 12.4 / 31.0 / 22.0 (−77% ❌) | 10.3 / 24.5 / 14.9 (−44% ❌) | 14.2 / 55.4 / 55.0 (−286% ❌) | 6 / 6 / 14 |

Payload 1 MB, 50 calls:

| Operation | median n/s/a, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | --- |
| mongodb insertOne | 149 / 264 / 83.1 (+44% ✅) | 116 / 206 / 78.7 (+32% ✅) | 274 / 317 / 118 (+57% ✅) | 6 / 10 / 10 |
| mongodb findOne | 54.5 / 150 / 127 (−134% ❌) | 53.8 / 135 / 119 (−121% ❌) | 58.0 / 164 / 144 (−149% ❌) | 8 / 10 / 145 |
| mysql insert | 2761 / 3262 / 1085 (+61% ✅) | 1939 / 2085 / 462 (+76% ✅) | 4271 / 3640 / 1421 (+67% ✅) | 10 / 10 / 10 |
| mysql selectOne | 39.4 / 122 / 105 (−166% ❌) | 37.9 / 117 / 99.6 (−163% ❌) | 51.2 / 186 / 129 (−152% ❌) | 12 / 16 / 151 |
| pgsql insert | 716 / 874 / 528 (+26% ✅) | 476 / 784 / 372 (+22% ✅) | 740 / 1273 / 880 (−19% ❌) | 8 / 8 / 8 |
| pgsql selectOne | 86.7 / 215 / 106 (−22% ❌) | 84.8 / 187 / 89.7 (−6% ❌) | 93.1 / 269 / 108 (−16% ❌) | 8 / 10 / 142 |

1. Writes: the fan-out wins while fsync dominates, but the margin shrinks as the
   payload grows — pgsql insert goes +77% → +74% → +26% at 1 MB, because PostgreSQL
   commits a large row cheaply via TOAST and the transfer cost catches up.
2. Reads: native leads at every size (the 1 KB mongodb row is noise — the sign
   flips between its `min` and `max`) — a point read has nothing to overlap, and
   the payload pays the boundary both ways. The tax by the `sync − native` gap is
   ~1.7–2.6 ms per 1 MB, i.e. ~0.2 ms at 64 KB.
3. **Memory is the main finding.** The async column at 1 MB reads 142–151 MB
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
| http-server-io | 100 × `GET /msleep/1000` (1 s async sleep) | 1.04 | — |
| http-server-cpu | 100 × `GET /cpu/100000` (sha256 loop) | 0.72 | — |
| socket-server-io | 100 × `msleep:1000` round-trip | 1.01 | — |
| socket-server-cpu | 100 × `cpu:100000` round-trip | 0.68 | — |
| socket-throughput | 50 conn × 2000 × `ping` | 0.58 | ≈171 000 rt/s |
| ws-server-io | 100 × `msleep:1000` round-trip | 1.01 | — |
| ws-server-cpu | 100 × `cpu:100000` round-trip | 0.70 | — |
| ws-throughput | 50 conn × 2000 × `ping` | 0.89 | ≈112 000 rt/s |

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
| `/` (empty) | ≈133 500 | 1.8 / 7.1 / 30.1 ms | ~1218% / ~1225% | ~221 MiB |
| `/all` (all features) | ≈3 010 | 76 / 155 / 267 ms | ~563% / ~590% | ~279 MiB |

The pool ceiling is 12 pinned cores (~1200%). The empty handle hits CPU; `/all` on
disk backends hits not CPU (~565% of 1200) but fsync — 6 DB operations per request,
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
| `/` (empty) | SConcur | ≈133 500 | 1.8 / 7.1 / 30.1 ms | ~1218% / ~1225% | ~221 MiB | +186% ✅ | −62% ❌ |
| `/` (empty) | RoadRunner | ≈46 600 | 5.4 / 6.0 / 6.9 ms | ~1050% / ~1062% | ~228 MiB | — | — |
| `/all-coro` | Swoole (coroutine fan-out) | ≈3 030 | 83 / 204 / 303 ms | ~263% / ~275% | ~137 MiB | — | — |
| `/all` | SConcur (fan-out in a `WaitGroup`) | ≈3 010 | 76 / 155 / 267 ms | ~563% / ~590% | ~279 MiB | +572% ✅ | — |
| `/all` | Swoole (native drivers, sequential in-request) | ≈2 671 | 80 / 267 / 322 ms | ~237% / ~249% | ~126 MiB | — | — |
| `/all-sconcur` | RoadRunner (SConcur fan-out in the worker) | ≈586 | 435 / 462 / 589 ms | ~592% / ~647% | ~360 MiB | +31% ✅ | — |
| `/all` | RoadRunner (native drivers, sequential) | ≈448 | 573 / 611 / 711 ms | ~158% / ~167% | ~232 MiB | — | — |

The `vs Swoole` column is filled on the empty handle only: on `/all` the two stacks
do not run the same workload, because MongoDB has no coroutine path in Swoole
(`ext-mongodb`/libmongoc is outside the runtime hooks) and its call blocks the whole
worker.

- On the empty handle the ranking is the price of the transport: Swoole answers
  from a C event loop in the same process as the PHP closure, while SConcur is
  ~2.9× RoadRunner because crossing the PHP↔Go boundary is cheaper than
  RoadRunner's IPC hop proxy → worker per request.
- On `/all` with disk backends both concurrent servers are ~6–7× RoadRunner and
  land in the same class. The reason is fsync: 3 writes per request fold into a
  chain of disk commits in a sequential worker (p50 ≈ 0.57 s at ~158% CPU), while
  both concurrent models overlap those same fsyncs across dozens of parked
  requests — the ceiling stops being the server and becomes the disk.
- CPU and memory go to Swoole (~237% against ~565%, ~126 MiB against ~279 MiB at
  comparable throughput; part of that saving lands on the backends, which burn
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

### Empty handle: the worker-count ladder

The three stacks on `/` (200 "ok", no I/O) across worker counts — the pure price
of each server layer as it scales over cores. Same wrk harness (4 threads / 256
connections / 20 s, median of 3), all three stacks in one session (2026-08-12).
The harness pins workers to the first N cores and wrk to the rest, so at 16
workers the generator shares cores with the server — that is what caps Swoole's
16-worker row, not the server itself.

| Workers | RoadRunner rps / p50 / p99 | SConcur rps / p50 / p99 | Swoole rps / p50 / p99 | SConcur vs RR | SConcur vs Swoole |
| ---: | --- | --- | --- | :---: | :---: |
| 1 | 7 970 / 31.5 ms / 36.2 ms | 23 845 / 8.4 ms / 155 ms | 183 429 / 1.4 ms / 1.9 ms | +199% ✅ | −87% ❌ |
| 3 | 14 080 / 17.9 ms / 22.0 ms | 46 035 / 3.8 ms / 117 ms | 440 814 / 0.4 ms / 1.7 ms | +227% ✅ | −90% ❌ |
| 8 | 31 932 / 7.9 ms / 9.3 ms | 90 175 / 2.6 ms / 45.3 ms | 584 657 / 0.2 ms / 0.7 ms | +182% ✅ | −85% ❌ |
| 16 | 51 034 / 4.9 ms / 6.9 ms | 141 176 / 1.3 ms / 61.8 ms | 353 414 / 0.4 ms / 0.5 ms | +177% ✅ | −60% ❌ |

- All three scale near-linearly until the cores run out; the per-request price
  keeps the ranking constant: SConcur holds ~2.8–3.3× RoadRunner on every rung,
  Swoole's C event loop stays far ahead of both.
- The SConcur p99 tails at small worker counts are queueing, not work: 256
  connections multiplex onto one PHP thread, so the median stays low (8.4 ms
  against RoadRunner's 31.5 ms on one worker) while the tail absorbs the queue.
  RoadRunner queues at accept instead — tight tails, high median.

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
| 1 | 5 280 / 47.3 ms / 62.9 ms | 9 957 / 24.3 ms / 45.7 ms (150) | 46 225 / 5.4 ms / 7.2 ms (150) | +89% ✅ | −78% ❌ |
| 3 | 10 282 / 24.4 ms / 31.2 ms | 19 779 / 11.4 ms / 118 ms (50×3) | 100 686 / 2.5 ms / 3.8 ms (50×3) | +92% ✅ | −80% ❌ |
| 8 | 23 665 / 10.6 ms / 12.6 ms | 38 617 / 6.2 ms / 19.3 ms (18×8) | 123 359 / 1.9 ms / 6.3 ms (18×8) | +63% ✅ | −69% ❌ |
| 16 | 28 272 / 8.7 ms / 74.2 ms | 37 860 / 6.1 ms / 40.1 ms (9×16) | 113 880 / 2.0 ms / 7.2 ms (9×16) | +34% ✅ | −67% ❌ |

- Against RoadRunner the SConcur advantage tapers with pool size — around +90%
  on small pools, +34% at the full per-core pool, where both approach the
  shared hardware ceiling (MySQL plus total CPU); SConcur now peaks at 8
  workers and holds a clear lead on every rung.
- Swoole is 3–5× ahead of both, and this is where SConcur's price is most
  visible. Both saturate one PHP thread on a single worker (~101% CPU), but
  that thread buys ≈10k rps for SConcur (~102 µs of CPU per request) against
  ≈46k for Swoole (~21 µs): the hooked `mysqlnd` never leaves the process. The
  work moves to the database accordingly — at one worker the mysql container
  burns ~194% CPU under Swoole against ~35% under SConcur.
- Tails: RoadRunner is tight until the full pool and then breaks (p99 74 ms at
  16 workers); SConcur multiplexes dozens of in-flight requests per thread and
  holds p99 40 ms; Swoole stays under 8 ms everywhere.
- Pool sizing: the pool lives per process, so the DB connection budget is divided
  across processes — 16 processes × a 150-connection pool against
  `max_connections = 151` turns a third of the responses into "Too many connections".
  The useful size is the expected in-flight per process plus a small margin; raising
  `max_connections` to 500 and inflating the pools moved nothing (±1%).

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
| 1 | 96 / 1.03 s / 1.98 s | 2 468 / 92.5 ms / 312 ms (150) | 2 657 / 87 ms / 337 ms (150) | ×26 ✅ | −7% ❌ |
| 3 | 204 / 1.24 s / 1.46 s | 2 535 / 88.2 ms / 444 ms (50×3) | 2 681 / 88 ms / 347 ms (50×3) | ×12 ✅ | −5% ❌ |
| 8 | 425 / 606 ms / 657 ms | 2 529 / 89.7 ms / 385 ms (18×8) | 2 654 / 87 ms / 304 ms (18×8) | ×6.0 ✅ | −5% ❌ |
| 16 | 754 / 343 ms / 433 ms | 2 471 / 91.2 ms / 366 ms (9×16) | 2 593 / 88 ms / 323 ms (9×16) | ×3.3 ✅ | −5% ❌ |

- One disk commit per request flips the ladder. Both concurrent stacks stand on the
  same ceiling from the first worker on (≈2.3–2.7k rps, flat across the ladder) —
  cross-request overlap folds the commits of dozens of in-flight requests into group
  commits, and the limit becomes MySQL itself (~1 300–1 380% CPU on the mysql
  container while php sits at 24–62% for Swoole and 93–211% for SConcur). Swoole's
  5–7% edge is the boundary tax, not a different ceiling.
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
- The boundary is cheap on the fan-out itself: socket/ws throughput ~112–171k
  round-trips/s, the empty HTTP handle ~133.5k rps (~2.9× RoadRunner).
- Against RoadRunner and Swoole on disk backends: on `/all` both concurrent models
  are ~6–7× the sequential worker and in the same class. Swoole holds it on ~237%
  CPU against ~565%, but its own in-request fan-out adds only +13% because
  `ext-mongodb` is outside its runtime hooks. On cheap point queries the ranking
  reverses — ≈46k rps against ≈10k on the same core, which is the boundary tax.
- Memory is practically flat across the modes — the fibers of a 100-wide fan-out do
  not move the peak noticeably.
