<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\EngineState;

/**
 * An object referenced twice from one graph, so serialize() writes it once and
 * then as a back-reference (r:N) into the object table it is building.
 */
class SharedNode
{
}
