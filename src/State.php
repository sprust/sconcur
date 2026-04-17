<?php

declare(strict_types=1);

namespace SConcur;

use Fiber;
use SConcur\Connection\Extension;
use SConcur\Flow\CurrentFlow;

class State
{
    /**
     * array<$fiberId, $flow>
     *
     * @var array<int, CurrentFlow>
     */
    protected static array $fiberFlows = [];

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
