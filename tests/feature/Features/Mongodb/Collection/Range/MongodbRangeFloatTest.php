<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Range;

use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbRangeTestCase;

/**
 * @extends BaseMongodbRangeTestCase<float>
 */
class MongodbRangeFloatTest extends BaseMongodbRangeTestCase
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
