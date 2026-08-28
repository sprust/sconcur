<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

use Closure;
use SConcur\Exceptions\Amqp\InvalidRetryException;
use SConcur\Features\Sleeper\Sleeper;
use Throwable;

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
     * Runs $call, and runs it again after each failure this schedule is meant to absorb —
     * waiting out the delay for the attempt just made — until it succeeds or the attempts
     * run out. The failure of the last attempt is the one raised.
     *
     * What counts as a failure worth retrying is the caller's business: only the exceptions
     * $retryable names are caught, everything else leaves immediately. That is what keeps a
     * dead channel from spending the whole schedule to arrive at the exception the first
     * attempt already had.
     *
     * @param int                           $retries   how many further attempts a failure may have
     * @param list<class-string<Throwable>> $retryable the failures another attempt is worth
     * @param Closure(): void               $call      one attempt
     */
    public function retrying(int $retries, array $retryable, Closure $call): void
    {
        static::assertRetries($retries);

        $attempt = 0;

        while (true) {
            try {
                $call();

                return;
            } catch (Throwable $failure) {
                if (!static::isRetryable($failure, $retryable) || $attempt >= $retries) {
                    throw $failure;
                }

                ++$attempt;
            }

            $delaySeconds = $this->delaySecondsFor($attempt);

            if ($delaySeconds > 0) {
                Sleeper::usleep(microseconds: (int) round($delaySeconds * 1_000_000));
            }
        }
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

    /**
     * Screens an attempt count. Public so a caller with setup of its own to do — entering
     * confirm mode is a round trip to the broker — can refuse a bad argument before paying
     * for it, and still fail with one message rather than two.
     */
    public static function assertRetries(int $retries): void
    {
        if ($retries < 0) {
            throw new InvalidRetryException(
                message: "A call cannot be retried a negative number of times, got $retries.",
            );
        }
    }

    /**
     * @param list<class-string<Throwable>> $retryable
     */
    protected static function isRetryable(Throwable $failure, array $retryable): bool
    {
        foreach ($retryable as $class) {
            if ($failure instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
