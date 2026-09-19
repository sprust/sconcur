<?php

declare(strict_types=1);

namespace SConcur\Telemetry;

/**
 * How many workers of each pool the master's watchdog has killed since it started.
 *
 * Master-side, unlike everything else the panel shows: a worker cannot report its own
 * killing, and the snapshot it stopped sending is the very evidence it was killed on.
 * Monotonic per master process, so a panel or a Prometheus scrape reads it as a counter
 * and a rise in it is the signal — an absolute value says only how long the master has
 * been up.
 */
class WatchdogCounters
{
    /** @var array<string, int> group name => kills since the master started */
    protected array $killsByGroup = [];

    public function increment(string $group): void
    {
        $this->killsByGroup[$group] = ($this->killsByGroup[$group] ?? 0) + 1;
    }

    /** @return array<string, int> */
    public function all(): array
    {
        return $this->killsByGroup;
    }
}
