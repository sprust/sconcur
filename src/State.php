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
     * array<$flowKey, array<$taskKey, $fiberId>>
     *
     * @var array<string, array<string, int>>
     */
    protected static array $fiberTasks = [];

    public static function registerFiberFlow(int $fiberId, CurrentFlow $flow): void
    {
        static::$fiberFlows[$fiberId] = $flow;
    }

    public static function unRegisterFiber(int $fiberId): void
    {
        unset(static::$fiberFlows[$fiberId]);
    }

    public static function getCurrentFlow(): CurrentFlow
    {
        $currentFiber = Fiber::getCurrent();

        if ($currentFiber === null) {
            $isAsync = false;
            $flowKey = uniqid();
        } else {
            $fiberId = spl_object_id($currentFiber);

            unset($currentFiber);

            if (array_key_exists($fiberId, self::$fiberFlows)) {
                $isAsync = true;
                $flowKey = self::$fiberFlows[$fiberId]->key;
            } else {
                $isAsync = false;
                $flowKey = uniqid();
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

        $flowFiberIds = array_keys(static::$fiberFlows);

        foreach ($flowFiberIds as $fiberId) {
            $registeredFlow = static::$fiberFlows[$fiberId];

            if ($registeredFlow->key !== $flowKey) {
                continue;
            }

            static::unRegisterFiber($fiberId);
        }

        Extension::get()->stopFlow($flowKey);
    }
}
