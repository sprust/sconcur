<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\EngineState;

use Closure;

/**
 * Runs a hook from its destructor. SerializeProbe::__serialize() returns a fresh
 * gate inside its array: the engine destroys that array right after writing it,
 * still inside serialize() but outside the lock it holds around __serialize()
 * itself. The hook therefore runs in the one window where user code executes
 * with the serialize object table registered and unlocked — a suspend or a
 * preemption there lets a neighbour's serialize() join that table.
 */
class SerializeGate
{
    public static ?Closure $onDestruct = null;

    public function __destruct()
    {
        if (static::$onDestruct !== null) {
            (static::$onDestruct)();
        }
    }
}
