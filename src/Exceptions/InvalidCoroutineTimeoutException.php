<?php

declare(strict_types=1);

namespace SConcur\Exceptions;

use LogicException;

/**
 * A deadline was asked for in negative milliseconds. Zero is not this: everywhere the
 * library takes a timeout, zero means "no deadline".
 *
 * A usage bug rather than a runtime failure, and it is raised before anything is built —
 * by WaitGroup::add() before the member is registered, by Scheduler::spawn() before the
 * coroutine exists, by Deadline::run() before the scope is entered — so a refusal never
 * leaves a half-created coroutine behind.
 */
class InvalidCoroutineTimeoutException extends LogicException
{
}
