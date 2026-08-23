<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

use SConcur\Exceptions\Amqp\InvalidRetryException;

/**
 * How long a publish waits before it tries again, by attempt number.
 *
 * The list is read positionally — the first entry is the wait after the first failure, the
 * second after the second — and it does not bound how many attempts there are: that is what
 * `retries` is for. An attempt past the end of the list takes the last entry, so a schedule
 * of `[1, 3, 4]` backs off to four seconds and stays there however many retries follow.
 *
 * An empty schedule means no wait at all, which is what a caller who only wants another
 * immediate attempt is asking for.
 */
readonly class RetrySchedule
{
    /** @var list<float> */
    public array $delaysSeconds;

    /**
     * @param list<float> $delaysSeconds the wait after each failure, in seconds
     */
    public function __construct(array $delaysSeconds = [])
    {
        foreach ($delaysSeconds as $position => $delaySeconds) {
            if ($delaySeconds < 0) {
                throw new InvalidRetryException(
                    message: "A retry delay cannot be negative, got $delaySeconds s at position $position.",
                );
            }
        }

        $this->delaysSeconds = $delaysSeconds;
    }

    /**
     * The wait before attempt number $attempt, counted from one.
     */
    public function delaySecondsFor(int $attempt): float
    {
        if ($this->delaysSeconds === []) {
            return 0.0;
        }

        // Clamped at both ends: below the first entry for an attempt counted from zero by
        // mistake, at the last one for every attempt past the end of the schedule.
        $position = max(0, min($attempt - 1, count($this->delaysSeconds) - 1));

        return $this->delaysSeconds[$position];
    }
}
