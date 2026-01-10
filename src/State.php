<?php

declare(strict_types=1);

namespace SConcur;

use Fiber;
use SConcur\Connection\Extension;
use SConcur\Flow\CurrentFlow;
use WeakMap;

class State
{
    /**
     * @var WeakMap<Fiber, CurrentFlow>|null
     */
    // TODO: maybe use an array instead of WeakMap
    protected static ?WeakMap $fiberFlows = null;

    /**
     * @var array<string, array<string, Fiber>>
     */
    protected static array $fiberTasks = [];

    public static function registerFiberFlow(Fiber $fiber, CurrentFlow $flow): void
    {
        static::getFiberFlows()->offsetSet($fiber, $flow);
    }

    public static function unRegisterFiber(Fiber $fiber): void
    {
        static::getFiberFlows()->offsetUnset($fiber);
    }

    public static function getCurrentFlow(): CurrentFlow
    {
        $currentFiber = Fiber::getCurrent();

        if ($currentFiber === null) {
            $isAsync = false;
            $flowKey = uniqid();
        } else {
            $isAsync = true;

            $flows = static::getFiberFlows();

            if ($flows->offsetExists($currentFiber)) {
                $flowKey = $flows->offsetGet($currentFiber)->key;
            } else {
                $flowKey = uniqid();
            }
        }

        return new CurrentFlow(
            isAsync: $isAsync,
            key: $flowKey,
        );
    }

    public static function addFiberTask(string $flowKey, string $taskKey, Fiber $fiber): void
    {
        static::$fiberTasks[$flowKey][$taskKey] = $fiber;
    }

    public static function pullFiberByTask(string $flowKey, string $taskKey): ?Fiber
    {
        if (!array_key_exists($flowKey, static::$fiberTasks)) {
            return null;
        }

        $fiber = static::$fiberTasks[$flowKey][$taskKey] ?? null;

        unset(static::$fiberTasks[$flowKey][$taskKey]);

        return $fiber;
    }

    public static function deleteFlow(string $flowKey): void
    {
        unset(static::$fiberTasks[$flowKey]);

        Extension::get()->stopFlow($flowKey);
    }

    /**
     * @return  WeakMap<Fiber, CurrentFlow>
     */
    protected static function getFiberFlows(): WeakMap
    {
        return static::$fiberFlows ??= new WeakMap();
    }
}
