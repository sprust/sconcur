<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Types;

use SConcur\Tests\Feature\Features\Mongodb\BaseMongodbRangeTestCase;

/**
 * @extends BaseMongodbRangeTestCase<float>
 */
class FloatMongodbRangeTest extends BaseMongodbRangeTestCase
{
    protected function getType(): string
    {
        return 'float';
    }

    protected function firstValue(): float
    {
        return 100.013;
    }

    /**
     * @param float $value
     */
    protected function nextValue(mixed $value): float
    {
        return $value + 1.33;
    }

    /**
     * @param float $value
     */
    protected function prevValue(mixed $value): float
    {
        return $value - 1.33;
    }
}
