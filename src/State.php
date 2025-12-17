<?php

declare(strict_types=1);

namespace SConcur;

use Fiber;
use SConcur\Flow\Flow;

class State
{
    protected static ?Flow $syncFlow = null;

    /**
     * @var array<int, Flow>
     */
    protected static array $fiberFlows = [];

    public static function registerFiberFlow(Fiber $fiber, Flow $flow): void
    {
        static::$fiberFlows[spl_object_id($fiber)] = $flow;
    }

    public static function unRegisterFiber(Fiber $fiber): void
    {
        unset(static::$fiberFlows[spl_object_id($fiber)]);
    }

    public static function getCurrentFlow(): Flow
    {
        $currentFiber = Fiber::getCurrent();

        if ($currentFiber === null) {
            return static::initSyncFlow();
        }

        return static::$fiberFlows[spl_object_id($currentFiber)]
            ?? static::initSyncFlow();
    }

    protected static function initSyncFlow(): Flow
    {
        return static::$syncFlow ??= new Flow(
            isAsync: false,
        );
    }
}
