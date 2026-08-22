<?php

declare(strict_types=1);

namespace SConcur\Exceptions;

/**
 * A coroutine ran past the deadline its `WaitGroup::add(timeoutMs: …)` gave it, and the
 * scheduler unwound it where it stood.
 *
 * It extends FlowStoppedException because it is one: a deliberate unwind, not a failure of
 * the work. Everything that already re-throws a stop as-is — the feature executor, the AMQP
 * channel and its consumer runtime — keeps working unchanged, and `catch
 * (CoroutineTimeoutException)` is there for code that wants to tell a deadline from a group
 * being stopped.
 *
 * Catching it inside the callback is what keeps a timeout local: a coroutine that catches it
 * and returns a value settles like any other, and its siblings never learn about it. One
 * that lets it escape fails its group, as any uncaught exception does.
 */
class CoroutineTimeoutException extends FlowStoppedException
{
}
