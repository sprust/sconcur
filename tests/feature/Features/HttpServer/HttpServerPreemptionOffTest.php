<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

class HttpServerPreemptionOffTest extends HttpServerPreemptionTest
{
    public function testLightRequestCompletesWhileNonYieldingCpuRequestRuns(): void
    {
        // The control run: with preemption disabled the non-yielding CPU loop
        // freezes the process, so the light request waits it out.
        $heavyHandle = $this->startBackgroundGet('/cpu/6000000');

        try {
            usleep(150_000);

            $lightStart = microtime(true);

            [$lightStatus, $lightBody] = $this->request('GET', '/msleep/50');

            $lightSeconds = microtime(true) - $lightStart;

            self::assertSame(200, $lightStatus);
            self::assertSame('slept', $lightBody);
            self::assertGreaterThan(
                0.5,
                $lightSeconds,
                sprintf(
                    'The light request took only %.3fs with preemption off; the CPU loop should have blocked it.',
                    $lightSeconds,
                ),
            );
        } finally {
            [$heavyStatus] = $this->finishBackgroundGet($heavyHandle);
        }

        self::assertSame(200, $heavyStatus);
    }

    protected static function serverOptions(): array
    {
        return [
            'preemptionQuantumMs' => 0,
        ];
    }
}
