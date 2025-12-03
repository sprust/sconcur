<?php

declare(strict_types=1);

namespace SConcur\Entities;

use SConcur\Exceptions\InvalidValueException;
use SConcur\Exceptions\TimeoutException;

class Context
{
    public float $timeout;
    public float $startTime;

    /**
     * @throws InvalidValueException
     */
    public function __construct(int $timeoutSeconds)
    {
        if ($timeoutSeconds < 1) {
            throw new InvalidValueException(
                'Timeout seconds must be greater than 0'
            );
        }

        $this->timeout   = (float) $timeoutSeconds;
        $this->startTime = microtime(true);
    }

    /**
     * @throws TimeoutException
     */
    public function check(): void
    {
        if ($this->isTimeout()) {
            throw new TimeoutException();
        }
    }

    public function getRemainMs(): int
    {
        $elapsedTime      = microtime(true) - $this->startTime;
        $remainingSeconds = max(0, $this->timeout - $elapsedTime);

        return (int) ($remainingSeconds * 1000);
    }

    private function isTimeout(): bool
    {
        return (microtime(true) - $this->startTime) > $this->timeout;
    }
}
