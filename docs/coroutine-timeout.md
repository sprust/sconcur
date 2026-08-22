English | [Русский](coroutine-timeout.ru.md)

# Coroutine timeout

Work can be given a deadline. Past it the scheduler unwinds the coroutine where it
stands, by throwing `CoroutineTimeoutException` into it, and the code decides what
that means.

```php
use SConcur\Exceptions\CoroutineTimeoutException;
use SConcur\Limiter;

try {
    return Limiter::on(ms: 1000, callback: fn() => handle($job));
} catch (CoroutineTimeoutException) {
    return null;   // did not make it
}
```

The deadline belongs to the coroutine that is running, so this works anywhere one
does: inside a `WaitGroup` member, inside a server handler, inside a nested group.

The shorthand for "the whole callback gets a second" is an argument on `add()`:

```php
$waitGroup->add(
    function () use ($job) {
        try {
            return handle($job);
        } catch (CoroutineTimeoutException) {
            return null;
        }
    },
    timeoutMs: 1000,
);
```

The two are the same mechanism. `Limiter::on()` bounds a piece of work and
composes; `add(timeoutMs: …)` bounds a callback from its first line, which is what
you want when the caller — not the callback — decides how long it may take.

## Catching it is what keeps it local

A coroutine that catches its timeout and returns settles like any other one, and
its siblings never learn about it:

```php
$waitGroup->add(function () {
    try {
        return Limiter::on(ms: 300, callback: fn() => slow());
    } catch (CoroutineTimeoutException) {
        return 'gave up';
    }
});

$waitGroup->add(fn() => fast());     // untouched by its neighbour's deadline

$waitGroup->waitAll();
```

A coroutine that lets the exception escape fails its group — and failing a group
unwinds every other member of it, because `WaitGroup::iterate()` stops the group on
the way out. So letting a timeout escape is a decision:

```php
try {
    $waitGroup->waitAll();
} catch (CoroutineTimeoutException $exception) {
    // one coroutine ran out of time and did not catch it;
    // the rest were stopped along with it
}
```

Both are legitimate. Catch inside when one slow job should cost only itself; let it
escape when the group is all-or-nothing.

In a coroutine spawned by the scheduler — a server request handler — there is no
group to fail, so an uncaught timeout is dropped like any other uncaught exception
there. Handlers catch their own.

## Nesting

Scopes nest, and the shorter allowance wins:

```php
Limiter::on(ms: 1000, callback: function () {
    // Asks for ten seconds inside a scope that has one; gets what is left of the one.
    return Limiter::on(ms: 10_000, callback: fn() => work());
});
```

An inner scope cannot buy more time than the outer one is holding — the outer
allowance is a promise someone else made. On the way out the previous deadline is
put back, so a scope that finished in time leaves the coroutine unbounded again.

## The exception

`SConcur\Exceptions\CoroutineTimeoutException` extends `FlowStoppedException`, the
project's signal for a deliberate unwind. Three consequences:

- everything that already re-throws a stop as-is keeps working unchanged — the
  feature executor, the AMQP channel, the consumer runtime;
- `catch (FlowStoppedException)` catches a timeout too, so cleanup written for one
  covers the other;
- it reaches its group unwrapped. Every other uncaught exception arrives as a
  `CallbackExecutionException` with the original inside; a deliberate unwind stays
  recognizable instead.

## When the clock starts

With `Limiter::on()`, when the scope is entered. With `add(timeoutMs: …)`, when the
callback starts running — not at `add()`.

The difference shows with a concurrency limit. A group created with
`WaitGroup::create(maxConcurrency: 4)` queues the fifth callback until a slot frees;
counting from `add()` would hand it an allowance already spent waiting for its
turn, and a busy group would time out callbacks that never ran a line.

## Where the deadline can reach

The scheduler enforces it at the points where it already takes control:

| The coroutine is | What happens |
| --- | --- |
| waiting for a task (any SConcur I/O, `Sleeper`) | unwound at the deadline, wherever it waits |
| parked by `Scheduler::switch()` | unwound before it would have been resumed |
| running PHP, with preemption armed | unwound by the preemption hook, between two opcodes |
| running PHP, without preemption | unwound at its next suspension point |
| inside a cgo call | **not** unwound until the call returns |

The last two rows are the honest limits.

**Without preemption** the deadline is cooperative. Servers arm preemption while
serving (the `preemptionQuantumMs` option), so handler code is interruptible there;
a CLI script or library code has to ask — `Scheduler::get()->enablePreemption()`,
see [coroutine switching](coroutine-switching.md). Without it a loop that computes
and never waits runs to its end, and the timeout is delivered afterwards.

**Inside a cgo call** no PHP runs at all, so nothing can be delivered. A query, a
request or a publish that hangs is bounded by that feature's own timeout on the Go
side — `rpcTimeout` and `readTimeout` on an AMQP connection, the HTTP client's own
deadlines — and not by this one. A coroutine deadline bounds the PHP coroutine,
which is not the same as bounding everything it can wait for.

Outside a coroutine there is nothing to unwind, so `Limiter::on()` runs the
callback unbounded — the same rule the rest of the library follows for code that is
not in a concurrent context.

## What it does not release

Unwinding runs the coroutine's `finally` blocks and destroys its locals, which is
what returns a transaction, a lock or a channel. But an unwound coroutine leaves
its objects in a reference cycle, so the release lands when the garbage collector
reaches them rather than at the moment of the timeout. Where that matters — an AMQP
delivery stays owed to the broker until its channel closes — the feature releases
what it holds itself; where it does not, the difference is invisible.

## Cost

A coroutine with no deadline costs nothing: the scheduler keeps the deadlines in a
list of their own, and every check begins by finding it empty.

One with a deadline costs a comparison at each of the scheduler's decision points,
and it shortens the scheduler's blocking wait to the nearest deadline — which is
what makes a timeout fire on an idle process, where no other result would have
woken the loop.

## Stopping the whole group

Easy to confuse with the above: `WaitGroup::stop()` unwinds every member at once,
with a plain `FlowStoppedException`. That is the tool for "abandon this work", where
a deadline is the tool for "this one job gets a second".
