<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Telemetry;

use PHPUnit\Framework\TestCase;
use SConcur\Telemetry\MasterMetrics;

/**
 * Unit coverage of the master's own process-metrics sampler: the rolling CPU% math
 * (seed → diff → rate, with the non-positive-interval guard) is exercised with the
 * /proc readers stubbed so the assertions are deterministic; a second case checks the
 * real /proc path produces a sane snapshot on Linux.
 */
class MasterMetricsTest extends TestCase
{
    public function testRollingCpuPercentIsRateOverTheInterval(): void
    {
        $metrics = $this->stubbedMetrics();

        // First sample only seeds the baseline → 0%.
        $metrics->ticks = 100.0;
        $metrics->sample(10.0);

        self::assertSame(0.0, $metrics->snapshot(10_000)->cpuPercent);

        // +50 ticks over 1s wall: 50 ticks / 100 Hz = 0.5 CPU-seconds / 1s = 50%.
        $metrics->ticks = 150.0;
        $metrics->sample(11.0);

        self::assertSame(50.0, $metrics->snapshot(11_000)->cpuPercent);
    }

    public function testCpuPercentHeldWhenWallDoesNotAdvance(): void
    {
        $metrics = $this->stubbedMetrics();

        $metrics->ticks = 100.0;
        $metrics->sample(10.0);

        $metrics->ticks = 150.0;
        $metrics->sample(11.0);

        self::assertSame(50.0, $metrics->snapshot(11_000)->cpuPercent);

        // A repeat sample at the same wall time must not divide by zero — the previous
        // value is kept rather than producing INF/NaN.
        $metrics->ticks = 999.0;
        $metrics->sample(11.0);

        self::assertSame(50.0, $metrics->snapshot(11_000)->cpuPercent);
    }

    public function testSnapshotReportsPidUptimeAndStart(): void
    {
        $startedAtMs = 1_700_000_000_000;

        $metrics = new MasterMetrics($startedAtMs);

        $snapshot = $metrics->snapshot($startedAtMs + 5_000);

        self::assertSame((int) getmypid(), $snapshot->pid);
        self::assertSame($startedAtMs, $snapshot->startedAtMs);
        self::assertSame(5.0, $snapshot->uptimeSeconds);
        self::assertGreaterThanOrEqual(0, $snapshot->rssBytes);
    }

    public function testNonPositiveStartFallsBackToNow(): void
    {
        $before = (int) (microtime(true) * 1000);

        $metrics  = new MasterMetrics(0);
        $snapshot = $metrics->snapshot((int) (microtime(true) * 1000));

        // A standalone runtime (no master start handed in) still reports a sane,
        // non-negative uptime anchored at construction time.
        self::assertGreaterThanOrEqual($before, $snapshot->startedAtMs);
        self::assertGreaterThanOrEqual(0.0, $snapshot->uptimeSeconds);
    }

    /**
     * A MasterMetrics with the /proc readers stubbed so CPU% is a pure function of the
     * injected tick counter — no dependence on the host's actual CPU usage.
     */
    protected function stubbedMetrics(): StubbedMasterMetrics
    {
        return new StubbedMasterMetrics();
    }
}

/**
 * Test double: feeds a controlled CPU-tick counter into the sampler instead of reading
 * /proc, so the rolling-CPU math can be asserted exactly.
 */
class StubbedMasterMetrics extends MasterMetrics
{
    public float $ticks = 0.0;

    protected function readCpuTicks(): float
    {
        return $this->ticks;
    }

    protected function readRssBytes(): int
    {
        return 4_096;
    }
}
