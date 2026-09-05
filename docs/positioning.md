English | [Русский](positioning.ru.md)

# Positioning: SConcur vs php-fpm, RoadRunner and Swoole

What SConcur changes in the PHP execution model, what that costs in resources,
and where it pays off. The numbers come from [benchmarks](benchmarks.md) and
[load testing](load-testing.md) — the same hardware, the same endpoints, the
reference stacks measured side by side on an identical test rig.

## Is SConcur for you?

Each row says what was measured and against what. "Concurrently" always means
the same thing: the operations are started one after another without waiting for
the previous one to finish, so their waiting times pass at the same time instead
of adding up.

| Your workload | What the measurements show | Verdict |
| --- | --- | :---: |
| A high-concurrency I/O-bound HTTP/WS service | On the same hardware SConcur serves [~4.5× the requests per second](#throughput-on-the-same-hardware) RoadRunner does, and holding that load takes ~2× less memory than RoadRunner and ~6–12× less than php-fpm — [resources](#resources-to-hold-the-same-load) | ✅ |
| One request or job needs several DB or network calls | Running them concurrently instead of one after another finishes SQL writes [~3–15×](benchmarks.md#mysql) faster, heavy reads [~2–7×](benchmarks.md#mongodb), network waits [~44×](benchmarks.md#clients-http--socket--websocket) | ✅ |
| MongoDB with concurrency | SConcur is the only way to have several MongoDB operations in progress at once inside one PHP process — [tables](benchmarks.md#mongodb) | ✅ |
| Single cheap queries, one at a time (SConcur used as a library) | Slower than the native driver: one call costs ~50 µs of [PHP↔extension boundary](benchmarks.md#conversion-overhead-the-phpextension-boundary) crossing, more than a cheap query itself takes | ❌ |
| Megabyte payloads per operation | Moving data across the boundary costs ~1.7–2.6 ms per MB in each direction, and the results of all operations running at once are held in memory together — [payload size](benchmarks.md#payload-size) | ❌ |
| CPU-bound handlers | No gain: PHP stays single-threaded, and a handler busy with computation blocks the whole process — [servers](benchmarks.md#servers-http--socket--websocket). [Coroutine switching](coroutine-switching.md) keeps such a handler from freezing its neighbours for long, but it does not add throughput | ❌ |

Three rules behind the table:

- Running operations concurrently wins wherever a single operation has a real
  cost — an fsync per write, server-side work over a dataset, a network wait.
  The higher that cost, the bigger the win.
- Cheap single operations stay with the native driver: crossing the boundary
  costs more than the operation, and there is no waiting time to hide other work
  behind.
- A single call through SConcur is always more expensive than the native one;
  the gain comes only from having several operations in progress at once.

The ❌ on single queries is about the price of one call inside one process. As a
server SConcur wins even on many small operations: every handler runs in its own
coroutine, so while one handler waits for a database, the process is already
running the next request — no `WaitGroup` needed. The `/all-nowg` endpoint,
which makes its six calls strictly one after another, still holds ≈2 570 rps
against RoadRunner's ≈460 on the same operations
([load testing](load-testing.md#concurrent-vs-sequential-calls-all-vs-all-nowg)).
Only on microsecond cache hits does the edge shrink to what the server layer
alone gives (~4.3× on the empty endpoint) — it never turns into a loss.

## Execution models

| | php-fpm | RoadRunner | SConcur |
| --- | --- | --- | --- |
| Model | a process per request, from a pool | a long-lived worker per request | a long-lived process serving N requests concurrently |
| Framework bootstrap | every request | once | once |
| I/O wait | the worker is held by the request and accepts nothing else | the worker is held by the request and accepts nothing else | the coroutine is suspended and the process serves other requests meanwhile |
| Throughput ceiling on I/O-bound load | workers × request time | same, minus the bootstrap | how fast the databases and external services answer — waiting itself no longer limits it |
| A CPU-bound request | blocks 1 worker of N | blocks 1 worker of N | slows the whole process; how long it delays the other requests is bounded by [preemption](coroutine-switching.md) |
| Memory under concurrency | RSS × workers, grows with the number of requests being served | RSS × workers, grows with the number of requests being served | ~50 MiB per process, one process per core, and it does not grow with the number of requests |

php-fpm and RoadRunner share the trait that matters under load: a request holds
a worker for its full duration, I/O waits included. RoadRunner removes the
per-request bootstrap and keeps connections alive, which buys 2–10× on light
endpoints — but the worker-per-request model stays. SConcur keeps the
long-lived-worker gains and breaks that trait: a request waiting on I/O costs a
suspended fiber, not a worker.

## Throughput on the same hardware

12 workers each, `wrk` 4 threads / 256 connections / 20 s, disk-backed backends
(details in [benchmarks](benchmarks.md#comparison-with-roadrunner-and-swoole)):

| Endpoint | Server | Requests/sec | p50 | CPU avg | MEM peak |
| --- | --- | ---: | ---: | ---: | ---: |
| `/` (empty) | SConcur | ≈194 300 | 0.9 ms | ~1081% | ~185 MiB |
| `/` (empty) | RoadRunner | ≈45 100 | 5.4 ms | ~1018% | ~225 MiB |
| `/all` (3 features, 6 DB ops) | SConcur | ≈1 371 | 183 ms | ~383% | ~237 MiB |
| `/all` | RoadRunner (native drivers) | ≈302 | 839 ms | ~102% | ~243 MiB |

On the empty endpoint the gap is ~4.3×: RoadRunner pays an extra inter-process
step (proxy → worker) on every request, SConcur pays the PHP↔extension boundary,
which is the cheaper of the two. On `/all` the gap is ~4.5× and structural: the
sequential worker performs its 3 disk commits one after another and idles at
~102% CPU while all 12 workers sit in that chain; SConcur runs those same commits
at the same time — both the ones inside a single request and the ones belonging
to different requests.

Swoole is absent from the `/all` rows and cannot be added to them: `ext-mongodb`
is outside its runtime hooks, so the endpoint blocks a Swoole worker outright and
the number would describe a driver rather than an execution model. Where the
workload is the same for all three — the empty endpoint and the `/db`, `/db-rw`
ladders in [benchmarks](benchmarks.md) — Swoole is measured beside the other two.

## Resources to hold the same load

The number of requests being served at any moment is throughput × latency
(Little's law). The worker model needs a worker for each of them; SConcur needs
a fiber. That is where the resource difference lives — not in CPU, which is
comparable per request: on `/all` SConcur spends ~2.8 cores per 1 000 rps
against RoadRunner's ~3.4, on the empty endpoint 0.06 against 0.23.

To hold the measured ≈1 370 rps of the `/all` workload:

- SConcur (measured): 12 per-core processes, ~535 MiB of worker RSS total (~45
  MiB each), and that figure does not grow with the number of requests being
  served.
- RoadRunner (linear extrapolation from 302 rps at 12 workers): ~55 workers. A
  bare PSR-7 worker is ~20 MiB → ~1.1 GiB, about 2× more memory. Also 55 workers
  × 3 backends = 165 DB connections, so PostgreSQL's default
  `max_connections = 100` is already broken. SConcur keeps one connection pool
  per process inside the extension, and the measurement ran with a cap of 5
  connections per process.
- php-fpm (model, no fpm reference in this repo): the same ~55 workers, but a
  worker with a booted framework is 60–120 MiB → 3–7 GiB, i.e. 6–12×, plus the
  per-request bootstrap CPU (at 10 ms of bootstrap, 1 370 rps costs ~14 cores of
  pure framework boot). In practice fpm does not reach this throughput on this
  hardware at all.

The longer the I/O waits (external APIs at 200–500 ms, slow queries), the worse the
worker model's arithmetic: workers scale with latency, a suspended fiber costs near
zero.

## Honest limits

- CPU-bound PHP loads the whole process together with every request it is
  currently serving — worse than the per-worker isolation of fpm/RoadRunner.
  Mitigations: [automatic preemption](coroutine-switching.md) bounds how long
  such code delays the other requests in the process, the per-core pool spreads
  requests over processes, `handlerTimeoutMs` still answers 504, `maxRequests`
  recycles workers; a watchdog for hung workers is on the roadmap. A native
  blocking call, or a single computation that never returns to the scheduler,
  still freezes the process — preemption cannot interrupt those.
- Every call crosses the PHP↔extension boundary and costs ~50 µs, which makes cheap
  point reads slower than the native driver at any dataset size. Large payloads
  cost ~1.7–2.6 ms per MB in each direction, and the results of all operations
  running at once are held in memory together (RSS ≈ number of concurrent
  operations × result size) — limit how many run at once
  (`WaitGroup::create(maxConcurrency: N)`) when the results are big
  ([payload size](benchmarks.md#payload-size)).
- The gains apply only to code that goes through the SConcur API. PDO-based ORMs
  (Eloquent, Doctrine) gain nothing until their queries are ported — which
  decides where SConcur realistically fits: new services and hand-written query
  code, not a drop-in migration of an existing framework app.

## When to choose what

- php-fpm — classic request/response sites with cheap short requests, the simplest
  operations story, shared hosting.
- RoadRunner — an existing framework app that wants to drop the per-request
  bootstrap without touching how it talks to databases.
- Swoole — the same concurrency effect on native drivers and cheaper per
  request, as long as the drivers you need are covered by its runtime hooks
  (`ext-mongodb` is not).
- SConcur — I/O-intensive services where one request makes many calls or waits a
  lot: aggregators and BFF gateways, MongoDB-heavy backends, queue consumers and
  ETL, handlers making many external calls. Code is written against the SConcur
  API, so it fits new services best.

The technology side is proven by the measurements above; how far the project goes
is decided by ecosystem bridges rather than by the extension — framework
integration packages, splitting the core and features into separate packages,
optimizing the synchronous path, and the hung-worker watchdog (see the
[roadmap](../README.md#roadmap)).
