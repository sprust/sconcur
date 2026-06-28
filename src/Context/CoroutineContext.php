<?php

declare(strict_types=1);

namespace SConcur\Context;

/**
 * Key-value context bound to the current coroutine (its fiber), isolated between
 * concurrent coroutines and inherited by child coroutines. It lets code carry
 * per-coroutine state (a request, a user, a locale) across suspend/resume without
 * any process-global swap: the value is read by the current fiber at access time.
 *
 * Reads are read-through up the parent chain (own map first, then the parent that
 * spawned this coroutine, up to the process root); writes are local to the current
 * coroutine, so a child may shadow a parent key without mutating the parent or its
 * siblings. The store is framework-neutral — it holds arbitrary mixed values.
 */
interface CoroutineContext
{
    /**
     * The value for $key, or null when it is absent (own map or any ancestor).
     */
    public function find(string $key): mixed;

    /**
     * Whether $key is visible (own map or inherited). Distinguishes an absent key
     * from a key whose stored value is null.
     */
    public function has(string $key): bool;

    /**
     * Writes $key into the current coroutine's own map (never the parent's). With
     * $replace = false an existing local key is kept (no-op); $replace = true
     * overwrites it.
     */
    public function set(string $key, mixed $value, bool $replace = false): void;

    /**
     * Removes $key from the current coroutine's own map only — an inherited key
     * from an ancestor is left untouched.
     */
    public function forget(string $key): void;
}
