<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\EngineState;

use Closure;

/**
 * A graph with a repeated object: serialize() writes `second` as a
 * back-reference whose index depends on how many objects the table holds.
 * The gate opens the window described on SerializeGate.
 */
class SerializeProbe
{
    protected static ?Closure $onUnserialize = null;

    public function __construct(
        protected SharedNode $first,
        protected SharedNode $second,
    ) {
    }

    /** Runs inside __unserialize(), where the engine holds its serialize lock. */
    public static function onUnserialize(?Closure $hook): void
    {
        static::$onUnserialize = $hook;
    }

    public function first(): SharedNode
    {
        return $this->first;
    }

    public function second(): SharedNode
    {
        return $this->second;
    }

    /**
     * @return array<string, object>
     */
    public function __serialize(): array
    {
        return [
            'first'  => $this->first,
            'second' => $this->second,
            'gate'   => new SerializeGate(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->first  = $data['first'];
        $this->second = $data['second'];

        if (static::$onUnserialize !== null) {
            (static::$onUnserialize)();
        }
    }
}
