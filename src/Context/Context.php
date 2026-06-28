<?php

declare(strict_types=1);

namespace SConcur\Context;

use SConcur\State;

/**
 * Static entry point to the current coroutine's context. Context::current()
 * resolves the running fiber (or the process root when called outside any fiber)
 * and returns a handle whose find/has/set/forget proxy to the per-fiber store in
 * State.
 *
 * The handle captures the coroutine's fiber id at the moment current() is called,
 * so it stays a stable reference to that coroutine's context even across the
 * coroutine's own suspend/resume.
 */
class Context implements CoroutineContext
{
    protected function __construct(
        protected int $fiberId,
    ) {
    }

    /**
     * The context of the coroutine that calls this, or the process root context
     * when called outside any fiber.
     */
    public static function current(): CoroutineContext
    {
        return new self(fiberId: State::currentContextFiberId());
    }

    public function find(string $key): mixed
    {
        return State::contextFind(
            fiberId: $this->fiberId,
            key: $key,
        );
    }

    public function has(string $key): bool
    {
        return State::contextHas(
            fiberId: $this->fiberId,
            key: $key,
        );
    }

    public function set(string $key, mixed $value, bool $replace = false): void
    {
        State::contextSet(
            fiberId: $this->fiberId,
            key: $key,
            value: $value,
            replace: $replace,
        );
    }

    public function forget(string $key): void
    {
        State::contextForget(
            fiberId: $this->fiberId,
            key: $key,
        );
    }
}
