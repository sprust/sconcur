<?php

declare(strict_types=1);

namespace SConcur\Entities;

use SConcur\Exceptions\InvalidValueException;
use SConcur\Exceptions\TimeoutException;

class Context
{
    public float $timeout;
    public float $startTime;

    protected function __construct(int $timeoutSeconds)
    {
        if ($timeoutSeconds < 1) {
            throw new InvalidValueException(
                'Timeout seconds must be greater than 0'
            );
        }

        $this->timeout   = (float) $timeoutSeconds;
        $this->startTime = microtime(true);
    }

    public static function create(int $timeoutSeconds): static
    {
        return new Context(
            timeoutSeconds: $timeoutSeconds
        );
    }

    public function check(): void
    {
        if ((microtime(true) - $this->startTime) > $this->timeout) {
            throw new TimeoutException();
        }
    }

    public function getRemainMs(): int
    {
        return (int) (($this->timeout - (microtime(true) - $this->startTime)) * 1000);
    }
}
