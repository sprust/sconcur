English | [Русский](bench-catalog.ru.md)

# Benchmark catalog

Every benchmark, soak and profiling tool in the repository, laid out the way the files
are, with the command that runs it and what it is for. The numbers they produce are in
[benchmarks](benchmarks.md) and [load testing](load-testing.md); how to read a soak is in
[.ai/soak-analysis.md](../.ai/soak-analysis.md).

## Before you run

- `make up` brings up the `php` container and the backends (MongoDB, MySQL, PostgreSQL,
  Redis, RabbitMQ) and the `servers` container. `make ext-build` builds the extension.
- Most targets run inside the `php` container through `docker compose exec`. The load
  harnesses (`*.sh` under `http/`, `ext/bench/ladder.sh`, `docs/run.py`) run on the
  host and need `wrk` there (`apt-get install wrk`); the WebSocket harness carries its
  own generator and needs nothing on the host.
- The client and server benchmarks of `http/`, `socket/` and `ws/` talk to the running
  `servers` pool (`make servers-restart` rebuilds it, `make servers-status` shows it).
- The pools the load harnesses start run the way production runs them: a worker master
  loads what the demo server builds on (`tests/servers/preload.php`) and forks the
  workers (`tests/benchmarks/lib/master-pool.sh`).
- `make bench-reset` drops the backends' data volumes and recreates them before a
  measuring session: the data is on disk and writes accumulate between runs.
- `SCONCUR_EXT=<path to .so>` points every target at another build of the extension.

### The feature benchmarks

The per-operation scripts run on one harness (`lib/benchmarker.php`) and print three
modes side by side: native (the plain PHP driver or function), sync (the SConcur
feature outside a `WaitGroup`) and async (the same calls fanned out in a `WaitGroup`).
They take `c="<calls> <log>"`: the calls per mode (5 by default) and `1` to print every
call. The DB scripts work against a seeded dataset of 100 000 rows
(`SCONCUR_BENCH_DATASET`, which `db/runs.sh` sets), each mode on its own id range.

## The tree

```
tests/benchmarks/
├── lib/                              shared harness, not run on its own
│   ├── benchmarker.php               the native / sync / async runner of the feature benches
│   ├── payload.php                   payload size from SCONCUR_BENCH_PAYLOAD_BYTES
│   ├── http-bench.php                reaching the `servers` HTTP pool, concurrent requests
│   ├── socket-bench.php              the same for the socket pool, length-prefixed frames
│   ├── ws-bench.php                  the same for the WebSocket pool, handshake and frames
│   ├── master-pool.sh                starts a demo-server pool under a worker master
│   └── excimer-profile.php           the PHP-stack sampler behind `make profile-php`
│
├── runtime/                          the scheduler and the PHP↔extension boundary, no backend
│   ├── sleeper.php                   make bench-sleeper c="<calls> <log>"
│   │                                 1 ms sleeps: Sleeper outside and inside a WaitGroup
│   ├── fan-out.php                   make bench-fan-out
│   │                                 cost per member of a wide fan-out on both sides of the
│   │                                 1024-result buffer, the backpressure path
│   ├── nested-fan-out.php            make bench-nested-fan-out
│   │                                 cost per member of a WaitGroup created in a coroutine
│   ├── coordination-profile.php      make bench-coordination
│   │                                 where suspend → push → waitAny → resume spends its time
│   ├── boundary-profile.php          php -d extension=ext/build/sconcur.so <script>
│   │                                 what taking one result across the boundary costs
│   ├── push-profile.php              make bench-push
│   │                                 what pushing one task across the boundary costs
│   ├── request-profile.php           make bench-request
│   │                                 the per-request PSR-7 construction, stage by stage
│   └── teardown-profile.php          make bench-teardown
│                                     coroutine teardown against a growing crowd of live ones
│
├── mongodb/                          make bench-mongodb-<op> c="<calls> <log>"
│   ├── insert-one.php                insertOne       into a seeded collection
│   ├── insert-many.php               insertMany      a batch into a seeded collection
│   ├── find-one.php                  findOne         by _id, a document per call
│   ├── update-one.php                updateOne       by _id, a document per call
│   ├── update-many.php               updateMany      the whole dataset per call: few calls
│   ├── delete-one.php                deleteOne       by _id, a real delete per call
│   ├── bulk-write.php                bulkWrite       unindexed filters, a scan each: few calls
│   ├── aggregate.php                 aggregate       $match + $limit 30
│   ├── count.php                     count           the whole dataset
│   ├── command.php                   command         a server command
│   ├── create-index.php              createIndex     a full scan each; no warm-up (64-index cap)
│   ├── payload-write.php             make bench-mongodb-payloadWrite c=… p=<bytes>
│   └── payload-read.php              make bench-mongodb-payloadRead c=… p=<bytes>
│                                     one document of p bytes: the transfer and decode path
│
├── mysql/                            make bench-mysql-<op> c="<calls> <log>"
│   ├── insert.php                    insert          into a seeded table
│   ├── select-one.php                selectOne       a row per call, by id
│   ├── select-many.php               selectMany      a sliding 100-row window
│   ├── update.php                    update          a row per call
│   ├── delete.php                    delete          a row per call, a real commit each
│   ├── count.php                     count           the seeded table
│   ├── transaction.php               transaction     begin → insert → commit
│   ├── payload-write.php             make bench-mysql-payloadWrite c=… p=<bytes>
│   └── payload-read.php              make bench-mysql-payloadRead c=… p=<bytes>
│
├── pgsql/                            make bench-pgsql-<op> c="<calls> <log>"
│   └── …                             the same nine scripts as mysql/
│
├── db/                               a whole DB session rather than one operation
│   ├── lifecycle.php                 make bench-db-lifecycle c=<rows> runs=<n> pool=<size>
│   │                                 create → seed → read N → delete N → drop, per database
│   ├── runs.sh                       make bench-db-runs  (RUNS=5 DATASET=100000)
│   │                                 every DB bench RUNS times on a cold dataset, for the
│   │                                 tables of benchmarks.md
│   └── runs-aggregate.php            used by runs.sh: the CSVs into median / min / max rows
│
├── redis/                            make bench-redis-<op> c="<calls> <log> [<arg>]"
│   ├── get.php                       GET
│   ├── set.php                       SET
│   ├── pipeline.php                  a batch at once against the same commands one by one
│   ├── value-size.php                GET of a value of <arg> bytes: where copying outweighs
│   │                                 the concurrency
│   └── pool-size.php                 the async GET at a pool of <arg> connections
│
├── amqp/                             make bench-amqp-<op> c="<calls> <log>"
│   ├── publish.php                   one publish per call into the mode's queue
│   ├── get.php                       one basic.get per call from a seeded queue
│   ├── consume.php                   c="<consumers> <messages each>": several queues at once
│   └── lib/amqp-bench.php            queues and seeding for the three
│
├── files/                            make bench-files-<op> c="<calls> <log>" bytes=<n>
│   ├── read.php                      a whole file: file_get_contents against Files
│   ├── write.php                     a whole file: file_put_contents against Files
│   ├── copy.php                      copy() against the copy inside the extension
│   ├── hash-file.php                 hash_file against the feature
│   ├── read-stream.php               fread in a loop against readChunks; watch the memory
│   ├── list-dir.php                  entries=<n>: scandir + filesize + filemtime
│   └── lib.php                       a scratch directory and seeded files per mode
│
├── http/
│   ├── client.php                    make bench-http-client c="<calls> <log>"
│   │                                 requests to /msleep of the `servers` pool
│   ├── client-download.php           make bench-http-client-download
│   │                                 4 MiB downloads into files, the sink path
│   ├── client-external.php           make bench-http-client-external
│   │                                 a real host on the internet (BENCH_URL): a smoke test
│   ├── server-io.php                 make bench-http-server-io
│   │                                 100 concurrent async sleeps on the `servers` pool
│   ├── server-cpu.php                make bench-http-server-cpu
│   │                                 100 concurrent CPU loops spread over the pool
│   ├── throughput.sh                 make bench-http-throughput  (host, wrk)
│   │                                 a worker per core on SO_REUSEPORT, keep-alive rps
│   ├── load-stats.sh                 make bench-http-load-stats  (host, wrk)
│   │                                 /all under load with CPU, memory and worker RSS sampled;
│   │                                 ROUTE, SERVERS, PIN_SERVERS, DISTINCT_PORTS, WORKER_LOGS
│   │   ├── make bench-http-load-stats-empty   ROUTE=/ and PIN_SERVERS=group: the ceiling
│   │   ├── make bench-http-load-stats-big     ROUTE=/big/$(BODY_BYTES): the cost of a body
│   │   └── make bench-http-load-soak          MODE=soak: 10 min, RSS trend and slope
│   ├── rr-load-stats.sh              make bench-rr-load-stats[-empty|-soak]  (host, wrk)
│   │                                 the same harness against RoadRunner on native drivers;
│   │                                 make bench-rr-sconcur-load-stats: SConcur in its workers;
│   │                                 make rr-serve: the server alone, in the foreground
│   ├── swoole-load-stats.sh          make bench-swoole-load-stats[-empty|-soak]  (host, wrk)
│   │                                 the same against Swoole; make bench-swoole-coro-load-stats:
│   │                                 its own coroutine fan-out; make swoole-serve: the server
│   │                                 alone, in the foreground
│   ├── nginx-proxy.sh                nginx in front of a DISTINCT_PORTS pool, per-request
│   │                                 balancing (the dispatch experiment, bench-docs --proxy)
│   └── mixed-profile.lua             WRK_SCRIPT: mostly cheap requests, a few heavy ones
│
├── socket/
│   ├── client.php                    make bench-socket-client c="<calls> <log>"
│   ├── throughput.php                make bench-socket-throughput: ping round trips per second
│   ├── server-io.php                 make bench-socket-server-io
│   └── server-cpu.php                make bench-socket-server-cpu
│
├── ws/
│   ├── client.php                    make bench-ws-client c="<calls> <log>"
│   ├── throughput.php                make bench-ws-throughput: ping round trips per second
│   ├── server-io.php                 make bench-ws-server-io
│   ├── server-cpu.php                make bench-ws-server-cpu
│   ├── load-stats.sh                 make bench-ws-load-stats[-empty|-soak]
│   │                                 the "all" message under load, a worker per core, with
│   │                                 CPU, memory and worker RSS sampled
│   └── ws-load/                      the WebSocket load generator (Rust), built by load-stats.sh
│
├── worker/                           what a pool of workers costs in memory
│   ├── memory.php                    make bench-worker-memory  (WORKERS, REQUESTS, ARMS)
│   │                                 exec (a php per worker), fork, preload (the master loaded
│   │                                 the application first): Pss, Rss, Private_Dirty, heap
│   │   └── make bench-worker-memory-soak      15 min an arm, without OPcache and with it
│   ├── master.php                    the master of the fork and preload arms
│   ├── app.php                       the stand-in application: Composer's classmap loaded
│   └── app-worker.php                the worker: /work, /memory
│
└── docs/
    └── run.py                        make bench-docs [f="--<section> …"]  (host)
                                      every measurement the docs quote, in one session, into
                                      .bench-runs/docs/<timestamp>/; f=--list names the sections

tests/mem-leak/                       soaks: one scenario in a loop, what stays behind
├── long-flow-soak.php                make mem-leak-long-flow scenario=<name> seconds=<n>
│                                     streams opened by a coroutine that never ends
├── redis-soak.php                    make mem-leak-redis scenario=<name> seconds=<n>
├── amqp-soak.php                     make mem-leak-amqp scenario=<name> seconds=<n>
├── files-soak.php                    make mem-leak-files scenario=<name> seconds=<n>
├── worker-soak.php                   make mem-leak-worker scenario=<list> seconds=<n> launch=…
│                                     the worker master under failures: what the master and the
│                                     surviving workers keep, the requests a death costs
├── worker-soak-worker.php            its worker: a path for every way a request can fail
├── worker-soak-summary.php           make mem-leak-worker-summary file=<saved output>
├── worker-exit-timing.php            make mem-leak-worker-exit-timing launch=… seconds=<n>
│                                     a death by exit(), step by step
└── endless-example.php               a coroutine that never ends, for watching by hand

ext/bench/
├── ladder.sh                         make bench-ladder  (host, wrk; ROUNDS, SERVERS, DURATION,
│                                     RUNGS="l0 l1 l2 l2f"): what a request costs before PHP (l0)
│                                     and what crossing into PHP adds (l1; l2 with a fiber per
│                                     request, l2f pushing from the fiber's stack), in CPU µs per
│                                     request; runs load-stats.sh with --ladder
└── loadclient.py                     a second load client, to check wrk's numbers

profiling (docker-compose.profiling.yml)
├── make profile-build / profile-up / profile-down   the image with perf and excimer
├── make profile-perf c=<script>      perf on the C stack, a folded stack file
├── make profile-php c=<script>       excimer on the PHP stack
├── make profile-perf-check           whether the host lets perf sample
└── make profile-verify               the image is unstripped and carries both profilers
```

`make bench-all` runs the feature, client and server benchmarks one after another; the
load harnesses, soaks and profilers are left out of it.
