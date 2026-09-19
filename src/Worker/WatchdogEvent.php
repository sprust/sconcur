<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * What the master hands its watchdog handler when it acts on a hung worker.
 *
 * A DTO rather than a list of arguments so that a later field does not break handlers
 * written against this one. The handler is where an application turns a kill into
 * whatever it watches with — an alert, an error tracker, a counter, an event of its own;
 * the master itself only writes the journal and counts.
 */
readonly class WatchdogEvent
{
    /**
     * @param string     $group             name of the pool the worker belongs to
     * @param int        $slot              slot index inside that pool
     * @param int        $pid               the worker's process id
     * @param null|float $ageSeconds        how long it had not marked itself alive when it
     *                                      was condemned; null on the later events, which
     *                                      report the same condemnation
     * @param int        $watchdogTimeoutMs the threshold it went past
     */
    public function __construct(
        public WatchdogEventEnum $event,
        public string $group,
        public int $slot,
        public int $pid,
        public ?float $ageSeconds,
        public int $watchdogTimeoutMs,
    ) {
    }
}
