<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

class HttpServerCpuSwitchTest extends BaseHttpServerTestCase
{
    public function testLightRequestCompletesWhileYieldingCpuRequestRuns(): void
    {
        // One heavy CPU request that yields via Scheduler::switch() and one light
        // request, fired concurrently at a single-process server. The light one
        // must complete while the heavy one is still crunching — the yields hand
        // the loop over between quanta. (/native-msleep and /cpu prove the
        // opposite for non-yielding handlers: they freeze the whole process.)
        $timings = $this->concurrentGetTimings([
            'heavy' => '/cpu-switch/3000000',
            'light' => '/msleep/50',
        ]);

        [$heavyStatus, $heavySeconds] = $timings['heavy'];
        [$lightStatus, $lightSeconds] = $timings['light'];

        self::assertSame(200, $heavyStatus);
        self::assertSame(200, $lightStatus);

        self::assertGreaterThan(
            0.4,
            $heavySeconds,
            sprintf('The heavy request finished in %.3fs; it was not heavy enough to prove anything.', $heavySeconds),
        );
        self::assertLessThan(
            $heavySeconds * 0.6,
            $lightSeconds,
            sprintf(
                'The light request took %.3fs against the heavy %.3fs; the CPU loop did not yield.',
                $lightSeconds,
                $heavySeconds,
            ),
        );
    }

    /**
     * Fires the given GET paths concurrently and returns [status, totalSeconds]
     * per key.
     *
     * @param array<string, string> $paths
     *
     * @return array<string, array{int, float}>
     */
    private function concurrentGetTimings(array $paths): array
    {
        $multi   = curl_multi_init();
        $handles = [];

        foreach ($paths as $key => $path) {
            $curl = curl_init($this->baseUrl() . $path);

            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
            ]);

            curl_multi_add_handle($multi, $curl);

            $handles[$key] = $curl;
        }

        $running = null;

        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi);
        } while ($running > 0);

        $timings = [];

        foreach ($handles as $key => $curl) {
            $timings[$key] = [
                (int) curl_getinfo($curl, CURLINFO_HTTP_CODE),
                (float) curl_getinfo($curl, CURLINFO_TOTAL_TIME),
            ];

            curl_multi_remove_handle($multi, $curl);
            curl_close($curl);
        }

        curl_multi_close($multi);

        return $timings;
    }
}
