# Reading a soak

How the worker master's memory and failure soaks are run, read and taken apart, and the
traps each reading has. Written after the 0.17.0 checks: an hour-long memory soak, 38
failure scenarios, one bug of the branch found by comparing two launches. The same methods
apply to any long run where the question is "does something keep a piece of what passed
through it".

## The tools

| What | Run | Answers |
|---|---|---|
| `tests/benchmarks/worker/memory.php` | `make bench-worker-memory`, `make bench-worker-memory-soak` | what a pool costs by launch (exec, fork, bootstrap) and how it moves under a long load; reads every worker's PHP heap through `/memory` |
| `tests/mem-leak/worker-soak.php` | `make mem-leak-worker scenario=<list> seconds=<n> launch=<list>` | whether the master, the fork server or the surviving workers keep a piece of failures repeated for minutes; why each failed background request failed |
| `tests/mem-leak/worker-soak-summary.php` | `make mem-leak-worker-summary file=<output> [scenario=...]` | the soak's output laid out: first read after the warm-up against the last, losses per death; every read of one scenario |
| `tests/mem-leak/worker-exit-timing.php` | `make mem-leak-worker-exit-timing launch=<one> seconds=<n>` | how long each step of a worker's death by `exit()` takes, beside the requests it cost |

Save a run's output (`> .bench-runs/<name>.txt`, gitignored): the soak prints its verdicts
only at the end, and a run of everything takes an hour and a half. The outputs of the
0.17.0 checks are in `.bench-runs/soak-2026-10-22/`.

## Memory: a leak or a warm-up

- Read anonymous memory, not Pss. Under memory pressure the kernel drops clean file pages
  instead of swapping them, so Pss + SwapPss falls while nothing was freed. What a process
  made itself is Anonymous + Swap (or Private_Dirty + Swap) from `smaps_rollup`.
- Read the PHP heap beside it. `memory_get_usage()` is what PHP holds,
  `memory_get_usage(true)` what it took from the system. A heap flat after the first
  minute while the process's memory still creeps is pages being touched for the first
  time (allocator arenas, OPcache, the runtime's buffers), not a leak. In the hour-long
  run the heap did not move after the first minute and `real` stayed at one value; the
  rest grew 0.6 MiB for the pool over the last half hour and was fading.
- Judge a slope over the last half of the run, not from the start: the first minutes
  carry the warm-up.

## Descriptors, threads, zombies

- A master of an exec group and a fork server hold three pipes per worker (stdout,
  stderr, heartbeat). Count descriptors beyond those ("own"), or every restart looks
  like growth.
- A read can land while a worker is being spawned or reaped, and the own count then
  swings either way: a worker the read found whose pipes are already closed, a child
  forked whose pipes are open but which the read did not find. Under a crash loop it
  swung between 6 and 18 around a steady total. So growth is the least value of the
  second half of the reads over the most of the first, not first-against-last, and a
  SUSPECT on descriptors is checked against the raw total (`scenario=` in the summary).
- A zombie is a leak only when the same pid is there in two reads in a row. A new pid in
  every read is a death not yet reaped.
- A fork server read at 0 MiB is one caught right after it was replaced.

## Losses: the requests a failure costs

- The background load is a closed loop: CONCURRENCY clients, each sending its next
  request once the last is answered. It shows losses well and exaggerates one: a worker
  that accepts but never answers (hung) pulls every client into itself, so the load
  collapses (hundreds of answers in minutes instead of millions). Under open traffic
  the same worker costs its share for as long as it hangs.
- Failures that end a worker are paced (every 500 ms, every 2.5 s), not fired by
  every client: failing all the time, the restart backoff grows and the pool is empty
  rather than recovering.
- Count losses per death (failed requests ÷ worker exits) or per event, not in total:
  scenarios differ in how many events they make.
- Compare deaths that differ in one thing. SIGKILL closes everything at once and cost
  0.6 requests a death; `oom`, `fatal` and, in a forked worker, `exit` cost 6.5–7: the
  worker's runtime goes on accepting while PHP dies from inside. `exit` in exec cost
  0.3 — the step it does and the other does not was the lead.

## Taking apart a difference between two launches

When one launch loses more than the other for the same failure, find out whether a step
is slow or skipped:

1. Time the death: `make mem-leak-worker-exit-timing` for both launches. The worker
   notes `exit()` and brackets its shutdown functions (a shutdown function registered
   from inside a shutdown function runs after every one queued before it); the tool
   polls `/proc/<pid>/stat` every 0.2 ms for the zombie state. Both launches finished
   their shutdown functions in ~0.1 ms and died in 3 and 5 ms: nothing was slow.
2. Equal times and unequal losses mean a step was skipped. Look for what runs at the end
   of one process and not the other: destructors (a fork marks the parent's objects
   destroyed — `sconcur_after_fork_in_child()` — so a singleton the fork server created
   never destructs in a worker), shutdown functions registered before the fork, `_exit`
   paths that skip PHP's shutdown.
3. Fix, rerun the one scenario for both launches (`scenario=exit,fatal seconds=40` with
   `WARMUP_SECONDS=10`), compare losses per death.

## Proving the test catches it

- Run the new test without the fix (`git stash push <the fixed files>`, run,
  `git stash pop`) and see it fail; then with the fix, a few times.
- Prefer a deterministic signal over a timing window. Two signals tried for the
  `exit()` fix were not: the access log writer writes as soon as a line arrives (a
  missing flush loses nothing in practice), and a request already handed to PHP is
  dropped on `exit()` in exec too. What held was the mechanism itself: the forked worker
  has an extension handle of its own.

## Noise

- The host swaps and runs other loads; check `uptime` and swap before measuring.
  Throughput numbers from a noisy host are not re-measured into the docs; leak verdicts
  (slopes, floors, the same zombie twice) survive noise, rates do not.
- A test that fails once in a full suite and passes alone three times, and in a second
  full run, is noise on a busy host; one that fails again is not.
