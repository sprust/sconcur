<?php

declare(strict_types=1);

namespace SConcur;

use Fiber;
use SConcur\Flow\Flow;
use WeakMap;

class State
{
    protected static ?Flow $syncFlow = null;

    /**
     * @var WeakMap<Fiber, Flow>|null
     */
    protected static ?WeakMap $fiberFlows = null;

    public static function registerFiberFlow(Fiber $fiber, Flow $flow): void
    {
        static::getFiberFlows()->offsetSet($fiber, $flow);
    }

    public static function unRegisterFiber(Fiber $fiber): void
    {
        static::getFiberFlows()->offsetUnset($fiber);
    }

    public static function getCurrentFlow(): Flow
    {
        $currentFiber = Fiber::getCurrent();

        if ($currentFiber === null) {
            return static::initSyncFlow();
        }

        $flows = static::getFiberFlows();

        return $flows->offsetExists($currentFiber)
            ? $flows->offsetGet($currentFiber)
            : static::initSyncFlow();
    }

    protected static function initSyncFlow(): Flow
    {
        return static::$syncFlow ??= new Flow(
            isAsync: false,
        );
    }

    /**
     * @return  WeakMap<Fiber, Flow>
     */
    protected static function getFiberFlows(): WeakMap
    {
        return static::$fiberFlows ??= new WeakMap();
    }
}
