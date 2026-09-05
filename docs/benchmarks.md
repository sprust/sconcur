English | [Русский](benchmarks.ru.md)

# Feature benchmarks

Per-feature measurements (except `Sleeper`): what a call across the PHP↔extension
boundary costs against the native driver, and what running several such calls at
the same time gains. Reference numbers, not a guarantee — they depend on
hardware, DB settings and load. The workload-matching verdict table is in
[positioning](positioning.md#is-sconcur-for-you).

> The `sync` column carries a fixed overhead that is not inherent to the approach:
> a synchronous call (outside a `WaitGroup` and outside the servers) still goes
> through the scheduler and the Fiber machinery. Cutting that is on the
> [roadmap](../README.md#roadmap). Until then the meaningful comparison is
> `native` vs `async`.

## Contents

- [Environment](#environment)
- [Conversion overhead (the PHP↔extension boundary)](#conversion-overhead-the-phpextension-boundary)
- [Methodology](#methodology)
- [MongoDB](#mongodb)
- [MySQL](#mysql)
- [PostgreSQL](#postgresql)
- [Payload size](#payload-size)
- [AMQP (RabbitMQ)](#amqp-rabbitmq)
- [Clients (HTTP / Socket / WebSocket)](#clients-http--socket--websocket)
- [Servers (HTTP / Socket / WebSocket)](#servers-http--socket--websocket)
  - [HTTP throughput: the empty endpoint](#http-throughput-the-empty-endpoint)
  - [Comparison with RoadRunner and Swoole](#comparison-with-roadrunner-and-swoole)
  - [Empty endpoint: the worker-count ladder](#empty-endpoint-the-worker-count-ladder)
  - [Point query: the worker-count ladder](#point-query-the-worker-count-ladder)
    - [Write and read: `/db-rw`](#write-and-read-db-rw)
- [Conclusions](#conclusions)

## Environment

Intel Core i7-13620H (16 threads), 15 GiB RAM, Linux, everything in Docker: the
benchmarks from the `php` container (`make bench-*`), the server pools from the
`servers` container (2 workers per group, `SO_REUSEPORT`). Component versions — see
[Tested versions](../README.md#tested-versions).

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

Every number on this page was taken on 2026-09-04/05, on an idle machine and on
the Rust core. The three stacks of the HTTP-server tables ran in one session with
the placement equalised (see [Methodology](#methodology)); the DB, payload,
client, server-feature and AMQP numbers were taken on the same disk-backed
volumes.

## Conversion overhead (the PHP↔extension boundary)

Every call crosses the boundary and converts its data: arguments are packed into
MessagePack (`Transport/MessagePackTransport`), the result is unpacked back; Mongo
documents ride in the same format, with the BSON values that MessagePack cannot
express carried as objects. This is a fixed CPU price
per operation, on top of the boundary call and runtime task dispatch. On cheap cached reads
it shows up as the `native` → `sync` gap (both sequential, but `sync` goes through
the extension): `pgsql-selectOne` 4.2 → 22.7 ms over 100 calls, `mysql-selectOne`
7.3 → 35.3 ms. On a slow operation the same surcharge is a small fraction of the
total.

## Methodology

Three modes per feature: `native` — the baseline without SConcur
(`mongodb/mongodb`, `PDO`, stream wrappers, raw sockets), sequential; `sync` —
SConcur outside a `WaitGroup` (the `Extension::wait` path), also sequential;
`async` — SConcur inside a `WaitGroup`, N coroutines running at the same time.

Each DB benchmark ran 5 times, each client/server benchmark 3 times; the tables
show the median. Every DB run starts cold — the table/collection is dropped and
reseeded to 100 000 rows/documents before the measurement — and point operations
work on distinct ids per mode, so no two calls share a hot row and a row lock
cannot force the concurrent calls to run one after another. A discarded warm-up
precedes each measurement, otherwise the concurrent calls would pay for spinning
up the connection pool inside the measured phase; the SQL pools are
`maxOpenConns: 50`. Memory is
the peak RSS of the PHP process per mode.

Calls per mode: 100 by default, 50 for client I/O benchmarks. Three MongoDB
benchmarks are bounded by the operation's nature: `createIndex` and `bulkWrite` 20,
`updateMany` 10. Single runs: `make bench-<name> c=<count>`; the whole DB session
is `make bench-db-runs`. The scripts live in `tests/benchmarks/`, one directory
per measured technology (`mongodb/`, `mysql/`, `pgsql/`, `http/`, `socket/`,
`ws/`), so `make bench-mysql-selectOne` runs
`tests/benchmarks/mysql/select-one.php`.

`async vs native` is the signed percent `(native − async) / native`, ✅ when
`async` is faster; in the DB tables each of the median, min and max columns
carries its own, which shows the spread across runs. In the server comparison
the `vs RoadRunner`/`vs Swoole` columns compare throughput against that stack's
row for the same endpoint. Sub-50 ms rows are noise-sensitive — a sign flip
between a row's `min` and `max` marks exactly that.

The server rows are taken with the workers and the load generator on
non-overlapping cores, because that is what makes a run comparable to the next
one. Production does not pin, and the difference is not zero — see
[CPU pinning](load-testing.md#cpu-pinning) for the measurements.

Two things have to be equal before the stacks can be compared, and neither is
by default. **Placement**: `load-stats.sh` defaults to `PIN_SERVERS=1`, one
worker per logical CPU, while `rr-load-stats.sh` and `swoole-load-stats.sh` can
only `taskset` the master and let it fork — the `group` placement. The core
budget is the same either way, the placement is not, and it is worth 26% on the
empty endpoint. Every server row below runs `group`; what `pin=1` costs is
measured separately in [CPU pinning](load-testing.md#cpu-pinning). **Table
state**: the three stacks share `load_all` and `bench_rw`, and only
`load-stats.sh` empties the first while nothing empties the second, so whichever
stack ran later inherited a bigger table. The rows below were taken with both
truncated before every single run.

Re-measure all three stacks in one session: cross-session drift alone reaches
±20%, and a reference row carried over from an older session is what made the
previous version of these tables incomparable.

## MongoDB

Everything gains except `insertMany`. What makes the server chew through the
dataset gains most — `count` ~7×, `bulkWrite` ~7×, `updateMany` ~5.7×,
`createIndex` +27% — and single-document operations now gain too, by 19–77%.

Median of 5 runs against a cold dataset of 100 000 documents. Cells hold
`native / sync / async`, ms, with the `async vs native` percent in parentheses.

| Operation | count | native / sync / async, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | ---: | --- |
| insertOne | 100 | 9.7 / 19.3 / 7.2 (+25% ✅) | 5.7 / 13.0 / 3.1 (+45% ✅) | 43.8 / 46.8 / 15.0 (+66% ✅) | 10 / 10 / 10 |
| insertMany | 100 | 26.9 / 59.7 / 33.4 (−24% ❌) | 24.3 / 54.0 / 33.0 (−36% ❌) | 67.0 / 130 / 38.3 (+43% ✅) | 10 / 10 / 10 |
| bulkWrite | 20 | 3566 / 3539 / 508 (+86% ✅) | 3466 / 3453 / 486 (+86% ✅) | 3603 / 3676 / 530 (+85% ✅) | 8 / 8 / 8 |
| updateOne | 100 | 24.0 / 25.6 / 5.4 (+77% ✅) | 7.0 / 12.5 / 2.7 (+61% ✅) | 39.9 / 31.9 / 17.5 (+56% ✅) | 10 / 10 / 10 |
| updateMany | 10 | 1788 / 1797 / 312 (+83% ✅) | 1745 / 1696 / 298 (+83% ✅) | 2123 / 1996 / 340 (+84% ✅) | 8 / 8 / 8 |
| deleteOne | 100 | 7.9 / 10.0 / 4.5 (+42% ✅) | 6.2 / 7.4 / 2.4 (+61% ✅) | 37.6 / 33.4 / 7.3 (+80% ✅) | 10 / 10 / 10 |
| findOne | 100 | 8.9 / 13.6 / 4.7 (+47% ✅) | 5.9 / 9.6 / 3.0 (+50% ✅) | 27.3 / 32.2 / 12.4 (+55% ✅) | 10 / 10 / 10 |
| aggregate | 100 | 21.6 / 23.5 / 7.4 (+66% ✅) | 11.5 / 13.1 / 4.8 (+58% ✅) | 32.7 / 157 / 39.2 (−20% ❌) | 10 / 10 / 10 |
| count | 100 | 2344 / 2299 / 322 (+86% ✅) | 2312 / 2281 / 296 (+87% ✅) | 2423 / 2355 / 341 (+86% ✅) | 10 / 10 / 10 |
| command | 100 | 8.4 / 11.9 / 6.8 (+19% ✅) | 3.4 / 11.6 / 3.3 (+3% ✅) | 18.8 / 23.0 / 11.8 (+37% ✅) | 6 / 6 / 6 |
| createIndex | 20 | 2679 / 2603 / 1965 (+27% ✅) | 2615 / 2490 / 1840 (+30% ✅) | 2793 / 2778 / 2023 (+28% ✅) | 8 / 8 / 8 |

async wins biggest where a call makes the server chew through the dataset —
every `count` scans all 100k documents, every `updateMany` rewrites them, the
unindexed `bulkWrite` filters scan the collection several times per call.

Single-document operations gain as well: `findOne` +47%, `updateOne` +77%,
`deleteOne` +42%, `insertOne` +25%. MongoDB pays no per-operation fsync on the
default write concern, so there is no I/O wait to hide other work behind; what
the concurrent mode overlaps here is the round-trip to the server, and that is
worth more than the boundary conversion costs. `insertMany` is the exception —
it already batches its documents into one round-trip, so there is nothing left
to overlap.

## MySQL

Disk writes run concurrently are ~14–22× faster (their fsyncs happen in
parallel), `transaction` ~22×, `count` ~1.6×; cheap reads stay with PDO. Median
of 5 runs against a cold dataset of 100 000 rows, columns as for MongoDB.

| Operation | count | native / sync / async, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | ---: | --- |
| insert | 100 | 1042 / 1127 / 73.6 (+93% ✅) | 1020 / 1006 / 65.0 (+94% ✅) | 1204 / 1171 / 90.1 (+93% ✅) | 6 / 6 / 6 |
| selectOne | 100 | 7.3 / 35.3 / 8.1 (−11% ❌) | 6.4 / 17.0 / 4.9 (+24% ✅) | 11.0 / 46.9 / 13.7 (−25% ❌) | 6 / 6 / 6 |
| selectMany | 100 | 7.9 / 28.5 / 14.5 (−84% ❌) | 7.2 / 25.3 / 11.2 (−55% ❌) | 9.7 / 96.8 / 34.9 (−259% ❌) | 6 / 6 / 10 |
| count | 100 | 150 / 168 / 93.2 (+38% ✅) | 145 / 163 / 74.9 (+48% ✅) | 153 / 170 / 120 (+22% ✅) | 6 / 6 / 6 |
| update | 100 | 1122 / 1235 / 53.4 (+95% ✅) | 1113 / 1172 / 40.4 (+96% ✅) | 1195 / 1317 / 62.3 (+95% ✅) | 6 / 6 / 6 |
| delete | 100 | 1200 / 1246 / 68.4 (+94% ✅) | 1180 / 1222 / 44.6 (+96% ✅) | 1249 / 1287 / 70.9 (+94% ✅) | 6 / 6 / 6 |
| transaction | 100 | 1230 / 1329 / 57.1 (+95% ✅) | 1149 / 1255 / 48.1 (+96% ✅) | 1251 / 1381 / 93.0 (+93% ✅) | 6 / 6 / 6 |

## PostgreSQL

Writes run concurrently are ~6–11× faster, `count` ~6×; point reads stay with
PDO.

| Operation | count | native / sync / async, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | ---: | --- |
| insert | 100 | 297 / 423 / 27.8 (+91% ✅) | 267 / 306 / 12.1 (+95% ✅) | 328 / 479 / 30.8 (+91% ✅) | 6 / 6 / 6 |
| selectOne | 100 | 4.2 / 22.7 / 5.5 (−33% ❌) | 3.0 / 16.9 / 3.8 (−27% ❌) | 5.2 / 39.8 / 7.5 (−44% ❌) | 6 / 6 / 6 |
| selectMany | 100 | 6.7 / 39.9 / 15.6 (−134% ❌) | 6.6 / 28.7 / 11.6 (−77% ❌) | 12.2 / 128 / 41.8 (−244% ❌) | 6 / 6 / 10 |
| count | 100 | 296 / 627 / 48.0 (+84% ✅) | 288 / 345 / 43.7 (+85% ✅) | 302 / 736 / 61.5 (+80% ✅) | 6 / 6 / 6 |
| update | 100 | 278 / 399 / 27.7 (+90% ✅) | 251 / 339 / 12.5 (+95% ✅) | 283 / 446 / 37.8 (+87% ✅) | 6 / 6 / 6 |
| delete | 100 | 257 / 364 / 24.9 (+90% ✅) | 232 / 231 / 12.5 (+95% ✅) | 264 / 408 / 34.9 (+87% ✅) | 6 / 6 / 6 |
| transaction | 100 | 270 / 453 / 44.3 (+84% ✅) | 259 / 366 / 21.8 (+92% ✅) | 307 / 485 / 46.8 (+85% ✅) | 6 / 6 / 6 |

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

On the async path the payload crosses the boundary twice — packed bindings (or a
BSON document) in, the result buffer out — so the boundary cost grows with the
payload while the gain from concurrency does not. Six dedicated benches
(`tests/benchmarks/{mongodb,mysql,pgsql}/payload-{write,read}.php`) move an
incompressible base64 payload of `SCONCUR_BENCH_PAYLOAD_BYTES` bytes per call;
read re-reads one hot row per mode, so the measured path is transfer + decode,
not disk. Single runs: `make bench-mysql-payloadWrite p=65536 c=100`. Median of
5 cold runs, columns as above.

Payload 1 KB, 100 calls:

| Operation | median n/s/a, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | --- |
| mongodb insertOne | 38.2 / 55.0 / 15.7 (+59% ✅) | 20.9 / 14.2 / 2.7 (+87% ✅) | 53.4 / 64.7 / 17.1 (+68% ✅) | 6 / 6 / 6 |
| mongodb findOne | 19.8 / 28.4 / 8.3 (+58% ✅) | 13.2 / 10.7 / 5.3 (+60% ✅) | 31.2 / 69.4 / 29.8 (+5% ✅) | 6 / 6 / 6 |
| mysql insert | 1003 / 1047 / 188 (+81% ✅) | 986 / 1028 / 177 (+82% ✅) | 1025 / 1070 / 210 (+80% ✅) | 6 / 6 / 6 |
| mysql selectOne | 8.2 / 70.9 / 19.1 (−133% ❌) | 7.5 / 16.8 / 3.2 (+57% ✅) | 17.3 / 77.9 / 26.8 (−55% ❌) | 6 / 6 / 6 |
| pgsql insert | 160 / 236 / 25.6 (+84% ✅) | 140 / 188 / 10.1 (+93% ✅) | 171 / 321 / 31.8 (+81% ✅) | 6 / 6 / 6 |
| pgsql selectOne | 3.4 / 29.5 / 12.4 (−263% ❌) | 2.8 / 17.6 / 6.0 (−116% ❌) | 8.3 / 66.8 / 23.5 (−183% ❌) | 6 / 6 / 6 |

Payload 64 KB, 100 calls:

| Operation | median n/s/a, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | --- |
| mongodb insertOne | 20.9 / 27.1 / 10.2 (+51% ✅) | 16.3 / 18.1 / 7.9 (+51% ✅) | 68.7 / 43.2 / 25.1 (+64% ✅) | 6 / 6 / 6 |
| mongodb findOne | 26.4 / 23.1 / 16.4 (+38% ✅) | 12.3 / 12.9 / 12.9 (−4% ❌) | 41.7 / 63.2 / 27.6 (+34% ✅) | 6 / 6 / 15 |
| mysql insert | 2030 / 1726 / 236 (+88% ✅) | 1771 / 1632 / 196 (+89% ✅) | 2216 / 1775 / 261 (+88% ✅) | 6 / 6 / 6 |
| mysql selectOne | 24.8 / 37.0 / 16.3 (+35% ✅) | 6.5 / 17.6 / 10.1 (−55% ❌) | 42.5 / 153 / 50.3 (−18% ❌) | 6 / 6 / 14 |
| pgsql insert | 421 / 720 / 128 (+70% ✅) | 379 / 710 / 38.7 (+90% ✅) | 480 / 868 / 161 (+66% ✅) | 6 / 6 / 6 |
| pgsql selectOne | 10.6 / 45.2 / 20.6 (−94% ❌) | 10.4 / 27.6 / 17.3 (−67% ❌) | 37.5 / 55.8 / 33.4 (+11% ✅) | 6 / 6 / 14 |

Payload 1 MB, 50 calls:

| Operation | median n/s/a, ms | min n/s/a, ms | max n/s/a, ms | Memory n/s/a, MB |
| --- | ---: | ---: | ---: | --- |
| mongodb insertOne | 191 / 171 / 130 (+32% ✅) | 116 / 141 / 57.4 (+51% ✅) | 202 / 288 / 172 (+15% ✅) | 6 / 10 / 10 |
| mongodb findOne | 59.8 / 90.0 / 85.8 (−44% ❌) | 54.2 / 87.4 / 67.8 (−25% ❌) | 69.3 / 173 / 87.2 (−26% ❌) | 8 / 10 / 126 |
| mysql insert | 3796 / 2962 / 951 (+75% ✅) | 3202 / 2744 / 778 (+76% ✅) | 4112 / 3141 / 1121 (+73% ✅) | 10 / 10 / 10 |
| mysql selectOne | 36.8 / 101 / 97.8 (−165% ❌) | 36.0 / 95.6 / 86.3 (−139% ❌) | 39.5 / 104 / 112 (−183% ❌) | 12 / 16 / 141 |
| pgsql insert | 635 / 1206 / 351 (+45% ✅) | 587 / 1146 / 264 (+55% ✅) | 915 / 1291 / 556 (+39% ✅) | 8 / 8 / 8 |
| pgsql selectOne | 83.0 / 295 / 103 (−25% ❌) | 77.9 / 237 / 89.4 (−15% ❌) | 96.2 / 331 / 113 (−18% ❌) | 8 / 10 / 129 |

1. Writes: concurrency wins while fsync dominates, but the margin shrinks as the
   payload grows — pgsql insert goes +84% → +70% → +45% at 1 MB, because
   PostgreSQL commits a large row cheaply via TOAST and the transfer cost
   catches up.
2. Reads split by driver. MongoDB gains up to 64 KB (+58% at 1 KB, +38% at
   64 KB) and loses at 1 MB; the SQL reads lose at every size except
   `mysql selectOne` at 64 KB. A point read has no waiting time to hide other
   work behind, and the payload pays the boundary both ways.
3. **Memory is the main finding.** The async column at 1 MB reads 126–141 MB
   against 8–12 MB for native: 50 concurrent 1 MB reads hold all 50 results in
   memory at once. It already shows at 64 KB, where the async reads sit at
   14–15 MB against 6. Limit how many such operations run at the same time
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
| publish | 1000 | 4.1 / 29.9 / 19.6 (−379% ❌) | 3.8 / 27.8 / 18.7 (−389% ❌) | 4.2 / 38.9 / 20.1 (−379% ❌) | 22 / 22 / 22 |
| get | 1000 | 29.0 / 64.2 / 29.6 (−2% ❌) | 27.7 / 56.1 / 26.0 (+6% ✅) | 46.9 / 101 / 69.9 (−49% ❌) | 22 / 22 / 22 |

Consuming a pre-filled set of queues, 10 queues × 200 messages, median of 5 runs:

| Measurement | native | sync | async |
| --- | ---: | ---: | ---: |
| messages per second | 122 100 | 53 800 | 111 000 |

`basic.publish` expects no reply, so it costs one write on the wire while every
SConcur call also crosses the PHP ↔ extension boundary — there is nothing to
overlap and the crossing is the whole difference. `basic.get` does wait for the
broker, and running the calls at the same time recovers all of it: the concurrent
mode now matches native and halves the synchronous one.

The three consuming numbers move more between runs than any other table here —
read them as orders of magnitude, not as figures to compare percentages on.

**What the tables do not measure is the reason the feature exists.** They pit one
call against one call on a queue that already has its messages. The gain is a
worker that waits on several queues at once: consuming holds the PHP thread in
both `ext-amqp` and `php-amqplib`, so a process is pinned to one queue, while
here the same loop suspends only its coroutine — three consumers waiting on a
200 ms delay finish in one delay, not three
(`tests/feature/Features/Amqp/AmqpConsumeTest.php`). That is throughput a
single-queue benchmark cannot show.

## Clients (HTTP / Socket / WebSocket)

Network waits overlap almost perfectly: the `msleep` endpoint holds the
connection for 100 ms, so 50 sequential calls take ≈5 s while the same 50 run
concurrently in the time of about one call.

| Benchmark | count | native, ms | sync, ms | async, ms | Memory n/s/a, MB | async vs native |
| --- | ---: | ---: | ---: | ---: | --- | :---: |
| http-client (`/msleep/100`) | 50 | 5216 | 5185 | 120 | 4 / 4 / 4 | +98% ✅ |
| socket-client (`msleep:100`) | 50 | 5203 | 5238 | 118 | 4 / 4 / 4 | +98% ✅ |
| ws-client (`msleep:100`) | 50 | 5203 | 5263 | 123 | 4 / 4 / 4 | +98% ✅ |

On I/O latency async gives ~44× (5.2 s → 0.12 s).

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
| http-server-io | 100 × `GET /msleep/1000` (1 s async sleep) | 1.03 | — |
| http-server-cpu | 100 × `GET /cpu/100000` (sha256 loop) | 1.04 | — |
| socket-server-io | 100 × `msleep:1000` round-trip | 1.00 | — |
| socket-server-cpu | 100 × `cpu:100000` round-trip | 0.96 | — |
| socket-throughput | 50 conn × 2000 × `ping` | 0.78 | ≈127 500 rt/s |
| ws-server-io | 100 × `msleep:1000` round-trip | 1.01 | — |
| ws-server-cpu | 100 × `cpu:100000` round-trip | 0.99 | — |
| ws-throughput | 50 conn × 2000 × `ping` | 0.83 | ≈120 900 rt/s |

100 handlers each sleeping 1 s asynchronously finish in ≈one sleep regardless of
the worker count — a single cooperative process already holds all those waits at
once. Throughput measures the pure round-trip price under concurrency. Behaviour
under sustained load is in [load testing](load-testing.md).

The `-cpu` rows scale with the worker count and nothing else: the sha256 loop
never yields, so a single `/cpu/100000` costs ~19 ms and 100 of them take ~0.95 s
across the two workers per group of `config/sconcur.servers.config.json` (~0.63 s
across three, at any preemption quantum).

### HTTP throughput: the empty endpoint

Sustained throughput under `wrk` (the `http-load-stats.sh` script, methodology
in [load testing](load-testing.md)): 12 processes in the `php` container, `wrk`
4 threads / 256 connections / 20 s hitting the bridge IP directly, 3 runs per
endpoint, all responses `200`. `/` returns `200 "ok"` and does no I/O, so it
measures the ceiling of the server and the framework and nothing else.

| Endpoint | Requests/sec | Latency p50 / p90 / p99 | CPU `php` avg / peak | MEM peak |
| --- | ---: | --- | --- | ---: |
| `/` (empty) | ≈194 300 | 0.9 / 73 / 263 ms | ~1081% / ~1199% | ~185 MiB |

The pool ceiling is 12 cores (~1200%), and the empty endpoint reaches it. The
median is under a millisecond while the tail is two orders above it: 256
connections are multiplexed onto 12 cooperative processes, so a request that
arrives behind a queue waits for it. What that tail does across pool sizes, and
where it behaves unexpectedly, is in the
[worker ladder](#empty-endpoint-the-worker-count-ladder).

### Comparison with RoadRunner and Swoole

Three execution models on the same endpoint. On an empty response the ranking is
the price of each transport, and Swoole's C event loop is ahead of everything.

Two reference stacks are measured next to SConcur, both on native drivers and
both committed (`tests/servers/roadrunner/`, `tests/servers/swoole/`) with load
scripts that are copies of `http-load-stats.sh`:
[RoadRunner](https://roadrunner.dev) 2025.1.15 (a request occupies a worker for
its whole duration) and [Swoole](https://swoole.com) 6.2.2 (a coroutine worker
serves many requests at once, blocking drivers become non-blocking via
`hook_flags => SWOOLE_HOOK_ALL`). Conditions are identical: the same `php`
container, 12 workers, `wrk` 4 threads / 256 connections / 20 s, 3 runs
(median), all three in one session (2026-09-05) in the `group` placement — only
in-session numbers are comparable, cross-session drift reaches ±20%.

`/` returns `200 "ok"` in all three; only the driver stack and the execution
model differ.

Honesty checks: every response was `200`. The one row that is not what it seems
is Swoole's: there the load generator is the limit, not the server — with 8
generator threads instead of 4 the same endpoint answered ≈865 000 rps, so the
figure below is a floor.

| Server | Requests/sec | p50 / p90 / p99 | CPU avg / peak | MEM peak | vs RoadRunner | vs Swoole |
| --- | ---: | --- | --- | ---: | :---: | :---: |
| Swoole | ≈365 600 | 0.35 / 0.45 / 0.70 ms | ~261% / ~272% | ~74 MiB | +710% ✅ | — |
| SConcur | ≈194 300 | 0.94 / 73 / 263 ms | ~1081% / ~1199% | ~185 MiB | +331% ✅ | −47% ❌ |
| RoadRunner | ≈45 100 | 5.4 / 6.5 / 9.2 ms | ~1018% / ~1058% | ~225 MiB | — | −88% ❌ |

- The ranking is the price of the transport. Swoole answers from a C event loop
  in the same process as the PHP closure. SConcur is ~4.3× RoadRunner because
  crossing the PHP↔extension boundary is cheaper than RoadRunner's extra
  inter-process step (proxy → worker) per request.
- The three differ in what they spend to get there. Swoole holds its number on
  ~261% of CPU and 74 MiB; SConcur and RoadRunner both saturate the 12-core
  budget, and SConcur turns it into 4.3× the requests.
- They also differ in the shape of the latency. RoadRunner queues at accept, so
  its median is the highest and its tail is the tightest — p99 is 1.7× p50.
  SConcur multiplexes 256 connections onto 12 cooperative processes: the median
  is 5.7× lower and the tail is 280× the median. Which of the two matters
  depends on whether a slow request may be paid for by a fast one.

### Empty endpoint: the worker-count ladder

The three stacks on `/` (200 "ok", no I/O) across worker counts — the pure price
of each server layer as it scales over cores. Same wrk harness (4 threads / 256
connections / 20 s, median of 3), all three stacks in one session (2026-09-04).
The script pins workers to the first N cores and wrk to the rest, so at 16
workers the generator shares cores with the server — that is what caps Swoole's
16-worker row, not the server itself.

| Workers | RoadRunner rps / p50 / p99 | SConcur rps / p50 / p99 | Swoole rps / p50 / p99 | SConcur vs RR | SConcur vs Swoole |
| ---: | --- | --- | --- | :---: | :---: |
| 1 | 8 142 / 30.6 ms / 38.7 ms | 27 642 / 0.07 ms / 5.6 ms | 179 929 / 1.4 ms / 2.0 ms | +240% ✅ | −85% ❌ |
| 3 | 14 218 / 17.5 ms / 23.1 ms | 66 033 / 2.9 ms / 1 320 ms | 437 989 / 0.6 ms / 1.3 ms | +364% ✅ | −85% ❌ |
| 8 | 31 576 / 7.9 ms / 10.5 ms | 154 946 / 1.3 ms / 24.7 ms | 586 904 / 0.2 ms / 0.7 ms | +391% ✅ | −74% ❌ |
| 16 | 49 571 / 4.8 ms / 8.9 ms | 186 203 / 1.1 ms / 9.7 ms | 359 497 / 0.4 ms / 0.7 ms | +276% ✅ | −48% ❌ |

- All three scale near-linearly until the cores run out, and SConcur holds
  ~3.4–4.9× RoadRunner on every rung. Swoole's C event loop stays far ahead of
  both, though the gap narrows from −85% to −48% as the pool grows.
- RoadRunner queues at accept: its median is the highest on every rung and its
  p99 never exceeds 1.6× its p50. SConcur multiplexes connections onto
  cooperative processes, so the median is far lower and the tail carries the
  queue instead.
- **The three-worker rung is the exception, and it is not explained.** Its p99
  is 1.3 s against a p50 of 2.9 ms, while one worker holds the same 256
  connections at a p99 of 5.6 ms and eight workers at 24.7 ms. Two causes were
  measured and ruled out: it is not the CPU placement (`PIN_SERVERS=1` gives the
  same tail and less throughput), and it is not an uneven `SO_REUSEPORT` split
  (the per-worker request counts are as uneven at twelve workers, 1.30×, as at
  three, 1.34×, while the p99 differs fivefold). Cutting the load to 64
  connections — the per-worker count twelve workers see — drops the p99 to
  431 ms, which points at the depth of a single worker's queue; the one-worker
  rung contradicts that, since it queues the most connections of all and has the
  tightest tail. The same shape appears on `/db`, so it is not specific to a
  route that does no I/O. A pool of three is worth avoiding until this is
  understood.

### Point query: the worker-count ladder

The same three stacks on the cheapest possible request (the `/db` endpoint of
the demo servers): one point SELECT of a random id per request, JSON response.
SConcur runs it through the MySQL feature with no `WaitGroup`, so the only
concurrency is between different requests; RoadRunner and Swoole go through PDO.
Disk-backed MySQL, default `max_connections = 151`; the per-process pool of
SConcur and Swoole is sized to fit that limit, RoadRunner holds one connection
per worker. Same wrk setup, one session.

| Workers | RoadRunner rps / p50 / p99 | SConcur rps / p50 / p99 (pool) | Swoole rps / p50 / p99 (pool) | SConcur vs RR | SConcur vs Swoole |
| ---: | --- | --- | --- | :---: | :---: |
| 1 | 5 129 / 47.3 ms / 81.9 ms | 10 201 / 0.8 ms / 20.2 ms (150) | 45 644 / 5.3 ms / 8.8 ms (150) | +99% ✅ | −78% ❌ |
| 3 | 10 199 / 24.0 ms / 37.2 ms | 27 657 / 7.0 ms / 1 240 ms (50×3) | 97 265 / 2.8 ms / 5.8 ms (50×3) | +171% ✅ | −72% ❌ |
| 8 | 22 887 / 10.7 ms / 16.9 ms | 44 200 / 5.4 ms / 32.7 ms (18×8) | 117 693 / 2.0 ms / 6.2 ms (18×8) | +93% ✅ | −62% ❌ |
| 16 | 27 541 / 8.7 ms / 15.5 ms | 37 823 / 6.2 ms / 31.6 ms (9×16) | 108 765 / 2.0 ms / 10.7 ms (9×16) | +37% ✅ | −65% ❌ |

- Against RoadRunner the SConcur advantage tapers with pool size — +99% on a
  single worker, +37% at the full per-core pool, where both approach the shared
  hardware ceiling (MySQL plus total CPU). SConcur peaks at 8 workers.
- Swoole is 2.5–4× ahead of both, and this is where SConcur's price is most
  visible. Both saturate one PHP thread on a single worker (~101% CPU), but
  that thread buys ≈10k rps for SConcur (~98 µs of CPU per request) against
  ≈46k for Swoole (~22 µs): the hooked `mysqlnd` never leaves the process. The
  work moves to the database accordingly — at one worker the mysql container
  burns ~202% CPU under Swoole against ~70% under SConcur.
- Tails: RoadRunner stays tight throughout (p99 under 2× its p50); Swoole under
  11 ms everywhere; SConcur holds p99 20–33 ms except on the three-worker rung,
  where the same unexplained tail as on the empty endpoint puts it at 1.24 s.
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

| Workers | RoadRunner rps / p50 / p99 | SConcur rps / p50 / p99 (pool) | Swoole rps / p50 / p99 (pool) | SConcur vs RR | SConcur vs Swoole |
| ---: | --- | --- | --- | :---: | :---: |
| 1 | 76 / 984 ms / 1.98 s | 2 350 / 98.6 ms / 289 ms (150) | 2 459 / 93.5 ms / 279 ms (150) | ×31 ✅ | −4% ❌ |
| 3 | 137 / 1.78 s / 1.97 s | 2 360 / 96.8 ms / 380 ms (50×3) | 2 480 / 93.9 ms / 296 ms (50×3) | ×17 ✅ | −5% ❌ |
| 8 | 289 / 877 ms / 1.01 s | 2 366 / 95.8 ms / 316 ms (18×8) | 2 467 / 89.8 ms / 344 ms (18×8) | ×8.2 ✅ | −4% ❌ |
| 16 | 508 / 506 ms / 598 ms | 2 300 / 99.9 ms / 318 ms (9×16) | 2 376 / 98.9 ms / 371 ms (9×16) | ×4.5 ✅ | −3% ❌ |

- One disk commit per request flips the ladder. Both concurrent stacks stand on
  the same ceiling from the first worker on (≈2.3–2.5k rps, flat across the
  ladder) — serving many requests at once lets MySQL fold the commits of dozens
  of them into group commits, and the limit becomes MySQL itself (~1 050–1 300%
  CPU on the mysql container while php sits at 23–59% for Swoole and 65–184% for
  SConcur). Swoole's 3–5% edge is the cost of the boundary, not a different
  ceiling. This is also the one table where the three-worker rung behaves: the
  disk sets the pace, so the queue never gets deep enough for the tail to show.
- RoadRunner scales almost linearly with workers but even 16 stay 4.5× behind;
  parity would take around 70 processes. Its latencies at small pools are
  queueing, not work: 256 wrk connections share 1–3 workers.
- The applicability boundary is the same as for concurrent calls, one level up:
  as soon as the request contains an operation with real waiting, what decides
  is whether that waiting is spent in parallel with other work — even with no
  `WaitGroup` inside the request.

## Conclusions

- A single call through SConcur is always more expensive than the native driver
  — the conversion at the boundary (MessagePack, plus BSON for MongoDB), most
  noticeable on cheap cached reads.
- The gain from concurrency is proportional to the price of an operation — an
  I/O wait (fsync, a network round-trip) or real server-side work — because
  those prices are paid in parallel instead of being summed: on disk-backed SQL
  every write wins, as do heavy reads on the 100k dataset, and 100 ms network
  waits run ~44× faster together. Cheap point operations stay with native.
- The connection pool is decisive: a cold pool cost the concurrent runs 3–15×,
  which is why the methodology includes a warm-up.
- The boundary itself is cheap under concurrency: socket/ws throughput ~112–171k
  round-trips/s, the empty HTTP endpoint ~194k rps (~4.3× RoadRunner).
- Against RoadRunner and Swoole: on a write-and-read request all three stand on
  MySQL's own ceiling, and the two concurrent models reach it from the first
  worker while RoadRunner needs around 70 processes to match them. On cheap
  point queries the ranking reverses against Swoole — ≈46k rps against ≈10k on
  the same core, which is the boundary tax.
- A pool of three workers has an unexplained latency tail on every route that is
  not disk-bound; until that is understood, size the pool away from it.
- Memory is practically flat across the modes — the fibers of 100 concurrent
  operations do not move the peak noticeably.
