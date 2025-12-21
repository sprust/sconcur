<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Types;

use DateTime;
use DateTimeZone;
use JsonSerializable;

readonly class UTCDateTime implements JsonSerializable
{
    protected const string TYPE_PREFIX = '$udt-lgof:';

    public DateTime $dateTime;

    public function __construct(?DateTime $dateTime = null)
    {
        if ($dateTime === null) {
            $dateTime = new DateTime();
        }

        $utcDateTime = DateTime::createFromInterface($dateTime);
        $utcDateTime->setTimezone(new DateTimeZone('UTC'));
        $this->dateTime = $utcDateTime;
    }

    public function jsonSerialize(): string
    {
        return static::TYPE_PREFIX . $this->dateTime->format(DATE_RFC3339_EXTENDED);
    }
}
