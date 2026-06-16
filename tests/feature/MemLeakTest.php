<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature;

use SConcur\Features\Sleeper\Sleeper;
use SConcur\WaitGroup;

class MemLeakTest extends BaseTestCase
{
    private const int WARMUP_ITERATIONS   = 50;
    private const int MEASURED_ITERATIONS = 150;

    /**
     * Allowed memory growth between the post-warmup baseline and the end of the
     * measured run. A real leak grows unbounded, so a small constant budget is
     * enough to tell a leak apart from allocator/GC jitter.
     */
    private const int MAX_GROWTH_BYTES = 512 * 1024;

    private Sleeper $sleeper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sleeper = new Sleeper();
    }

    /**
     * Ported from tests/mem-leak/endless-add.php: a single long-living flow that
     * keeps receiving new tasks while iterating must not grow its memory usage.
     */
    public function testEndlessAddKeepsMemoryStable(): void
    {
        $callback = function (): void {
            $this->sleeper->msleep(milliseconds: 1);
        };

        $waitGroup = WaitGroup::create();

        $waitGroup->add(callback: $callback);

        $generator = $waitGroup->iterate();

        $iteration = 0;
        $baseline  = 0;

        foreach ($generator as $ignored) {
            ++$iteration;

            if ($iteration === self::WARMUP_ITERATIONS) {
                $baseline = memory_get_usage();
            }

            if ($iteration >= self::WARMUP_ITERATIONS + self::MEASURED_ITERATIONS) {
                break;
            }

            $waitGroup->add(callback: $callback);
        }

        $waitGroup->stop();

        $this->assertMemoryStable($baseline);
    }

    /**
     * Ported from tests/mem-leak/endless-break.php: repeatedly creating a flow,
     * taking a single result and abandoning the rest (cleaned up via stop())
     * must not accumulate memory across cycles.
     */
    public function testEndlessBreakKeepsMemoryStable(): void
    {
        $callback = function (): void {
            $this->sleeper->msleep(milliseconds: 1);
        };

        $baseline = 0;

        for ($iteration = 1; $iteration <= self::WARMUP_ITERATIONS + self::MEASURED_ITERATIONS; ++$iteration) {
            if ($iteration === self::WARMUP_ITERATIONS) {
                $baseline = memory_get_usage();
            }

            $waitGroup = WaitGroup::create();

            foreach (range(1, 10) as $ignored) {
                $waitGroup->add(callback: $callback);
            }

            foreach ($waitGroup->iterate() as $ignored) {
                break;
            }

            $waitGroup->stop();
        }

        $this->assertMemoryStable($baseline);
    }

    private function assertMemoryStable(int $baseline): void
    {
        $growth = memory_get_usage() - $baseline;

        self::assertLessThan(
            self::MAX_GROWTH_BYTES,
            $growth,
            sprintf(
                'Memory grew by %d bytes after %d iterations, expected less than %d.',
                $growth,
                self::MEASURED_ITERATIONS,
                self::MAX_GROWTH_BYTES,
            ),
        );
    }
}
