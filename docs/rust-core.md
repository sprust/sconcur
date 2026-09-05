English | [Русский](rust-core.ru.md)

# The move to Rust

The extension core was ported from Go to Rust through August and September 2026.
The Go core was deleted on 2026-09-04, toolchain and all; nothing in the tree
depends on it or is checked against it any more. This page is the record of what
the port was asked to prove and what it measured — history, not a comparison the
project keeps alive.

## Contents

- [The question, and the gate set before the code](#the-question-and-the-gate-set-before-the-code)
- [The floor and the boundary](#the-floor-and-the-boundary)
- [The pathology that disappeared](#the-pathology-that-disappeared)
- [fork and PHP-FPM](#fork-and-php-fpm)
- [What it gave on the benchmarks](#what-it-gave-on-the-benchmarks)
- [What it did not give](#what-it-did-not-give)

## The question, and the gate set before the code

The cost of a request had already been attributed: on the Go core an empty
request was ~91 µs of CPU, of which the extension floor was 14.2 µs and the
PHP↔extension boundary another 29.8 µs. The open question was how much of that
boundary was the idea — packing MessagePack and crossing — and how much was cgo
specifically: `needm`/`dropm` on entry, waking a cgo-locked M, copying strings.

A threshold was fixed before a line was written, so the answer could not be
argued into being good enough:

> The floor ≤ 9 µs and the boundary ≤ 18 µs — then there is something to discuss.
> Missed, and the question is closed for good.

The metric was microseconds of CPU per request (`CPU_avg% * 10000 / rps`), not
requests per second, because rps mixes efficiency with saturation.

## The floor and the boundary

Two ladder steps run by the same PHP script, differing only in whether the
request reaches PHP at all: `L0` is the server answering by itself, `L1` is every
request crossing into PHP and back, so `L1 − L0` is the boundary and nothing
else. Six workers pinned, `wrk` 4 threads / 128 connections / 15 s, three rounds
with the cores interleaved round by round, medians. The machine carried unrelated
load throughout, so the deltas are the subject and the absolute numbers are not.

| Step | Go, µs CPU/request | Rust, µs CPU/request | Delta |
| --- | ---: | ---: | ---: |
| L0 — the server answers, PHP is never called | 11.85 | 6.31 | −47% |
| L1 — every request goes into PHP and back | 28.24 | 17.64 | −38% |
| the boundary (L1 − L0) | 16.39 | 11.33 | −31% |

Both gate conditions were met, and the port went ahead.

## The pathology that disappeared

The gate did not ask about this, and it turned out to matter more than the
percentages.

Pushing a task from inside a coroutine was, on the Go core, catastrophically
expensive. A cgo call made with the stack pointer inside a Fiber stack makes the
Go runtime recompute the bounds of the system stack (`x_cgo_getstackbound` →
`pthread_getattr_np`), and glibc answers that, for the main thread, by reading and
parsing all of `/proc/self/maps`.

Single runs, same geometry, a fresh Fiber per request:

| Form | Go, µs CPU/request | Rust, µs CPU/request |
| --- | ---: | ---: |
| push made off the Fiber stack | 38.92 | 35.67 |
| push made on the Fiber stack | 273.24 | 27.55 |
| ratio between the two | ×7.0 | ×0.77 |

On Rust the second form is not merely un-degraded, it is *cheaper* than the
first, because the fiber finishes inside `start()` without a suspend/resume pair.

This is the part that paid for itself in code rather than in microseconds. The
deferred-dispatch queue in the scheduler, and the rule that a cgo call is never
made from a coroutine stack, existed for no other reason than to route around
this. On a core with no cgo, the queue is not a workaround that got cheaper — it
is unnecessary, and it has since been deleted. Pushing from a coroutine went from
forbidden to preferred.

## fork and PHP-FPM

The Go runtime starts inside `dlopen`, before `MINIT`, so a process that had
merely loaded the extension could not usefully fork — "do not touch it before
forking" was not a workaround, because loading was already too late. That is what
put "CLI only, no `pcntl_fork`" in the README.

The Rust core starts its runtime lazily and rebuilds it in a child through a
`pthread_atfork` handler, at the cost of one relaxed atomic on the hot path. A
check counts only if 12 coroutines of 100 ms each really ran at the same time,
so a worker that quietly fell back to running them one after another fails it.

| Scenario | Go | Rust |
| --- | --- | --- |
| `fork` before the extension is used at all | hangs | 108.0 ms |
| `fork` after use, runtime already up | hangs | 102.7 ms, parent survives |
| four children forked at once | hangs | ~106 ms each |
| PHP-FPM, 4 static workers, 8 requests | every request hangs to timeout | all 8, ~103 ms each |

This is the one item of the whole port that gave a new class of application
rather than a percentage. The README limit was a property of the Go runtime, not
of the library's architecture, and it is gone.

## What it gave on the benchmarks

The spike projected −12…16% of CPU on an empty request, or roughly 133 500 rps
becoming 151 000–158 000. Measured on the finished port at the same placement the
old figure was taken with, the empty endpoint went 133 500 → 154 432 rps, +16% —
inside the projected band, on a different day and a different session.

The feature tables are a stronger form of evidence than that one, because the
`async vs native` percent is measured inside a single run: both modes execute in
the same process against the same backend minutes apart, so machine drift cancels
out of the ratio. Several MongoDB operations changed sign:

| Operation | Go core, async vs native | Rust core, async vs native |
| --- | ---: | ---: |
| findOne | −8% | +47% |
| updateOne | −27% | +77% |
| deleteOne | −11% | +42% |
| command | −58% | +19% |
| aggregate | −51% | +66% |

Single-document MongoDB calls used to be the standing example of work not worth
running through SConcur. They are not any more: what the concurrent mode overlaps
there is the round trip to the server, and that is now worth more than the
boundary costs.

AMQP carries its own control. Consuming a pre-filled set of queues is measured in
messages per second for all three modes at once, and the native mode — `ext-amqp`,
untouched by the port — reads 121 500 before and 122 100 after, which is what
says the two sessions are comparable at all:

| Mode | Go core, msg/s | Rust core, msg/s |
| --- | ---: | ---: |
| native (`ext-amqp`, unaffected by the port) | 121 500 | 122 100 |
| SConcur, sequential | 22 100 | 53 800 |
| SConcur, concurrent | 82 800 | 111 000 |

Latency tails moved the furthest, and the cleanest measurement of that is a
single worker, where the placement question does not arise because one core is
one mask either way. On the empty endpoint, 256 connections against one worker:
p99 went from 155 ms to 5.6 ms, and the median from 8.4 ms to 0.07 ms.

The same shape appeared during the port itself, on a point-query endpoint driven
by a client that keeps exactly one request in flight per connection:

| Connections | Go rps | Go p99 | Rust rps | Rust p99 |
| ---: | ---: | ---: | ---: | ---: |
| 4 | 9 180 | 1.12 ms | 10 874 | 1.01 ms |
| 16 | 8 654 | 4.56 ms | 11 981 | 1.74 ms |
| 64 | 8 152 | 33.14 ms | 11 706 | 7.85 ms |

## What it did not give

- **Nothing where the backend is the ceiling.** On `/all` and across the DB
  benchmarks the limit is the backends' fsync, and the extension core has no part
  in it. The projection said zero there before the port and the measurement
  agreed.
- **Nothing on the two most expensive steps of a request.** The Fiber machinery
  (~13.9 µs) and the PHP-side plumbing (~33.5 µs) are untouched by which language
  the core is written in. After the port the PHP side is two thirds of an empty
  request, so everything the core can still win is a fight over the remaining
  third.
- **No smaller feature code, as a rule.** The MongoDB feature came out at 2 247
  lines against 4 073, but that is the driver's `Document` building BSON where Go
  assembled it byte by byte — not a property of the language.
- **No free correctness.** The port re-ran the project's own suites as its
  specification, and MongoDB landed on 109/109 with 21 521 assertions, exactly
  what the Go core produced. Getting there meant finding five ways the wire
  contract had been silently misread, each of which corrupted data rather than
  failing loudly.
