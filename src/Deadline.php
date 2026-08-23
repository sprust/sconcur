<?php

declare(strict_types=1);

namespace SConcur;

use Closure;
use SConcur\Exceptions\CoroutineTimeoutException;
use SConcur\Exceptions\InvalidCoroutineTimeoutException;
use SConcur\Scheduler\Scheduler;

/**
 * Runs a piece of work under a deadline.
 *
 * ```php
 * try {
 *     return Deadline::run(timeoutMs: 1000, callback: fn() => handle($job));
 * } catch (CoroutineTimeoutException) {
 *     return null;
 * }
 * ```
 *
 * The deadline belongs to the coroutine that is running, so this works anywhere one does —
 * inside a WaitGroup member, inside a server handler spawned by the scheduler, inside a
 * nested group. `WaitGroup::add(timeoutMs: …)` is the same thing said once for a whole
 * callback.
 *
 * Scopes nest, and the shorter allowance wins: a scope asking for ten seconds inside one
 * that has one second left gets the second. On the way out the previous deadline is put
 * back, so an inner scope cannot lengthen an outer one, and a scope that finished in time
 * leaves nothing behind.
 *
 * Outside a coroutine there is nothing to unwind, so the callback simply runs — the same
 * rule the rest of the library follows for code that is not in a concurrent context.
 */
class Deadline
{
    /**
     * @template TReturn
     *
     * @param int                $timeoutMs how long the callback may take; 0 means no deadline,
     *                                      so it runs under whatever bound it is already under
     * @param Closure(): TReturn $callback  the work to bound
     *
     * @return TReturn
     *
     * @throws InvalidCoroutineTimeoutException if the timeout is negative
     * @throws CoroutineTimeoutException if the callback outlives the deadline — thrown into
     *                                   the coroutine wherever it stands, so the callback's
     *                                   own finally blocks run on the way out
     */
    public static function run(int $timeoutMs, Closure $callback): mixed
    {
        return Scheduler::get()->withDeadline(
            timeoutMs: $timeoutMs,
            callback: $callback,
        );
    }
}
