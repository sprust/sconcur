<?php

declare(strict_types=1);

namespace SConcur\Telemetry;

use SConcur\Telemetry\Dto\MasterInfo;
use SConcur\Telemetry\Dto\Memory;

/**
 * Samples the worker master's own process metrics (RSS from /proc/self/status, Pss and
 * SwapPss from /proc/self/smaps_rollup, CPU from /proc/self/stat) — the PHP-side mirror
 * of the sampler a worker runs for itself (ext/src/stats/metrics.rs).
 * Both are refreshed by sample() on a fixed cadence (the runtime calls it every five
 * seconds, the cadence a worker reads its own at), so CPU is a real interval rate rather
 * than a since-boot average; snapshot() repeats the last reading in between. Linux-only:
 * off /proc the readings degrade to 0.
 */
class MasterMetrics
{
    // USER_HZ — the unit of the utime/stime fields in /proc/self/stat. Fixed at 100 on
    // virtually every Linux build (matches the extension side's assumption).
    protected const float CLOCK_TICKS_PER_SECOND = 100.0;

    protected int $pid;

    protected int $startedAtMs;

    protected bool $seeded = false;

    protected float $previousCpuTicks = 0.0;

    protected float $previousWallSeconds = 0.0;

    protected float $cpuPercent = 0.0;

    protected ?Memory $memory = null;

    /**
     * @param int $startedAtMs the master's serve start (epoch ms); <= 0 falls back to
     *                         now, so a standalone runtime still reports a sane uptime.
     */
    public function __construct(int $startedAtMs = 0)
    {
        $this->pid         = (int) getmypid();
        $this->startedAtMs = $startedAtMs > 0 ? $startedAtMs : (int) (microtime(true) * 1000);
    }

    /**
     * Reads memory afresh and refreshes the rolling CPU percentage by diffing consumed
     * CPU ticks against wall time since the previous call. The first call only seeds the
     * CPU baseline. Driven by the runtime on a fixed cadence so the rate is over a known
     * interval.
     */
    public function sample(float $nowSeconds): void
    {
        $this->memory = $this->readMemory();

        $ticks = $this->readCpuTicks();

        if (!$this->seeded) {
            $this->seeded              = true;
            $this->previousCpuTicks    = $ticks;
            $this->previousWallSeconds = $nowSeconds;

            return;
        }

        $deltaTicks       = $ticks - $this->previousCpuTicks;
        $deltaWallSeconds = $nowSeconds - $this->previousWallSeconds;

        $this->previousCpuTicks    = $ticks;
        $this->previousWallSeconds = $nowSeconds;

        if ($deltaWallSeconds <= 0) {
            return;
        }

        $this->cpuPercent = ($deltaTicks / self::CLOCK_TICKS_PER_SECOND) / $deltaWallSeconds * 100.0;
    }

    /**
     * Builds the current master metrics: the last sampled memory (read now if nothing was
     * sampled yet) and CPU percent, and the uptime derived from $nowMs.
     */
    public function snapshot(int $nowMs): MasterInfo
    {
        $uptimeSeconds = max(0.0, ($nowMs - $this->startedAtMs) / 1000);

        return new MasterInfo(
            pid: $this->pid,
            startedAtMs: $this->startedAtMs,
            uptimeSeconds: $uptimeSeconds,
            memory: $this->memory ?? $this->readMemory(),
            cpuPercent: $this->cpuPercent,
        );
    }

    /**
     * Memory of the master process: the resident set size from /proc/self/status
     * (VmRSS), its proportional share and the same share of swap from
     * /proc/self/smaps_rollup (Pss, SwapPss). The master holds the pages a preloaded
     * pool inherited, so its share is part of what the pool takes. A value that cannot
     * be read is 0 (non-Linux).
     */
    protected function readMemory(): Memory
    {
        $rollup = (string) @file_get_contents('/proc/self/smaps_rollup');

        return new Memory(
            rssBytes: $this->readKibFieldBytes(
                contents: (string) @file_get_contents('/proc/self/status'),
                field: 'VmRSS',
            ),
            pssBytes: $this->readKibFieldBytes(
                contents: $rollup,
                field: 'Pss',
            ),
            swapPssBytes: $this->readKibFieldBytes(
                contents: $rollup,
                field: 'SwapPss',
            ),
        );
    }

    /**
     * The value of the "<field>: <n> kB" line of a /proc file, in bytes, or 0 when the
     * line is missing or does not parse. The colon is matched too, so Pss does not take
     * the Pss_Anon line of smaps_rollup.
     */
    protected function readKibFieldBytes(string $contents, string $field): int
    {
        foreach (explode("\n", $contents) as $line) {
            if (!str_starts_with($line, $field . ':')) {
                continue;
            }

            $fields = preg_split('/\s+/', trim($line)) ?: [];

            if (isset($fields[1]) && ctype_digit($fields[1])) {
                return (int) $fields[1] * 1024;
            }

            return 0;
        }

        return 0;
    }

    /**
     * utime + stime (in clock ticks) of the master process from /proc/self/stat. The
     * comm field (2nd) may contain spaces and parentheses, so fields are parsed after
     * the last ')': utime is field 14, stime field 15. Returns 0 on any parse failure.
     */
    protected function readCpuTicks(): float
    {
        $contents = @file_get_contents('/proc/self/stat');

        if ($contents === false) {
            return 0.0;
        }

        $lastParen = strrpos($contents, ')');

        if ($lastParen === false) {
            return 0.0;
        }

        $fields = preg_split('/\s+/', trim(substr($contents, $lastParen + 1))) ?: [];

        // After the comm field, index 0 = state (field 3); utime is field 14 (index
        // 11), stime field 15 (index 12).
        if (!isset($fields[11], $fields[12]) || !is_numeric($fields[11]) || !is_numeric($fields[12])) {
            return 0.0;
        }

        return (float) $fields[11] + (float) $fields[12];
    }
}
