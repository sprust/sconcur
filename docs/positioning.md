English | [Русский](positioning.ru.md)

# Positioning: SConcur vs php-fpm and RoadRunner

What SConcur changes in the PHP execution model, what that costs in resources under
high load, and where it pays off. The numbers come from
[feature benchmarks](benchmarks.md) and [load testing](load-testing.md) — the same
hardware, the same handles, RoadRunner measured side by side under an identical harness.

## Execution models

| | php-fpm | RoadRunner | SConcur |
| --- | --- | --- | --- |
| Model | a process per request, from a pool | a long-lived worker per request | a long-lived process serving N requests concurrently |
| Framework bootstrap | every request | once | once |
| I/O wait | occupies the worker | occupies the worker | overlaps (the fiber yields) |
| Throughput ceiling on I/O-bound load | workers × request time | same, minus the bootstrap | bounded by the backends, not by the waits |
| A CPU-bound request | blocks 1 worker of N | blocks 1 worker of N | blocks the process with all its in-flight requests |
| Memory under concurrency | RSS × workers, grows with in-flight | RSS × workers, grows with in-flight | ~50 MiB × per-core processes, flat |

php-fpm and RoadRunner share the trait that matters under load: a request occupies a
worker for its full duration, I/O waits included. RoadRunner removes the per-request
bootstrap and keeps connections alive, which buys 2–10× on light endpoints — but the
worker-per-request model stays. SConcur breaks exactly that: a request parked on I/O
costs a suspended fiber, not a worker.

## Throughput on the same hardware

12 workers each, `wrk` 4 threads / 256 connections / 20 s, disk-backed backends
(details and the honesty checks in [benchmarks](benchmarks.md#comparison-with-roadrunner-native-drivers)):

| Handle | Server | Requests/sec | p50 | CPU avg | MEM peak |
| --- | --- | ---: | ---: | ---: | ---: |
| `/` (empty) | SConcur | ≈67 100 | 3.7 ms | ~1210% | ~256 MiB |
| `/` (empty) | RoadRunner | ≈47 100 | 5.3 ms | ~1060% | ~230 MiB |
| `/all` (3 features, 6 DB ops) | SConcur | ≈2 680 | 87 ms | ~740% | ~287 MiB |
| `/all` | RoadRunner (native drivers) | ≈460 | 561 ms | ~160% | ~237 MiB |

On the empty handle the gap is ~1.4×: RoadRunner pays an IPC hop proxy → worker per
request, SConcur pays the PHP↔Go boundary — comparable prices. On `/all` the gap is ~6×
and structural: the sequential worker folds 3 disk commits into a chain and idles at
~160% CPU while all 12 workers sit in that chain; the fan-out overlaps the same commits
within and across requests. The `/all-native` control row in the benchmark doc (the same
sequential code inside the SConcur server → the same ≈460 rps) proves the gap comes from
the execution model, not the server layer.

## Resources to hold the same load

The number of requests in flight is throughput × latency (Little's law). The worker
model needs a worker per in-flight request; SConcur needs a fiber. That is where the
resource difference lives — not in CPU.

CPU per request is comparable: on `/all` SConcur spends ~2.8 cores per 1 000 rps against
RoadRunner's ~3.5; on the empty handle 0.18 against 0.23. SConcur does not save CPU —
the DB work is the same — it saves everything tied to a parked worker.

To hold the measured ≈2 680 rps of the `/all` workload:

- SConcur (measured): 12 per-core processes, ~620 MiB of worker RSS total (~52 MiB
  each), flat regardless of concurrency.
- RoadRunner (linear extrapolation from 460 rps at 12 workers): ~70 workers. The bare
  PSR-7 worker of the reference stack is ~20 MiB → ~1.4 GiB, about 5× more memory. Also
  70 workers × 3 backends = 210 DB connections — PostgreSQL's default
  `max_connections = 100` is already broken (SConcur pools per process on the Go side;
  the run fit in a cap of 5 per process).
- php-fpm (model, no fpm reference in this repo): the same ~70 workers, but a worker
  with a booted framework is 60–120 MiB → 4–8 GiB, i.e. 15–30×, plus the per-request
  bootstrap CPU (at 10 ms of bootstrap, 2 680 rps costs ~27 cores of pure framework
  boot). In practice fpm does not reach this throughput on this hardware at all.

The longer the I/O waits (external APIs at 200–500 ms, slow queries), the worse the
worker model's arithmetic: workers scale with latency, a suspended fiber costs near
zero. With short waits the difference shrinks accordingly.

## What the measurements support

- I/O fan-out: 50 waits of 100 ms overlap into ~120 ms (~44×); disk-backed SQL writes
  win 5–18× ([benchmarks](benchmarks.md)).
- Memory stability: a 10-minute soak (1.74M requests) holds worker RSS flat, slope
  +0.11 MiB/min = noise ([load testing](load-testing.md)).
- Async MongoDB with the official Go driver — Swoole has no coroutine MongoDB path
  (`ext-mongodb`/libmongoc bypasses its runtime hooks), RoadRunner has no in-request
  concurrency at all.

## Honest limits

- CPU-bound PHP blocks the whole process with all its in-flight requests — worse than
  the per-worker isolation of fpm/RoadRunner. Mitigations: the per-core pool spreads
  requests, the Go-side `handlerTimeoutMs` still answers 504, `maxRequests` recycles
  workers; a watchdog for hung workers is on the roadmap.
- The boundary tax (~50 µs per call) makes cheap point reads slower than the native
  driver at any dataset size; moving large payloads costs ~1.5–2.3 ms per MB each way,
  and a fan of large results holds them all in flight at once (RSS ≈ fan width ×
  payload) — cap the fan width on big data
  ([payload-size benchmarks](benchmarks.md#payload-size)).
- The gains apply only to code that goes through the SConcur API. PDO-based ORMs
  (Eloquent, Doctrine) gain nothing until their queries are ported — which shapes where
  adoption is realistic: new services and hand-written query code, not drop-in
  framework migrations.

## When to choose what

- php-fpm — classic request/response sites with cheap short requests, the simplest
  operations story, shared hosting.
- RoadRunner — an existing framework app that wants to drop the per-request bootstrap
  without touching how it talks to databases.
- SConcur — I/O-intensive services where the request fans out or waits a lot:
  aggregators and BFF gateways, MongoDB-heavy backends, queue consumers and ETL,
  handlers making many external calls. Code is written against the SConcur API, so it
  fits new services best.

## Outlook

The technology side is proven by the measurements above; how far the project goes is
decided by ecosystem bridges rather than by the extension. The directions that matter:
framework integration packages (Laravel/Symfony), splitting the core and features into
separate packages, optimizing the synchronous path, and the hung-worker watchdog — see
the roadmap in the [README](../README.md).
