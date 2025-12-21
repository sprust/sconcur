<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Types;

use DateMalformedStringException;
use SConcur\Features\Mongodb\Types\UTCDateTime;
use SConcur\Tests\Feature\Features\Mongodb\BaseMongodbRangeTestCase;

/**
 * @extends BaseMongodbRangeTestCase<UTCDateTime>
 */
class UTCDateTimeMongodbRangeTest extends BaseMongodbRangeTestCase
{
    protected function getType(): string
    {
        return 'dateTime';
    }

    protected function firstValue(): UTCDateTime
    {
        return new UTCDateTime();
    }

    /**
     * @param UTCDateTime $value
     *
     * @throws DateMalformedStringException
     */
    protected function nextValue(mixed $value): UTCDateTime
    {
        return new UTCDateTime(
            (clone $value->dateTime)->modify('+1 hour')
        );
    }

    /**
     * @param UTCDateTime $value
     *
     * @throws DateMalformedStringException
     */
    protected function prevValue(mixed $value): UTCDateTime
    {
        return new UTCDateTime(
            (clone $value->dateTime)->modify('-1 hour')
        );
    }
}
