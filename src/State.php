<?php

declare(strict_types=1);

namespace SConcur;

use Fiber;
use SConcur\Connection\Extension;
use SConcur\Flow\CurrentFlow;

class State
{
    /**
     * Stand-in fiber id for the process root context — the ultimate ancestor of
     * every coroutine's context and the context used outside any fiber.
     * spl_object_id() never returns 0, so 0 can never clash with a real fiber and
     * the root is never removed by unRegisterFiber().
     */
    public const int ROOT_CONTEXT_ID = 0;

    /**
     * array<$fiberId, $flow>
     *
     * @var array<int, CurrentFlow>
     */
    protected static array $fiberFlows = [];

    /**
     * Per-coroutine context: own key-value map by fiber id (0 = process root).
     * Created lazily on the first write; reads walk the parent chain below.
     *
     * @var array<int, array<string, mixed>>
     */
    protected static array $fiberContext = [];

    /**
     * Context inheritance links: child fiber id => parent fiber id. Set when a
     * coroutine is spawned (Scheduler::spawn / WaitGroup::add); a coroutine
     * created outside any fiber points at ROOT_CONTEXT_ID. Drives the read-through
     * lookup in contextFind()/contextHas().
     *
     * @var array<int, int>
     */
    protected static array $fiberContextParent = [];

    /**
     * array<$flowKey, array<$fiberId, true>>
     *
     * @var array<string, array<int, true>>
     */
    protected static array $flowFibers = [];

    /**
     * array<$flowKey, array<$taskKey, $fiberId>>
     *
     * @var array<string, array<string, int>>
     */
    protected static array $fiberTasks = [];

    /**
     * Flows created for synchronous (non-fiber) iterable operations. Such a flow
     * lives until its cursor is exhausted or the iterator is released.
     *
     * array<$taskKey, $flowKey>
     *
     * @var array<string, string>
     */
    protected static array $syncTaskFlows = [];

    public static function registerFiberFlow(int $fiberId, CurrentFlow $flow): void
    {
        static::$fiberFlows[$fiberId]             = $flow;
        static::$flowFibers[$flow->key][$fiberId] = true;
    }

    public static function unRegisterFiber(int $fiberId): void
    {
        $flow = static::$fiberFlows[$fiberId] ?? null;

        if ($flow !== null) {
            unset(static::$flowFibers[$flow->key][$fiberId]);

            if ((static::$flowFibers[$flow->key] ?? []) === []) {
                unset(static::$flowFibers[$flow->key]);
            }
        }

        unset(static::$fiberFlows[$fiberId]);

        // Release this coroutine's context with the coroutine itself (the root,
        // id 0, is never passed here, so it survives the process lifetime).
        unset(static::$fiberContext[$fiberId], static::$fiberContextParent[$fiberId]);
    }

    /**
     * Records the context inheritance link for a freshly created coroutine: its
     * parent is the coroutine that spawned it (ROOT_CONTEXT_ID when spawned
     * outside any fiber). Called before the new fiber starts so its first run
     * already sees the inherited keys.
     */
    public static function registerCoroutineContext(int $fiberId, int $parentFiberId): void
    {
        static::$fiberContextParent[$fiberId] = $parentFiberId;
    }

    /**
     * Fiber id identifying the current coroutine's context — spl_object_id of the
     * running fiber, or ROOT_CONTEXT_ID outside any fiber.
     */
    public static function currentContextFiberId(): int
    {
        $currentFiber = Fiber::getCurrent();

        return $currentFiber === null
            ? self::ROOT_CONTEXT_ID
            : spl_object_id($currentFiber);
    }

    /**
     * Read-through lookup: the own map first, then up the parent chain to the
     * root. Returns null when the key is absent everywhere.
     */
    public static function contextFind(int $fiberId, string $key): mixed
    {
        $current = $fiberId;

        while (true) {
            if (array_key_exists($key, static::$fiberContext[$current] ?? [])) {
                return static::$fiberContext[$current][$key];
            }

            $parent = static::$fiberContextParent[$current] ?? null;

            if ($parent === null) {
                return null;
            }

            $current = $parent;
        }
    }

    /**
     * Whether the key is visible from this coroutine (own map or any ancestor) —
     * by key presence, so a stored null still counts as present.
     */
    public static function contextHas(int $fiberId, string $key): bool
    {
        $current = $fiberId;

        while (true) {
            if (array_key_exists($key, static::$fiberContext[$current] ?? [])) {
                return true;
            }

            $parent = static::$fiberContextParent[$current] ?? null;

            if ($parent === null) {
                return false;
            }

            $current = $parent;
        }
    }

    /**
     * Writes the key into this coroutine's own map. With $replace = false an
     * existing local key is kept; an inherited (ancestor) key is shadowed, not
     * counted as "already set" locally.
     */
    public static function contextSet(int $fiberId, string $key, mixed $value, bool $replace): void
    {
        if (!$replace && array_key_exists($key, static::$fiberContext[$fiberId] ?? [])) {
            return;
        }

        static::$fiberContext[$fiberId][$key] = $value;
    }

    /**
     * Removes the key from this coroutine's own map only; inherited keys are left
     * intact.
     */
    public static function contextForget(int $fiberId, string $key): void
    {
        unset(static::$fiberContext[$fiberId][$key]);
    }

    public static function getCurrentFlow(): CurrentFlow
    {
        $currentFiber = Fiber::getCurrent();

        if ($currentFiber === null) {
            $isAsync = false;
            $flowKey = static::newFlowKey();
        } else {
            $fiberId = spl_object_id($currentFiber);

            unset($currentFiber);

            if (array_key_exists($fiberId, self::$fiberFlows)) {
                $isAsync = true;
                $flowKey = self::$fiberFlows[$fiberId]->key;
            } else {
                $isAsync = false;
                $flowKey = static::newFlowKey();
            }
        }

        return new CurrentFlow(
            isAsync: $isAsync,
            key: $flowKey,
        );
    }

    public static function addFiberTask(string $flowKey, string $taskKey, int $fiberId): void
    {
        static::$fiberTasks[$flowKey][$taskKey] = $fiberId;
    }

    public static function pullFiberByTask(string $flowKey, string $taskKey): ?int
    {
        if (!array_key_exists($flowKey, static::$fiberTasks)) {
            return null;
        }

        $fiberId = static::$fiberTasks[$flowKey][$taskKey] ?? null;

        unset(static::$fiberTasks[$flowKey][$taskKey]);

        return $fiberId;
    }

    public static function registerSyncTaskFlow(string $taskKey, string $flowKey): void
    {
        static::$syncTaskFlows[$taskKey] = $flowKey;
    }

    public static function releaseSyncTaskFlow(string $taskKey): void
    {
        $flowKey = static::$syncTaskFlows[$taskKey] ?? null;

        if ($flowKey === null) {
            return;
        }

        unset(static::$syncTaskFlows[$taskKey]);

        Extension::get()->stopFlow($flowKey);
    }

    public static function deleteFlow(string $flowKey): void
    {
        unset(static::$fiberTasks[$flowKey]);

        if (isset(static::$flowFibers[$flowKey])) {
            foreach (static::$flowFibers[$flowKey] as $fiberId => $_) {
                static::unRegisterFiber($fiberId);
            }
        } else {
            foreach (static::$fiberFlows as $fiberId => $registeredFlow) {
                if ($registeredFlow->key !== $flowKey) {
                    continue;
                }

                static::unRegisterFiber($fiberId);
            }
        }

        Extension::get()->stopFlow($flowKey);
    }

    protected static function newFlowKey(): string
    {
        return uniqid('', true);
    }
}
