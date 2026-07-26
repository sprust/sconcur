<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

class HttpServerPreemptionTest extends BaseHttpServerTestCase
{
    public function testLightRequestCompletesWhileNonYieldingCpuRequestRuns(): void
    {
        // /cpu/{n} does NOT call Scheduler::switch() — without preemption it
        // freezes the whole single-threaded process (see the ...PreemptionOffTest
        // counterpart). Preemption is on by default, so the light request must
        // complete while the heavy loop is still crunching.
        $heavyHandle = $this->startBackgroundGet('/cpu/6000000');

        try {
            usleep(150_000);

            $lightStart = microtime(true);

            [$lightStatus, $lightBody] = $this->request('GET', '/msleep/50');

            $lightSeconds = microtime(true) - $lightStart;

            self::assertSame(200, $lightStatus);
            self::assertSame('slept', $lightBody);
            self::assertLessThan(
                0.8,
                $lightSeconds,
                sprintf('The light request took %.3fs; preemption did not interleave it.', $lightSeconds),
            );
        } finally {
            [$heavyStatus] = $this->finishBackgroundGet($heavyHandle);
        }

        self::assertSame(200, $heavyStatus);
    }

    /**
     * Starts a GET without waiting for it and returns the in-flight state.
     *
     * @return array{0: \CurlMultiHandle, 1: \CurlHandle}
     */
    protected function startBackgroundGet(string $path): array
    {
        $multi = curl_multi_init();
        $curl  = curl_init($this->baseUrl() . $path);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);

        curl_multi_add_handle($multi, $curl);

        // Drive the transfer until the request has actually been written to the
        // server (PRETRANSFER_TIME turns non-zero) — a single exec only starts
        // the connect, and an unsent request would make the test prove nothing.
        $running  = null;
        $deadline = microtime(true) + 2.0;

        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.01);

            $preTransferSeconds = (float) curl_getinfo($curl, CURLINFO_PRETRANSFER_TIME);
        } while ($preTransferSeconds <= 0 && $running > 0 && microtime(true) < $deadline);

        return [$multi, $curl];
    }

    /**
     * Waits out a request started by startBackgroundGet and returns [status, body].
     *
     * @param array{0: \CurlMultiHandle, 1: \CurlHandle} $handle
     *
     * @return array{int, string}
     */
    protected function finishBackgroundGet(array $handle): array
    {
        [$multi, $curl] = $handle;

        $running = null;

        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi);
        } while ($running > 0);

        $result = [
            (int) curl_getinfo($curl, CURLINFO_HTTP_CODE),
            (string) curl_multi_getcontent($curl),
        ];

        curl_multi_remove_handle($multi, $curl);
        curl_close($curl);
        curl_multi_close($multi);

        return $result;
    }
}
