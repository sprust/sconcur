English | [Русский](benchmarks.ru.md)

# Feature benchmarks

Measurements per feature — a feature is one of the library's integrations in
`src/Features/` (MongoDB, MySQL, Redis, the HTTP client and so on); all of them
except `Sleeper` are here. What a call across the PHP↔extension boundary costs
against the native driver, and what running several such calls at the same time
gains. Reference numbers, not a guarantee — they depend on hardware, DB settings
and load. The workload-matching verdict table is in
[positioning](positioning.md#is-sconcur-for-you).

> The `sync` column carries a fixed overhead that is not inherent to the approach:
> a synchronous call (outside a `WaitGroup` and outside the servers) opens a
> one-off task group in the extension for every call, so one operation costs three
> calls into the extension — `push`, `wait`, `stopFlow`. Optimizing that path is
> deferred (see the [roadmap](../README.md#roadmap)). Until then the meaningful
> comparison is `native` vs `async`.

## Contents

- [Environment](#environment)
- [Conversion overhead (the PHP↔extension boundary)](#conversion-overhead-the-phpextension-boundary)
- [Methodology](#methodology)
- [MongoDB](#mongodb)
- [MySQL](#mysql)
- [PostgreSQL](#postgresql)
- [Payload size](#payload-size)
- [AMQP (RabbitMQ)](#amqp-rabbitmq)
- [Redis](#redis)
- [Files](#files)
- [Clients (HTTP / Socket / WebSocket)](#clients-http--socket--websocket)
- [Servers (HTTP / Socket / WebSocket)](#servers-http--socket--websocket)
  - [HTTP throughput: the empty endpoint](#http-throughput-the-empty-endpoint)
  - [Comparison with RoadRunner and Swoole](#comparison-with-roadrunner-and-swoole)
  - [Empty endpoint: the worker-count ladder](#empty-endpoint-the-worker-count-ladder)
  - [Point query: the worker-count ladder](#point-query-the-worker-count-ladder)
    - [Write and read: `/db-rw`](#write-and-read-db-rw)
- [Conclusions](#conclusions)

## Environment

Intel Core i7-13620H: 6 performance cores with two threads each (CPU 0–11) and 4
efficiency cores (CPU 12–15). At 12 workers the HTTP harnesses put the servers
on 0–11 and `wrk` on 12–15. 15 GiB RAM, NVMe SSD (Samsung, 1 TB), Ubuntu 26.04
with kernel 7.0, Docker 29.1, power profile Performance. Everything runs in
Docker: the benchmarks from the `php` container (`make bench-*`), the server
pools from the `servers` container (2 workers per group, `SO_REUSEPORT`).
Component versions — see [Tested versions](../README.md#tested-versions).

DB data lives on the host disk (SSD), as in a real deployment: writes pay a real
fsync, hot reads come from the DB cache. By default `docker-compose.yml` keeps the
data in `tmpfs`; for a benchmark session the named volumes are uncommented instead,
and the state is reset with `make bench-reset` — without it writes accumulate
between runs and the numbers drift.

Skipping that swap does more than drift the numbers. Each `tmpfs` mount is capped
at 1 GiB and a sustained `/all` run fills it well inside an hour — mostly with
`pg_wal` and the MySQL binary log, which no table truncation reclaims. Past the cap
PostgreSQL answers `SQLSTATE 53100` and MySQL can no longer build its internal
temporary tables, so the demo handler returns `500` for every request while the
worker log stays empty: it reports a failed feature in the response body, not to
stderr. The run still prints a plausible requests/sec. Always check
`Non-2xx or 3xx responses` in the wrk output before trusting a long run.

Every number on this page was taken on 2026-10-18, on an idle machine with the
Ubuntu power profile set to Performance, on disk-backed volumes, on the Rust
core after the boundary stopped carrying MessagePack: the feature tables through
their harnesses, the HTTP-server tables with all three stacks in one session and
the placement equalised (see [Methodology](#methodology)).

## Conversion overhead (the PHP↔extension boundary)

Every call crosses the boundary and converts its data: the core reads the
arguments off the PHP array inside the push call and builds the result as PHP
values inside the wait call; Mongo documents are converted to and from BSON at the
same two points, with the BSON values a PHP array cannot express carried as objects.
This is a fixed CPU price per operation, on top of the boundary call and runtime
task dispatch. On cheap cached reads
it shows up as the `native` → `sync` gap (both sequential, but `sync` goes through
the extension): `pgsql-selectOne` 2.3 → 17.1 ms over 100 calls, `mysql-selectOne`
2.5 → 9.1 ms. On a slow operation the same surcharge is a small fraction of the
total.

## Methodology

Three modes per feature: `native` — the baseline without SConcur
(`mongodb/mongodb`, `PDO`, stream wrappers, raw sockets), sequential; `sync` —
SConcur outside a `WaitGroup` (the `Extension::wait` path), also sequential;
`async` — SConcur inside a `WaitGroup`, N coroutines running at the same time (a
coroutine is a closure the `WaitGroup` runs in a PHP Fiber, suspended while its
operation waits in the extension).

Each DB benchmark ran 5 times, each client/server benchmark 3 times; the tables
show the median. Every DB run starts cold — the table/collection is dropped and
reseeded to 100 000 rows/documents before the measurement — and point operations
work on distinct ids per mode, so no two calls share a hot row and a row lock
cannot force the concurrent calls to run one after another. A discarded warm-up
precedes each measurement, otherwise the concurrent calls would pay for spinning
up the connection pool inside the measured phase; the SQL pools are
`maxOpenConns: 50`. Memory is
the peak of `memory_get_peak_usage(true)` per mode: what PHP's allocator took,
not counting what the extension allocates on its own side.

Calls per mode in the tables: 100, 50 for client I/O benchmarks. Three MongoDB
benchmarks are bounded by the operation's nature: `createIndex` and `bulkWrite`
20, `updateMany` 10. Single runs: `make bench-<name> c=<count>` (without `c` a
script makes 5 calls); the whole DB session is `make bench-db-runs`, and every
table on this page and on the pages that quote it is `make bench-docs`, which
writes the raw results of one session to `.bench-runs/docs/`. The scripts live
in `tests/benchmarks/`, one directory per measured technology (`mongodb/`,
`mysql/`, `pgsql/`, `http/`, `socket/`, `ws/`, `amqp/`, `redis/`, `files/`), so
`make bench-mysql-selectOne` runs `tests/benchmarks/mysql/select-one.php`.

`async vs native` is the signed percent `(native − async) / native`, ✅ when
`async` is faster; in the DB tables each of the median, min and max columns
carries its own, which shows the spread across runs. In the server comparison
the `rps vs RoadRunner`/`rps vs Swoole` columns compare throughput against that
stack's row for the same endpoint. Sub-50 ms rows are noise-sensitive — a sign
flip between a row's `min` and `max` marks exactly that.

The server rows are taken with the workers and the load generator on
non-overlapping cores, because that is what makes a run comparable to the next
one. Production does not pin, and the difference is not zero — see
[CPU pinning](load-testing.md#cpu-pinning) for the measurements.

Two things have to be equal before the stacks can be compared, and neither is
by default. Placement: `load-stats.sh` defaults to `PIN_SERVERS=1`, one
worker per logical CPU, while `rr-load-stats.sh` and `swoole-load-stats.sh` can
only `taskset` the master and let it fork — the `group` placement. The core
budget is the same either way, the placement is not, and it is worth 6.5% on the
empty endpoint. Every server row below runs `group`; what `pin=1` costs is
measured separately in [CPU pinning](load-testing.md#cpu-pinning). Table
state: the three stacks share `load_all` and `bench_rw`, only `load-stats.sh`
empties the first, and none of the three load scripts empties the second, so
whichever stack ran later inherited a bigger table. The rows below were taken
with both truncated before every single run (`make bench-docs` truncates
`bench_rw` before every `/db-rw` run).

Re-measure all three stacks in one session: cross-session drift alone reaches
±20%, and a reference row carried over from an older session is what made the
previous version of these tables incomparable.

Check the power profile before a session (`powerprofilesctl get`) and keep it
the same throughout. It moves the absolute numbers on its own: the empty
endpoint, 12 workers, gave 11.5% more rps under Performance than under
Balanced, runs interleaved on one build.

## MongoDB

Everything gains except `insertMany`. What makes the server chew through the
dataset gains most — `count` ~6.7×, `bulkWrite` ~6×, `updateMany` ~5.6×,
`createIndex` +26% — and single-document operations gain too, by 36–68%.

Median of 5 runs against a cold dataset of 100 000 documents. Cells hold
`native / sync / async`, ms, with the `async vs native` percent in parentheses.

| Operation | count | native / sync / async, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | ---: | --- |
| insertOne | 100 | 4.0 / 8.9 / 2.6 (+36% ✅) | 3.9 / 5.3 / 2.2 (+44% ✅) | 4.4 / 13.3 / 3.4 (+22% ✅) | 10 / 10 / 10 |
| insertMany | 100 | 17.5 / 51.5 / 23.8 (−36% ❌) | 17.1 / 49.7 / 21.3 (−25% ❌) | 33.2 / 60.9 / 33.2 (0%) | 10 / 10 / 10 |
| bulkWrite | 20 | 2799 / 2763 / 450 (+84% ✅) | 2697 / 2678 / 419 (+84% ✅) | 2841 / 2820 / 510 (+82% ✅) | 8 / 8 / 8 |
| updateOne | 100 | 4.8 / 9.5 / 2.1 (+57% ✅) | 4.7 / 7.1 / 2.0 (+58% ✅) | 5.9 / 14.4 / 3.6 (+39% ✅) | 10 / 10 / 10 |
| updateMany | 10 | 1432 / 1342 / 254 (+82% ✅) | 1407 / 1334 / 248 (+82% ✅) | 1656 / 1600 / 317 (+81% ✅) | 8 / 8 / 8 |
| deleteOne | 100 | 5.9 / 8.1 / 1.9 (+68% ✅) | 4.1 / 5.2 / 1.8 (+57% ✅) | 8.5 / 10.7 / 5.9 (+30% ✅) | 10 / 10 / 10 |
| findOne | 100 | 4.1 / 6.9 / 2.3 (+43% ✅) | 3.9 / 5.1 / 2.0 (+48% ✅) | 5.5 / 8.6 / 2.6 (+53% ✅) | 10 / 10 / 10 |
| aggregate | 100 | 9.3 / 11.1 / 4.1 (+56% ✅) | 8.8 / 10.8 / 3.7 (+58% ✅) | 16.7 / 13.6 / 4.5 (+73% ✅) | 10 / 10 / 10 |
| count | 100 | 1829 / 1843 / 275 (+85% ✅) | 1778 / 1809 / 267 (+85% ✅) | 1889 / 1884 / 307 (+84% ✅) | 10 / 10 / 10 |
| command | 100 | 2.7 / 3.5 / 1.6 (+39% ✅) | 2.7 / 3.4 / 1.5 (+45% ✅) | 9.3 / 4.9 / 2.1 (+77% ✅) | 6 / 6 / 6 |
| createIndex | 20 | 1772 / 1758 / 1304 (+26% ✅) | 1730 / 1705 / 1227 (+29% ✅) | 1794 / 1804 / 1347 (+25% ✅) | 8 / 8 / 8 |

async wins biggest where a call makes the server chew through the dataset —
every `count` scans all 100k documents, every `updateMany` rewrites them, the
unindexed `bulkWrite` filters scan the collection several times per call.

Single-document operations gain as well: `findOne` +43%, `updateOne` +57%,
`deleteOne` +68%, `insertOne` +36%. MongoDB pays no per-operation fsync on the
default write concern, so there is no I/O wait to hide other work behind; what
the concurrent mode overlaps here is the round-trip to the server, and that is
worth more than the boundary conversion costs. `insertMany` is the exception —
it already batches its documents into one round-trip, so there is nothing left
to overlap.

## MySQL

Disk writes run concurrently are ~19–22× faster (their fsyncs happen in
parallel), `transaction` ~20×, `count` ~2×; cheap reads stay with PDO. Median
of 5 runs against a cold dataset of 100 000 rows, columns as for MongoDB.

| Operation | count | native / sync / async, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | ---: | --- |
| insert | 100 | 565 / 598 / 29.2 (+95% ✅) | 546 / 570 / 27.6 (+95% ✅) | 686 / 670 / 33.7 (+95% ✅) | 6 / 6 / 6 |
| selectOne | 100 | 2.5 / 9.1 / 3.0 (−19% ❌) | 2.5 / 7.0 / 2.5 (−2% ❌) | 2.8 / 13.4 / 3.8 (−37% ❌) | 6 / 6 / 6 |
| selectMany | 100 | 5.9 / 22.2 / 13.2 (−122% ❌) | 5.8 / 19.5 / 12.4 (−114% ❌) | 8.8 / 23.3 / 14.8 (−69% ❌) | 6 / 6 / 10 |
| count | 100 | 113 / 129 / 55.3 (+51% ✅) | 101 / 127 / 51.3 (+49% ✅) | 132 / 174 / 64.0 (+51% ✅) | 6 / 6 / 6 |
| update | 100 | 563 / 568 / 25.3 (+96% ✅) | 552 / 561 / 21.2 (+96% ✅) | 569 / 583 / 29.3 (+95% ✅) | 6 / 6 / 6 |
| delete | 100 | 543 / 584 / 28.3 (+95% ✅) | 540 / 573 / 24.9 (+95% ✅) | 561 / 598 / 29.2 (+95% ✅) | 6 / 6 / 6 |
| transaction | 100 | 558 / 577 / 28.2 (+95% ✅) | 538 / 568 / 27.0 (+95% ✅) | 572 / 583 / 28.5 (+95% ✅) | 6 / 6 / 6 |

## PostgreSQL

Writes run concurrently are ~13–19× faster, `count` ~7.5×; point reads stay with
PDO.

| Operation | count | native / sync / async, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | ---: | --- |
| insert | 100 | 101 / 123 / 5.3 (+95% ✅) | 100 / 123 / 5.1 (+95% ✅) | 103 / 135 / 6.0 (+94% ✅) | 6 / 6 / 6 |
| selectOne | 100 | 2.3 / 17.1 / 3.7 (−63% ❌) | 2.1 / 11.3 / 2.7 (−26% ❌) | 4.7 / 21.8 / 4.1 (+12% ✅) | 6 / 6 / 6 |
| selectMany | 100 | 5.3 / 26.5 / 13.2 (−151% ❌) | 5.2 / 25.0 / 11.7 (−125% ❌) | 7.0 / 39.6 / 15.1 (−117% ❌) | 6 / 6 / 10 |
| count | 100 | 234 / 267 / 31.2 (+87% ✅) | 224 / 253 / 31.1 (+86% ✅) | 237 / 279 / 34.0 (+86% ✅) | 6 / 6 / 6 |
| update | 100 | 102 / 127 / 5.8 (+94% ✅) | 101 / 124 / 5.4 (+95% ✅) | 107 / 132 / 7.5 (+93% ✅) | 6 / 6 / 6 |
| delete | 100 | 103 / 122 / 6.0 (+94% ✅) | 101 / 118 / 5.1 (+95% ✅) | 122 / 133 / 6.3 (+95% ✅) | 6 / 6 / 6 |
| transaction | 100 | 106 / 132 / 8.4 (+92% ✅) | 102 / 125 / 8.0 (+92% ✅) | 107 / 141 / 12.0 (+89% ✅) | 6 / 6 / 6 |

The disk flips the SQL picture on writes: every committed write pays an fsync,
the sequential modes sum it over all 100 calls, and `async` waits for all of
them in parallel. `count` over the 100 000-row table also goes to async (pgsql's
`COUNT(*)` scans the heap): a read that makes the server work is worth running
concurrently too. What stays with native are cheap cached point reads
(`selectOne`) and `selectMany`, where the row-set conversion at the boundary
dominates.

## Payload size

Up to ~64 KB per operation the boundary cost is negligible; megabyte blobs
belong to the native driver, and many large results at once cost RSS ≈ number of
concurrent operations × payload.

On the async path the payload crosses the boundary twice — the parameters read
off the PHP array on the way in, the result built as PHP values on the way out —
so the boundary cost grows with the payload while the gain from concurrency does
not. Six dedicated benches
(`tests/benchmarks/{mongodb,mysql,pgsql}/payload-{write,read}.php`) move an
incompressible base64 payload of `SCONCUR_BENCH_PAYLOAD_BYTES` bytes per call;
read re-reads one hot row per mode, so the measured path is transfer + decode,
not disk. Single runs: `make bench-mysql-payloadWrite p=65536 c=100`. Median of
5 cold runs, columns as above.

Payload 1 KB, 100 calls:

| Operation | median n/s/a, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | --- |
| mongodb insertOne | 4.2 / 5.8 / 2.0 (+53% ✅) | 4.0 / 4.8 / 1.8 (+54% ✅) | 5.6 / 7.8 / 2.6 (+54% ✅) | 6 / 6 / 6 |
| mongodb findOne | 4.2 / 7.4 / 2.1 (+50% ✅) | 4.1 / 4.8 / 1.8 (+56% ✅) | 7.8 / 9.1 / 3.8 (+51% ✅) | 6 / 6 / 6 |
| mysql insert | 593 / 625 / 98.1 (+83% ✅) | 569 / 613 / 93.5 (+84% ✅) | 607 / 678 / 100 (+83% ✅) | 6 / 6 / 6 |
| mysql selectOne | 2.7 / 8.0 / 2.7 (−1% ❌) | 2.5 / 7.0 / 2.4 (+1% ✅) | 5.8 / 16.1 / 8.1 (−40% ❌) | 6 / 6 / 6 |
| pgsql insert | 102 / 124 / 5.9 (+94% ✅) | 100.0 / 119 / 5.4 (+95% ✅) | 104 / 129 / 6.9 (+93% ✅) | 6 / 6 / 6 |
| pgsql selectOne | 2.4 / 20.5 / 3.4 (−42% ❌) | 2.2 / 11.1 / 3.1 (−37% ❌) | 6.0 / 21.8 / 3.8 (+36% ✅) | 6 / 6 / 6 |

Payload 64 KB, 100 calls:

| Operation | median n/s/a, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | --- |
| mongodb insertOne | 13.1 / 12.2 / 5.0 (+62% ✅) | 12.3 / 10.4 / 4.7 (+62% ✅) | 15.6 / 13.0 / 5.9 (+62% ✅) | 6 / 6 / 6 |
| mongodb findOne | 9.9 / 8.6 / 3.8 (+62% ✅) | 9.3 / 7.9 / 3.7 (+60% ✅) | 10.6 / 9.4 / 3.8 (+64% ✅) | 6 / 6 / 12 |
| mysql insert | 778 / 752 / 83.3 (+89% ✅) | 757 / 743 / 80.3 (+89% ✅) | 955 / 815 / 92.3 (+90% ✅) | 6 / 6 / 6 |
| mysql selectOne | 4.9 / 12.8 / 6.5 (−32% ❌) | 4.5 / 11.9 / 5.0 (−10% ❌) | 12.9 / 24.9 / 10.5 (+19% ✅) | 6 / 6 / 12 |
| pgsql insert | 123 / 177 / 20.6 (+83% ✅) | 120 / 171 / 19.7 (+84% ✅) | 131 / 193 / 25.4 (+81% ✅) | 6 / 6 / 6 |
| pgsql selectOne | 9.2 / 31.9 / 6.5 (+29% ✅) | 8.8 / 22.7 / 6.2 (+29% ✅) | 15.3 / 41.7 / 8.9 (+42% ✅) | 6 / 6 / 12 |

Payload 1 MB, 50 calls:

| Operation | median n/s/a, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | --- |
| mongodb insertOne | 108 / 75.2 / 47.3 (+56% ✅) | 106 / 63.3 / 39.6 (+63% ✅) | 186 / 95.1 / 149 (+20% ✅) | 6 / 6 / 6 |
| mongodb findOne | 63.6 / 27.0 / 22.3 (+65% ✅) | 62.1 / 25.8 / 20.1 (+68% ✅) | 66.9 / 28.6 / 23.0 (+66% ✅) | 8 / 8 / 106 |
| mysql insert | 1322 / 1069 / 436 (+67% ✅) | 1250 / 1039 / 414 (+67% ✅) | 1562 / 1313 / 456 (+71% ✅) | 10 / 8 / 8 |
| mysql selectOne | 28.6 / 43.5 / 49.2 (−72% ❌) | 27.4 / 38.4 / 44.8 (−64% ❌) | 29.7 / 44.7 / 55.8 (−88% ❌) | 12 / 14 / 112 |
| pgsql insert | 247 / 440 / 153 (+38% ✅) | 192 / 431 / 129 (+33% ✅) | 278 / 504 / 213 (+24% ✅) | 6 / 6 / 6 |
| pgsql selectOne | 69.2 / 100 / 52.9 (+24% ✅) | 63.4 / 98.4 / 49.0 (+23% ✅) | 96.2 / 109 / 56.8 (+41% ✅) | 8 / 8 / 106 |

1. Writes: concurrency wins while fsync dominates, but the margin shrinks as the
   payload grows — pgsql insert goes +94% → +83% → +38% at 1 MB, because
   PostgreSQL commits a large row cheaply via TOAST and the transfer cost
   catches up.
2. Reads split by driver. MongoDB gains at every size (+50% at 1 KB, +62% at
   64 KB, +65% at 1 MB); `mysql selectOne` loses at every size, and
   `pgsql selectOne` loses at 1 KB and gains from 64 KB on. A point read has
   no waiting time to hide other work behind, and the payload pays the boundary
   both ways.
3. Memory is the main finding. The async column at 1 MB reads 106–112 MB
   against 8–12 MB for native: 50 concurrent 1 MB reads hold all 50 results in
   memory at once. It already shows at 64 KB, where the async reads sit at
   12 MB against 6. Limit how many such operations run at the same time
   (`WaitGroup::create(maxConcurrency: N)`) on large result sets, and move
   megabyte blobs through the native driver or a path that never crosses the
   boundary (like `HttpClient::download()`).

## AMQP (RabbitMQ)

Publishing is where the native extension wins and nothing can be done about it;
`basic.get` run concurrently lands around it; consuming a queue that is already
full is the extension's too. The gain is elsewhere — see below the tables.

Median of 5 runs, 1000 calls per mode, columns as for MongoDB. The concurrent mode
spreads its calls over 50 channels, because a channel is serialized on the broker;
the native and the synchronous modes use one, as an application would.

| Operation | count | native / sync / async, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | ---: | --- |
| publish | 1000 | 3.7 / 20.9 / 16.6 (−343% ❌) | 3.4 / 19.2 / 15.7 (−361% ❌) | 6.6 / 26.9 / 17.1 (−160% ❌) | 22 / 22 / 22 |
| get | 1000 | 22.8 / 46.3 / 18.4 (+19% ✅) | 21.7 / 39.9 / 17.5 (+20% ✅) | 24.0 / 49.0 / 19.9 (+17% ✅) | 22 / 22 / 22 |

Consuming a pre-filled set of queues, 10 queues × 200 messages, median of 5 runs:

| Measurement | native | sync | async |
| --- | ---: | ---: | ---: |
| messages per second | 147 567 | 71 368 | 161 967 |

`basic.publish` expects no reply, so it costs one write on the wire while every
SConcur call also crosses the PHP ↔ extension boundary — there is nothing to
overlap and the crossing is the whole difference. `basic.get` does wait for the
broker, and running the calls at the same time recovers all of it: the concurrent
mode comes out ahead of native and takes well under half of the synchronous time.

The three consuming numbers move more between runs than any other table here —
read them as orders of magnitude, not as figures to compare percentages on.

What the tables do not measure is the reason the feature exists. They pit one
call against one call on a queue that already has its messages. The gain is a
worker that waits on several queues at once: consuming holds the PHP thread in
both `ext-amqp` and `php-amqplib`, so a process is pinned to one queue, while
here the same loop suspends only its coroutine — three consumers waiting on a
200 ms delay finish in one delay, not three
(`tests/feature/Features/Amqp/AmqpConsumeTest.php`). That is throughput a
single-queue benchmark cannot show.

## Redis

phpredis wins every shape measured here, and the reason is the latency it is
measured against: a `GET` on a Redis in the same container answers in about 11 µs,
which is less than one crossing of the PHP ↔ extension boundary costs. There is
no server wait to overlap, so the concurrency has nothing to recover and the
crossing is the whole difference.

Median of 5 runs, columns as for MongoDB; min and max are the fastest and the
slowest of those runs. `get` and `set` make 2000 calls; `pipeline` makes 200
calls of 20 commands each, so both sides send 4000 commands.

| Operation | count | native / sync / async, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | ---: | --- |
| get | 2000 | 17.6 / 37.8 / 31.8 (−81% ❌) | 16.8 / 36.4 / 29.7 (−76% ❌) | 18.4 / 47.1 / 33.4 (−82% ❌) | 40 / 40 / 42 |
| set | 2000 | 17.9 / 41.9 / 32.0 (−79% ❌) | 17.7 / 41.0 / 31.3 (−77% ❌) | 19.3 / 51.3 / 32.7 (−69% ❌) | 40 / 40 / 42 |
| pipeline (20 commands) | 200 | 4.2 / 10.5 / 5.8 (−38% ❌) | 4.1 / 10.3 / 5.7 (−38% ❌) | 5.8 / 13.6 / 6.1 (−5% ❌) | 10 / 10 / 10 |

Batching is what closes the gap: a pipeline pays one crossing for twenty commands
instead of twenty, and the async path lands within about 40% of the native
extension. A command at a time costs about 1.8× it.

Value size, 500 `GET` calls of one value, single run:

| Value | native / sync / async, ms | Memory n/s/a, MB |
| --- | ---: | --- |
| 100 B | 4.3 / 12.6 / 6.9 | 12 / 12 / 12 |
| 10 KB | 5.6 / 11.0 / 7.8 | 14 / 14 / 14 |
| 1 MB | 438 / 159 / 457 | 16 / 14 / 140 |

A megabyte value is where the concurrent path turns from slower into wrong for
the job: 500 of them running at once hold 140 MB against the native driver's
16, because every result exists in the extension and in PHP at the same time.
Read blobs one at a time, or through a path that never crosses the boundary.

`poolSize`, 2000 concurrent `GET` calls, single run
(`make bench-redis-pool-size c="2000 0 <poolSize>"`): 1 → 51.3 ms, 2 → 45.6 ms,
4 → 45.1 ms, 8 → 44.9 ms. One multiplexed connection already carries every
concurrent command; a second one buys the last 11%, and past two the line is
flat. The default is 4, which is that flat part with room for a large value in
flight not to hold up what is behind it.

What the tables do not measure is the reason the feature exists. They time a
process whose only job is Redis. In a request handler the phpredis call blocks
the whole worker for its 11 µs plus whatever the network adds, while this one
suspends one coroutine and the worker serves other requests; and against a Redis
across a network, where a round trip is a millisecond rather than eleven
microseconds, it is the round trips that overlap rather than the crossings that
accumulate. Neither shows up in a benchmark that runs one call at a time on
localhost.

## Files

The Files tables share the session of the feature tables. Their files live in the
container's own filesystem
(`sys_get_temp_dir()` inside the `php` service), not on the disk-backed volumes
the database rows use — which for a file benchmark is the decisive fact about
the environment, and the reason the page gives every size from a kilobyte to
64 MiB rather than one.

The only feature here whose verdict turns on a number the caller picks: the size
of the file. On a kilobyte with a warm page cache the boundary crossing is the
whole measurement; on sixty-four megabytes it is a rounding error next to the
disk. So every row below is given at both ends rather than at one.

Single run each: `c="20 0"` at 1 KiB, `c="30 0"` at 1 MiB and 10 MiB, `c="6 0"`
at 64 MiB, the size in bytes as `bytes=`
(`make bench-files-read bytes=1024 c="20 0"`). Each mode works on files of its
own, so the page cache is not handed from the column that ran first to the ones
after it.

| Operation | Size | count | native / sync / async, ms | Memory n/s/a, MB |
| --- | --- | ---: | ---: | --- |
| read | 1 KiB | 20 | 0.20 / 1.2 / 1.1 (−440% ❌) | 4 / 4 / 4 |
| read | 1 MiB | 30 | 2.4 / 10.6 / 16.7 (−606% ❌) | 4 / 4 / 34 |
| read | 64 MiB | 6 | 108 / 302 / 252 (−133% ❌) | 68 / 68 / 324 |
| write | 1 KiB | 20 | 0.27 / 1.3 / 0.39 (−46% ❌) | 4 / 4 / 4 |
| write | 1 MiB | 30 | 5.2 / 11.0 / 11.8 (−126% ❌) | 4 / 4 / 4 |
| write | 64 MiB | 6 | 174 / 451 / 268 (−54% ❌) | 68 / 68 / 68 |
| copy | 1 KiB | 20 | 0.44 / 1.7 / 1.00 (−125% ❌) | 4 / 4 / 4 |
| copy | 1 MiB | 30 | 6.6 / 13.3 / 7.8 (−18% ❌) | 4 / 4 / 4 |
| copy | 64 MiB | 6 | 184 / 165 / 82.9 (+55% ✅) | 4 / 4 / 4 |
| hashFile (sha256) | 10 MiB | 30 | 179 / 243 / 167 (+7% ✅) | 4 / 4 / 4 |
| hashFile (sha256) | 64 MiB | 6 | 227 / 312 / 196 (+14% ✅) | 4 / 4 / 4 |
| readChunks (64 KiB buffer) | 10 MiB | 30 | 30.9 / 126 / 31.6 (−2% ❌) | 4 / 4 / 8 |
| readChunks (64 KiB buffer) | 64 MiB | 6 | 38.4 / 143 / 35.3 (+8% ✅) | 4 / 4 / 4 |

Directory listing with a size and a time per entry — `scandir()` followed by
`filesize()` and `filemtime()` on every entry against one crossing:

| Entries | count | native / sync / async, ms | Memory n/s/a, MB |
| --- | ---: | ---: | --- |
| 10 000 | 30 | 457 / 731 / 358 (+22% ✅) | 102 / 102 / 102 |
| 100 000 | 5 | 866 / 1358 / 649 (+25% ✅) | 166 / 166 / 168 |

Read in order:

- `copy` is the clearest win, and only past a megabyte. The bytes never
  cross the boundary — the extension streams the file itself — so at 64 MiB the
  synchronous path is already faster than PHP's `copy()` and the concurrent one
  twice as fast. At a megabyte the crossing still costs more than the copy
  saves.
- `list` wins concurrently at any size worth listing. The per-entry syscalls
  stay inside the extension; what crosses is the finished list. The memory column
  is the price: that list exists on both sides at once.
- `hashFile` wins concurrently and loses synchronously, which is the same
  trade every feature here makes. `hash_file()` already reads in a loop without
  holding the file, so the gain is not memory — it is that the loop and its disk
  waits leave the PHP thread.
- `read` and `write` lose on time at every size. The payload crosses the
  boundary, and nothing in the operation overlaps with anything. At 64 MiB the
  concurrent path holds 324 MB against the native 68, because six files exist in
  the extension and in PHP at the same time.
- `readChunks` is the answer to that number, not to the time. The same
  64 MiB read peaks at 4 MB instead of 68, and the concurrent path lands level
  with the native loop. A file larger than the process should never go through
  `read()`.

What the tables do not measure is the reason the feature exists. They time a
process whose only job is files. In a request handler the native call holds the
whole worker for however long the disk takes — microseconds on a warm cache,
milliseconds on a cold one, tens of milliseconds on NFS — while this one suspends
one coroutine and the worker serves other requests. That is a latency tail, not a
throughput number, and it does not show up in a loop that runs one call at a time.

## Clients (HTTP / Socket / WebSocket)

Network waits overlap almost perfectly: the `msleep` endpoint holds the
connection for 100 ms, so 50 sequential calls take ≈5 s while the same 50 run
concurrently in the time of about one call.

| Benchmark | count | native, ms | sync, ms | async, ms | Memory n/s/a, MB | async vs native |
| --- | ---: | ---: | ---: | ---: | --- | :---: |
| http-client (`/msleep/100`) | 50 | 5106 | 5106 | 104 | 4 / 4 / 4 | +98% ✅ |
| socket-client (`msleep:100`) | 50 | 5105 | 5115 | 103 | 4 / 4 / 4 | +98% ✅ |
| ws-client (`msleep:100`) | 50 | 5104 | 5126 | 105 | 4 / 4 / 4 | +98% ✅ |

On I/O latency async gives ~49× (5.1 s → 0.10 s).

`http-client-download` is unmeasured because of an open bug: at 50 concurrent
downloads of a 4 MiB body against a multi-worker pool the server answers `500`
about half the time, with
`fopen(Nyholm-Psr7-Zval://): Failed to open stream: infinite recursion prevented`
in its log — PHP refusing to re-enter the userland stream wrapper Nyholm PSR-7
wraps a string body in. A single worker never reproduces it, and neither does a
pool below ~30 concurrent downloads.

## Servers (HTTP / Socket / WebSocket)

One cooperative process can wait on any number of I/O operations at the same
time; CPU-bound requests rely on the per-core pool, not on the scheduler. A pool
of 2 workers (`SO_REUSEPORT`), 100 concurrent requests/connections per run
(throughput — 50 connections × 2000 round-trips), median of 3 runs, all
responses successful.

| Benchmark | Load | elapsed, s | Throughput |
| --- | --- | ---: | --- |
| http-server-io | 100 × `GET /msleep/1000` (1 s async sleep) | 1.04 | — |
| http-server-cpu | 100 × `GET /cpu/100000` (sha256 loop) | 0.75 | — |
| socket-server-io | 100 × `msleep:1000` round-trip | 1.00 | — |
| socket-server-cpu | 100 × `cpu:100000` round-trip | 0.71 | — |
| socket-throughput | 50 conn × 2000 × `ping` | 0.41 | ≈244 619 rt/s |
| ws-server-io | 100 × `msleep:1000` round-trip | 1.00 | — |
| ws-server-cpu | 100 × `cpu:100000` round-trip | 0.72 | — |
| ws-throughput | 50 conn × 2000 × `ping` | 0.36 | ≈278 715 rt/s |

100 handlers each sleeping 1 s asynchronously finish in ≈one sleep regardless of
the worker count — a single cooperative process already holds all those waits at
once. Throughput measures the pure round-trip price under concurrency. Behaviour
under sustained load is in [load testing](load-testing.md).

The `-cpu` rows scale with the worker count and nothing else: the sha256 loop
never yields, so a single `/cpu/100000` costs ~15 ms and 100 of them take ~0.75 s
across the two workers per group of `config/sconcur.servers.config.json`.

### HTTP throughput: the empty endpoint

Sustained throughput under `wrk` (the `tests/benchmarks/http/load-stats.sh`
script, methodology in [load testing](load-testing.md)): 12 processes in the
`php` container, `wrk` 4 threads / 256 connections / 20 s hitting the bridge IP
directly, 3 runs per endpoint, all responses `200`. `/` returns `200 "ok"` and
does no I/O, so it measures the ceiling of the server and the framework and
nothing else.

| Endpoint | Requests/sec | Latency p50 / p90 / p99 | CPU `php` avg / peak | MEM peak |
| --- | ---: | --- | --- | ---: |
| `/` (empty) | ≈248 303 | 0.84 / 2.1 / 4.2 ms | ~1102% / ~1184% | ~196 MiB |

The pool ceiling is 12 cores (~1200%), and the empty endpoint reaches it. The
median is under a millisecond and the tail stays within five times it: 256
connections are multiplexed onto 12 cooperative processes, so a request that
arrives behind a queue waits for it, and the queue is short. How that holds
across pool sizes is in the
[worker ladder](#empty-endpoint-the-worker-count-ladder).

### Comparison with RoadRunner and Swoole

Three execution models on the same endpoint. On an empty response the ranking is
the price of each transport, and Swoole's C event loop is ahead of everything.

Two reference stacks are measured next to SConcur, both on native drivers and
both committed (`tests/servers/roadrunner/`, `tests/servers/swoole/`) with load
scripts that are copies of `tests/benchmarks/http/load-stats.sh`:
[RoadRunner](https://roadrunner.dev) 2025.1.15 (a request occupies a worker for
its whole duration) and [Swoole](https://swoole.com) 6.2.2 (a coroutine worker
serves many requests at once, blocking drivers become non-blocking via
`hook_flags => SWOOLE_HOOK_ALL`). Conditions are identical: the same `php`
container, 12 workers, `wrk` 4 threads / 256 connections / 20 s, 3 runs
(median), all three in one session (2026-10-18) in the `group` placement — only
in-session numbers are comparable, cross-session drift reaches ±20%.

`/` returns `200 "ok"` in all three; only the driver stack and the execution
model differ.

Honesty checks: every response was `200`. Swoole's row was checked against the
load generator: with 8 generator threads instead of 4 the same endpoint answered
≈490 300 rps, the same figure, so it is the server's ceiling and not the
generator's.

| Server | Requests/sec | p50 / p90 / p99 | CPU avg / peak | MEM peak | rps vs RoadRunner | rps vs Swoole |
| --- | ---: | --- | --- | ---: | :---: | :---: |
| Swoole | ≈490 461 | 0.25 / 0.49 / 0.62 ms | ~292% / ~302% | ~83 MiB | +843% ✅ | — |
| SConcur | ≈248 303 | 0.84 / 2.1 / 4.2 ms | ~1102% / ~1184% | ~196 MiB | +378% ✅ | −49% ❌ |
| RoadRunner | ≈51 984 | 4.7 / 5.6 / 7.0 ms | ~1000% / ~1032% | ~246 MiB | — | −89% ❌ |

- The ranking is the price of the transport. Swoole answers from a C event loop
  in the same process as the PHP closure. SConcur is ~4.8× RoadRunner because
  crossing the PHP↔extension boundary is cheaper than RoadRunner's extra
  inter-process step (proxy → worker) per request.
- The three differ in what they spend to get there. Swoole holds its number on
  ~292% of CPU and 83 MiB; SConcur and RoadRunner both saturate the 12-core
  budget, and SConcur turns it into 4.8× the requests.
- They also differ in the shape of the latency. RoadRunner queues at accept, so
  its median is the highest and its tail is the tightest — p99 is 1.5× p50.
  SConcur multiplexes 256 connections onto 12 cooperative processes: the median
  is 5.6× lower and the tail is 5× the median. Which of the two matters
  depends on whether a slow request may be paid for by a fast one.

### Empty endpoint: the worker-count ladder

The three stacks on `/` (200 "ok", no I/O) across worker counts — the pure price
of each server layer as it scales over cores. Same wrk harness (4 threads / 256
connections / 20 s, median of 3), all three stacks in one session (2026-10-18).
The script pins workers to the first N cores and wrk to the rest, so at 16
workers the generator shares cores with the server — that is what caps the
16-worker row of Swoole and SConcur alike, not the servers themselves.

| Workers | RoadRunner rps / p50 / p99 | SConcur rps / p50 / p99 | Swoole rps / p50 / p99 | SConcur rps vs RR | SConcur rps vs Swoole |
| ---: | --- | --- | --- | :---: | :---: |
| 1 | 10 071 / 24.9 ms / 30.1 ms | 54 807 / 4.6 ms / 6.8 ms | 196 052 / 1.3 ms / 1.6 ms | +444% ✅ | −72% ❌ |
| 3 | 18 074 / 13.9 ms / 18.7 ms | 93 424 / 2.0 ms / 5.0 ms | 500 516 / 0.51 ms / 1.1 ms | +417% ✅ | −81% ❌ |
| 8 | 38 433 / 6.5 ms / 8.8 ms | 189 817 / 1.2 ms / 4.1 ms | 702 095 / 0.20 ms / 0.55 ms | +394% ✅ | −73% ❌ |
| 16 | 57 706 / 4.2 ms / 7.2 ms | 145 144 / 0.93 ms / 3.2 ms | 482 912 / 0.27 ms / 0.53 ms | +152% ✅ | −70% ❌ |

- All three scale near-linearly until the cores run out, and SConcur holds
  ~5× RoadRunner up to eight workers and 2.5× at sixteen, where it shares cores
  with the generator. Swoole's C event loop stays far ahead of both, −70 to −81%
  on every rung.
- RoadRunner queues at accept: its median is the highest on every rung and its
  p99 never exceeds 1.7× its p50. SConcur multiplexes connections onto
  cooperative processes, so the median is far lower and the tail, 1.5–3.4× the
  median, carries the queue instead.

### Point query: the worker-count ladder

The same three stacks on the cheapest possible request (the `/db` endpoint of
the demo servers): one point SELECT of a random id per request, JSON response.
SConcur runs it through the MySQL feature with no `WaitGroup`, so the only
concurrency is between different requests; RoadRunner and Swoole go through PDO.
Disk-backed MySQL, default `max_connections = 151`; the per-process pool of
SConcur and Swoole is sized to fit that limit, RoadRunner holds one connection
per worker. Same wrk setup, one session.

| Workers | RoadRunner rps / p50 / p99 | SConcur rps / p50 / p99 (pool) | Swoole rps / p50 / p99 (pool) | SConcur rps vs RR | SConcur rps vs Swoole |
| ---: | --- | --- | --- | :---: | :---: |
| 1 | 6 491 / 37.4 ms / 65.5 ms | 16 229 / 15.7 ms / 21.3 ms (150) | 53 223 / 4.6 ms / 7.2 ms (150) | +150% ✅ | −70% ❌ |
| 3 | 12 745 / 19.3 ms / 28.3 ms | 35 901 / 6.7 ms / 217 ms (50×3) | 111 550 / 2.4 ms / 4.6 ms (50×3) | +182% ✅ | −68% ❌ |
| 8 | 27 452 / 8.9 ms / 13.7 ms | 51 656 / 4.6 ms / 15.7 ms (18×8) | 136 151 / 1.6 ms / 8.2 ms (18×8) | +88% ✅ | −62% ❌ |
| 16 | 32 096 / 7.5 ms / 12.0 ms | 44 366 / 5.3 ms / 18.3 ms (9×16) | 128 552 / 1.8 ms / 8.2 ms (9×16) | +38% ✅ | −65% ❌ |

- Against RoadRunner the SConcur advantage tapers with pool size — +150% on a
  single worker, +38% at the full per-core pool, where both approach the shared
  hardware ceiling (MySQL plus total CPU). SConcur peaks at 8 workers.
- Swoole is 2.6–3.3× ahead of SConcur and 4–9× ahead of RoadRunner, and this is
  where SConcur's price is most visible. Both saturate one PHP thread on a
  single worker (~101% CPU), but that thread buys ≈16k rps for SConcur (~62 µs
  of CPU per request) against ≈53k for Swoole (~19 µs): the hooked `mysqlnd`
  never leaves the process. The work moves to the database accordingly — at one
  worker the mysql container burns ~183% CPU under Swoole against ~89% under
  SConcur.
- Tails: RoadRunner stays tight throughout (p99 under 2× its p50); Swoole under
  9 ms everywhere; SConcur holds p99 16–21 ms except on the three-worker rung,
  where an unexplained tail puts it at 217 ms — the one tail on this page that
  has no explanation, and a pool size worth avoiding until it has one.
- Pool sizing: the pool lives per process, so the DB connection budget is divided
  across processes — 16 processes × a 150-connection pool against
  `max_connections = 151` turns a third of the responses into "Too many
  connections". The useful size is the expected number of requests served at once
  per process plus a small margin; raising `max_connections` to 500 and inflating
  the pools moved nothing (±1%).

This is the same boundary the cheap-point-query row of the verdict table draws
([positioning](positioning.md#is-sconcur-for-you)): when a request has no
waiting to hide other work behind, SConcur's remaining edge over the worker
model is holding the same load on fewer processes, and against a coroutine
server on hooked native drivers there is no edge at all.

#### Write and read: `/db-rw`

The same ladder with a write in the request. `/db-rw` does one INSERT, a COUNT(*)
and a point SELECT of a random id within that count, sequentially in all three
stacks. The `bench_rw` table is reset to its 10 000 seed rows before each run;
during a run it grows with the inserts, so the faster stack makes its own COUNT(*)
heavier — the comparison is conservative.

| Workers | RoadRunner rps / p50 / p99 | SConcur rps / p50 / p99 (pool) | Swoole rps / p50 / p99 (pool) | SConcur rps vs RR | SConcur rps vs Swoole |
| ---: | --- | --- | --- | :---: | :---: |
| 1 | 162 / 1.57 s / 1.61 s | 2 792 / 82.7 ms / 300 ms (150) | 2 882 / 79.2 ms / 311 ms (150) | ×17 ✅ | −3% ❌ |
| 3 | 266 / 956 ms / 1.03 s | 2 745 / 83.4 ms / 382 ms (50×3) | 2 888 / 81.8 ms / 313 ms (50×3) | ×10 ✅ | −5% ❌ |
| 8 | 581 / 438 ms / 476 ms | 2 738 / 82.9 ms / 310 ms (18×8) | 2 872 / 77.6 ms / 302 ms (18×8) | ×4.7 ✅ | −5% ❌ |
| 16 | 1 036 / 246 ms / 276 ms | 2 677 / 85.0 ms / 323 ms (9×16) | 2 863 / 74.7 ms / 378 ms (9×16) | ×2.6 ✅ | −6% ❌ |

- One disk commit per request flips the ladder. Both concurrent stacks stand on
  the same ceiling from the first worker on (≈2.7–2.9k rps, flat across the
  ladder) — serving many requests at once lets MySQL fold the commits of dozens
  of them into group commits, and the limit becomes MySQL itself (~1 100–1 330%
  CPU on the mysql container while php sits at 25–50% for Swoole and 67–157% for
  SConcur). Swoole's 3–6% edge is the cost of the boundary, not a different
  ceiling. This is also a table where the three-worker rung behaves: the disk
  sets the pace, so the queue never gets deep enough for the tail to show.
- RoadRunner scales almost linearly with workers but even 16 stay 2.6× behind;
  parity would take around 40 processes. Its latencies at small pools are
  queueing, not work: 256 wrk connections share 1–3 workers.
- The applicability boundary is the same as for concurrent calls, one level up:
  as soon as the request contains an operation with real waiting, what decides
  is whether that waiting is spent in parallel with other work — even with no
  `WaitGroup` inside the request.

## Conclusions

- A single call through SConcur is always more expensive than the native driver
  — the conversion at the boundary (PHP values to and from the core's, plus BSON
  for MongoDB), most
  noticeable on cheap cached reads.
- The gain from concurrency is proportional to the price of an operation — an
  I/O wait (fsync, a network round-trip) or real server-side work — because
  those prices are paid in parallel instead of being summed: on disk-backed SQL
  every write wins, as do heavy reads on the 100k dataset, and 100 ms network
  waits run ~49× faster together. Cheap point operations stay with native.
- The connection pool is decisive: a cold pool cost the concurrent runs 3–15×,
  which is why the methodology includes a warm-up.
- The boundary itself is cheap under concurrency: socket/ws throughput ~245–279k
  round-trips/s, the empty HTTP endpoint ~248k rps (~4.8× RoadRunner).
- Against RoadRunner and Swoole: on a write-and-read request all three stand on
  MySQL's own ceiling, and the two concurrent models reach it from the first
  worker while RoadRunner needs around 40 processes to match them. On cheap
  point queries the ranking reverses against Swoole — ≈53k rps against ≈16k on
  the same core, which is the boundary tax.
- On the point query a pool of three workers carries an unexplained latency tail
  (p99 217 ms against 16–21 ms on the other rungs); until that is understood,
  size the pool away from it.
- Memory is practically flat across the modes for ordinary payloads — the fibers
  of 100 concurrent operations do not move the peak noticeably. It stops being
  flat when the payloads themselves are large: 50 concurrent 1 MB MongoDB reads
  and 500 concurrent 1 MB Redis values both hold every result in the extension
  and in PHP at once (see [Payload size](#payload-size) and [Redis](#redis)).
- Redis is the one feature where the native extension wins every shape measured.
  A command against a local server answers in about 11 µs, which is less than one
  crossing of the boundary costs, so there is nothing to overlap; batching brings
  the concurrent path back to within about 40% of phpredis. The gain the tables
  cannot show is the worker that is not blocked while it waits.
