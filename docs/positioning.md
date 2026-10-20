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
| A high-concurrency I/O-bound HTTP/WS service | On the same hardware SConcur serves [~4.8× the requests per second](#throughput-on-the-same-hardware) RoadRunner does, and holding that load takes ~2× less memory than RoadRunner and ~6–12× less than php-fpm — [resources](#resources-to-hold-the-same-load) | ✅ |
| One request or job needs several DB or network calls | Running them concurrently instead of one after another finishes SQL writes [~13–22×](benchmarks.md#mysql) faster, heavy reads [~2–7×](benchmarks.md#mongodb), network waits [~49×](benchmarks.md#clients-http--socket--websocket) | ✅ |
| MongoDB with concurrency | SConcur is the only way to have several MongoDB operations in progress at once inside one PHP process — [tables](benchmarks.md#mongodb) | ✅ |
| Single cheap queries, one at a time (SConcur used as a library) | Slower than the native driver: a sequential call through the [PHP↔extension boundary](benchmarks.md#conversion-overhead-the-phpextension-boundary) costs ~30–150 µs more than the native one (MongoDB `findOne` ~30, MySQL ~65, PostgreSQL ~150), more than a cheap query itself takes | ❌ |
| Megabyte payloads per operation | A 1 MB point read through the boundary costs ~0.3–0.6 ms more per call than the native SQL drivers, and the results of all operations running at once are held in memory together: 50 concurrent 1 MB reads peak at ~106–112 MB against 8–12 MB native — [payload size](benchmarks.md#payload-size) | ❌ |
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
coroutine (a PHP Fiber that is suspended while its call waits in the extension),
so while one handler waits for a database, the process is already running the
next request — no `WaitGroup` needed. The `/all-nowg` endpoint,
which makes its six calls strictly one after another, still holds ≈2 770 rps
against RoadRunner's ≈612 on the same operations
([load testing](load-testing.md#concurrent-vs-sequential-calls-all-vs-all-nowg)).
Only on microsecond cache hits does the edge shrink to what the server layer
alone gives (~4.8× on the empty endpoint) — it never turns into a loss.

## Execution models

| | php-fpm | RoadRunner | SConcur |
| --- | --- | --- | --- |
| Model | a process per request, from a pool | a long-lived worker per request | a long-lived process serving N requests concurrently |
| Framework bootstrap | every request | once | once |
| I/O wait | the worker is held by the request and accepts nothing else | the worker is held by the request and accepts nothing else | the coroutine is suspended and the process serves other requests meanwhile |
| Throughput ceiling on I/O-bound load | workers × request time | same, minus the bootstrap | how fast the databases and external services answer — waiting itself no longer limits it |
| A CPU-bound request | blocks 1 worker of N | blocks 1 worker of N | slows the whole process; how long it delays the other requests is bounded by [preemption](coroutine-switching.md) |
| Memory under concurrency | RSS × workers, grows with the number of requests being served | RSS × workers, grows with the number of requests being served | ~46 MiB per process, one process per core, and it does not grow with the number of requests |

php-fpm and RoadRunner share the trait that matters under load: a request holds
a worker for its full duration, I/O waits included. RoadRunner removes the
per-request bootstrap and keeps connections alive, which buys 2–10× on light
endpoints — but the worker-per-request model stays. SConcur keeps the
long-lived-worker gains and breaks that trait: a request waiting on I/O costs a
suspended fiber, not a worker.

## Throughput on the same hardware

12 workers each, `wrk` 4 threads / 256 connections / 20 s, disk-backed backends
(details in [benchmarks](benchmarks.md#comparison-with-roadrunner-and-swoole)).
`/all` makes six DB calls through three features — a feature is one of the
library's integrations in `src/Features/`, here MongoDB, MySQL and PostgreSQL:

| Endpoint | Server | Requests/sec | p50 | CPU avg | MEM peak |
| --- | --- | ---: | ---: | ---: | ---: |
| `/` (empty) | SConcur | ≈248 300 | 0.84 ms | ~1102% | ~196 MiB |
| `/` (empty) | RoadRunner | ≈52 000 | 4.7 ms | ~1000% | ~246 MiB |
| `/all` (3 features, 6 DB ops) | SConcur | ≈2 950 | 83 ms | ~205% | ~244 MiB |
| `/all` | RoadRunner (native drivers) | ≈612 | 416 ms | ~34% | ~258 MiB |

On the empty endpoint the gap is ~4.8×: RoadRunner pays an extra inter-process
step (proxy → worker) on every request, SConcur pays the PHP↔extension boundary,
which is the cheaper of the two. On `/all` the gap is ~4.8× and structural: the
sequential worker performs its 3 disk commits one after another and idles at
~34% CPU while all 12 workers sit in that chain; SConcur runs those same commits
at the same time — both the ones inside a single request and the ones belonging
to different requests.

Swoole is measured beside the other two where the workload is the same for all
three: the empty endpoint and the `/db`, `/db-rw` ladders in
[benchmarks](benchmarks.md). On `/all` a Swoole worker blocks — `ext-mongodb` is
outside its runtime hooks.

## Resources to hold the same load

The number of requests being served at any moment is throughput × latency
(Little's law). The worker model needs a worker for each of them; SConcur needs
a fiber. That is where the resource difference lives — not in CPU, which is
comparable per request: on `/all` SConcur spends ~0.7 cores per 1 000 rps
against RoadRunner's ~0.6, on the empty endpoint 0.04 against 0.19.

To hold the measured ≈2 950 rps of the `/all` workload:

- SConcur (measured): 12 per-core processes, ~549 MiB of worker RSS total (~46
  MiB each), and that figure does not grow with the number of requests being
  served.
- RoadRunner (linear extrapolation from 612 rps at 12 workers): ~58 workers. A
  bare PSR-7 worker is ~20 MiB → ~1.1 GiB, about 2× more memory. Also 58 workers
  × 3 backends = 174 DB connections, 58 on each database, and that count follows
  the worker count, not the load (PostgreSQL's default `max_connections` is
  100). SConcur keeps one connection pool per process inside the extension, and
  the measurement ran with a cap of 5 connections per process.
- php-fpm (model, no fpm reference in this repo): the same ~58 workers, but a
  worker with a booted framework is 60–120 MiB → 3–7 GiB, i.e. 6–12×, plus the
  per-request bootstrap CPU (at 10 ms of bootstrap, 2 950 rps costs ~30 cores of
  pure framework boot). In practice fpm does not reach this throughput on this
  hardware at all.

The longer the I/O waits (external APIs at 200–500 ms, slow queries), the worse the
worker model's arithmetic: workers scale with latency, a suspended fiber costs near
zero.

## Honest limits

- CPU-bound PHP loads the whole process together with every request it is
  currently serving — worse than the per-worker isolation of fpm/RoadRunner.
  Mitigations: [automatic preemption](coroutine-switching.md), when turned on,
  bounds how long such code delays the other requests in the process, the per-core pool spreads
  requests over processes, `handlerTimeoutMs` still answers 504, `maxRequests`
  recycles workers, and the master's watchdog kills and replaces a worker whose
  PHP thread stopped answering ([stuck worker](worker-master.md#stuck-worker)). What
  preemption cannot interrupt is a native blocking call (`sleep`, PDO, `curl`)
  or one long internal call — `preg_match` over a huge subject, `json_decode` of
  a huge blob: the interrupt is serviced at an opcode boundary, and neither of
  those reaches the next boundary until it returns. Both freeze the process for
  their whole duration — see
  [coroutine switching](coroutine-switching.md#semantics-to-be-aware-of).
- Every call crosses the PHP↔extension boundary, so cheap point reads stay
  slower than the native driver at any dataset size, and large results of
  operations running at once are held in memory together — the numbers are in
  the table [above](#is-sconcur-for-you). Limit how many run at once
  (`WaitGroup::create(maxConcurrency: N)`) when the results are big
  ([payload size](benchmarks.md#payload-size)).
- The gains apply only to code that goes through the SConcur API. Raw PDO and
  Doctrine gain nothing until their queries are ported. Laravel is the exception:
  [sconcur/laravel](https://github.com/sprust/sconcur-laravel) serves requests
  from a concurrent worker whose application state is per coroutine, and points
  Eloquent, `Cache::`, `Redis::` and the queue at the matching features, so the
  application's own query code stays as it is. Elsewhere the fit is what it was:
  new services and hand-written query code, not a drop-in migration of a
  framework app.

The technology side is proven by the measurements above; what is left is the
work around it — installing the extension through PIE, and stopping a single
coroutine rather than a whole flow, the group of tasks a `WaitGroup` owns (see
the [roadmap](../README.md#roadmap)).
