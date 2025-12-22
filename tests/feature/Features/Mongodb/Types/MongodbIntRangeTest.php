<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Types;

use SConcur\Tests\Feature\Features\Mongodb\BaseMongodbRangeTestCase;

/**
 * @extends BaseMongodbRangeTestCase<int>
 */
class MongodbIntRangeTest extends BaseMongodbRangeTestCase
{
    protected function getType(): string
    {
        return 'int';
    }

    protected function firstValue(): int
    {
        return 100;
    }

    /**
     * @param int $value
     */
    protected function nextValue(mixed $value): int
    {
        return $value + 1;
    }

    /**
     * @param int $value
     */
    protected function prevValue(mixed $value): int
    {
        return $value - 1;
    }
}
