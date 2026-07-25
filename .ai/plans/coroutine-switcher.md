# Coroutine switcher: `Scheduler::switch()`

Cooperative yield for CPU-bound coroutine code. A handler crunching data in a loop
calls `Scheduler::get()->switch()` periodically; the scheduler parks the coroutine,
lets everything that is ready make progress (delivered results, new incoming server
requests), then resumes it. The Swoole preemptive-scheduler effect, achieved
cooperatively and purely on the PHP side.

## Goal and non-goals

- Goal: latency fairness. A CPU-heavy handler stops starving the other in-flight
  coroutines of its process; neighbours' p99 becomes bounded by the switch quantum
  instead of the heavy handler's full runtime. Graceful shutdown also stays responsive
  during heavy handlers.
- Non-goal: throughput. The PHP thread is still one; total CPU work is unchanged (plus
  a small switching cost). The benchmarks-doc verdict "CPU-bound → per-core pool"
  stays.
- Non-goal: preempting code that does not call `switch()` (third-party loops, a single
  huge `json_decode`/`preg_match`). Automatic preemption (`pcntl_alarm` +
  `Fiber::suspend()` from the signal handler) is a separate experiment, not this plan.

## API

`Scheduler::switch(int $quantumMs = 5): bool` — instance method (called as
`Scheduler::get()->switch()`; `switch` is a valid method name since PHP 7).

- Returns `true` when the coroutine actually yielded, `false` when the call was a
  cheap no-op.
- No-op cases: called outside a fiber (the sync path — callers need no guards); called
  from a fiber the scheduler does not track; the quantum has not elapsed yet.
- Quantum: the first `switch()` call of a coroutine records the timestamp and returns
  `false` (the quantum starts counting there); each later call yields only when
  `hrtime(true) - lastSwitchNs >= quantumMs * 1_000_000`, then re-records. So the call
  can sit inside a hot loop: in the common case it costs one `hrtime()` and one
  comparison.
- `quantumMs <= 0` — always yield (tests, explicit switch points).

No extension changes: parking and resuming is pure scheduler bookkeeping, and the
non-blocking poll it needs already exists (`Extension::waitAnyTimeout(0)` —
`popAnyPending` first, then an immediately-firing timer → `null`).

## Mechanics

New pieces in `Scheduler`:

- `Dto/PendingSwitchDto` — an empty marker DTO the yielding coroutine suspends with
  (the third pending kind after `PendingPushDto`/`PendingNextDto`).
- `protected array $switchedCoroutines = []` — FIFO of fiber ids parked by `switch()`.
- `protected array $lastSwitchNs = []` — per-fiber-id quantum timestamps
  (`Coroutine` is readonly, so the map lives here); entries are released in
  `forget()`/`detach()` and cleared in `shutdown()`.

Flow:

1. `switch()` passes the guards → `Fiber::suspend(new PendingSwitchDto())`. Error
   handling mirrors `awaitGroup`: `FlowStoppedException` is re-thrown as-is (a
   stop/shutdown unwinding through the parked coroutine), any other `Throwable` wraps
   into `FiberStateException`.
2. The resuming side sees the suspend value. `dispatchPendingTask()` gets a new
   branch: `PendingSwitchDto` → append the fiber id to `$switchedCoroutines`, return.
   (Today a suspend value it does not recognize is ignored — the awaitGroup contract —
   so the new branch is additive.)
3. Draining. Results always take priority; a parked coroutine resumes only when
   nothing is deliverable right now:
   - `tick()` (the `run()` loop): queue empty → blocking `waitAny()` as today. Queue
     non-empty → `waitAnyTimeout(0)`; a result resumes its owner, `null` resumes the
     queue head.
   - `serve()`: poll timeout becomes `switchedCoroutines === [] ? 250 : 0`; on a
     `null` result with a non-empty queue, resume the queue head and loop. Incoming
     requests keep being accepted between switches — a parked heavy handler does not
     stop `spawn`-on-request.
4. Resume value is `null`; `switch()` returns `true` after the resume. One parked
   coroutine is resumed per loop iteration, so deliverable results interleave with the
   queue and two CPU loops round-robin each other.

Edge cases:

- `WaitGroup::stop()` / `Scheduler::shutdown()` while parked: the fiber is suspended,
  so the existing unwind (`fiber->throw(FlowStoppedException)`) works; the queue entry
  goes stale. The drain skips ids missing from `$this->coroutines`
  (`resumeNextSwitched()` loops until it finds a live one or empties the queue).
- A parked coroutine's group must not report "settled" early: parking does not touch
  group bookkeeping — the coroutine stays a live member (exactly like one awaiting a
  task result), so `isLive()`/`wakeGroupWaiters` semantics are unchanged.
- Livelock: impossible by construction — every drain iteration first polls for a
  deliverable result with `waitAnyTimeout(0)`.
- Re-entrancy: `switch()` from the outermost (non-scheduler) code path is a no-op by
  the fiber guard; nested `WaitGroup` coroutines are ordinary tracked fibers and just
  work.

## Tests

`tests/feature/Scheduler/SchedulerSwitchTest.php` (BaseAsyncTestCase where the event
framework helps):

- `switch()` outside a fiber returns `false` (and changes nothing).
- Interleaving: coroutine A appends events in a loop calling
  `switch(quantumMs: 0)`; coroutine B appends one event. B's event lands between A's
  iterations, not after them all.
- Quantum: with a large `quantumMs` the first call returns `false` and a tight second
  call returns `false`; with `quantumMs: 0` calls return `true` (after the first).
- Round-robin: two switching CPU coroutines interleave each other's events.
- Stop while parked: `WaitGroup::stop()` with a coroutine parked in `switch()` unwinds
  it (FlowStoppedException path), no scheduler-state leak
  (`switchedCoroutines`/`lastSwitchNs` clean).

Server-level E2E (`tests/feature/Features/HttpServer/`): a new demo route
`/cpu-switch/{n}` in `tests/servers/http/http-server.php` — the `/cpu/{n}` sha256 loop
with `Scheduler::get()->switch(quantumMs: 1)` inside. The test starts a heavy
`/cpu-switch` request, then a `/ping`-style request while it runs, and asserts the
light one completes while the heavy one is still in flight (the `/native-msleep`
counter-example already proves the opposite for non-yielding code).

## Out of scope / follow-ups

- Automatic preemption experiments (signal/tick-based) — separate plan if ever.
- Docs on shipping: a "CPU-bound handlers" subsection in `docs/http-server.md` (+ru)
  and a note in `docs/benchmarks.md`'s verdict table row about CPU-bound (the ❌ stays,
  with "latency can be smoothed with Scheduler::switch()" pointing at the server doc).
- No extension version bump: the PHP↔Go protocol is untouched.
