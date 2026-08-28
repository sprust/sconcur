<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

class HttpServerHandlerTimeoutTest extends BaseHttpServerTestCase
{
    public function testSlowHandlerIsAnsweredWith504WithoutWaitingForIt(): void
    {
        // The handler would sleep 10s; the 250ms deadline must answer 504 long
        // before that, freeing the connection.
        $start = microtime(true);

        [$status] = $this->request(
            method: 'GET',
            path: '/msleep/10000',
        );

        $elapsed = microtime(true) - $start;

        self::assertSame(504, $status);
        self::assertLessThan(2.0, $elapsed, sprintf('504 took %.3fs; the deadline did not fire promptly.', $elapsed));
    }

    public function testFastHandlerIsNotAffected(): void
    {
        [$status, $body] = $this->request(
            method: 'GET',
            path: '/msleep/50',
        );

        self::assertSame(200, $status);
        self::assertSame('slept', $body);
    }

    public function testStreamingHandlerIsCutOffByTheTotalDeadline(): void
    {
        // /slow-stream emits "p0\n".."p3\n" 100ms apart (~400ms total). The 250ms
        // total deadline cuts it mid-stream: the head (200) is already on the wire,
        // so the client gets a truncated body, not the full one.
        $full = "p0\np1\np2\np3\n";

        [$status, $body] = $this->captureStream($this->baseUrl() . '/slow-stream');

        self::assertSame(200, $status, 'the head is sent before the deadline');
        self::assertNotSame('', $body, 'some chunks should arrive before the cut');
        self::assertStringStartsWith($body, $full, 'the received body must be a prefix of the full one');
        self::assertNotSame($full, $body, 'the stream must be cut before completing');
    }

    /**
     * The 504 says the client was answered. It used to say nothing about the handler, which
     * went on working behind it — holding its connections and its locks — until it finished
     * a response nobody would read. The deadline now unwinds it too.
     *
     * The probe endpoint marks itself completed at the end of the handler and marks itself
     * finished from a finally block, so the two cases are distinguishable: "unwound" is the
     * finally without the completion.
     */
    public function testTheHandlerItselfIsUnwoundAndNotLeftRunning(): void
    {
        $name = 'cut-' . bin2hex(random_bytes(4));

        [$status] = $this->request(method: 'GET', path: "/timeout-probe/$name/3000");

        self::assertSame(504, $status);

        // Well past the handler's own sleep: if it had been left running, it would have
        // reached its end by now and said so.
        usleep(3_500_000);

        [, $result] = $this->request(method: 'GET', path: "/timeout-probe-result/$name");

        self::assertSame('unwound', $result, 'the handler was left running behind its 504');
    }

    /** A handler that fits in the deadline reaches its end as before. */
    public function testAHandlerWithinTheDeadlineCompletes(): void
    {
        $name = 'ok-' . bin2hex(random_bytes(4));

        [$status] = $this->request(method: 'GET', path: "/timeout-probe/$name/50");

        self::assertSame(200, $status);

        [, $result] = $this->request(method: 'GET', path: "/timeout-probe-result/$name");

        self::assertSame('completed', $result);
    }

    /**
     * The case that separates a working handler deadline from one that only reaches
     * handlers parked in an async call: a handler that never yields at all.
     *
     * Nothing but automatic preemption can take control away from a loop like this, and
     * the deadline is delivered from the preemption hook. The client must still get its
     * status — the write deadline is far away, so there is nothing stopping the 504 —
     * and the coroutine must be gone, not left spinning behind the answer.
     */
    public function testAHandlerInANeverYieldingLoopIsCutAndAnswered(): void
    {
        $name = 'cpu-' . bin2hex(random_bytes(4));

        $startedAt = microtime(true);

        [$status] = $this->request(method: 'GET', path: "/timeout-probe-cpu/$name");

        $elapsed = microtime(true) - $startedAt;

        self::assertSame(504, $status, 'the client must get a status, not a dropped connection');
        self::assertLessThan(5.0, $elapsed, sprintf('the 504 took %.3fs', $elapsed));

        // The loop would run for 30s if nothing stopped it.
        usleep(2_000_000);

        [, $result] = $this->request(method: 'GET', path: "/timeout-probe-result/$name");

        self::assertSame('unwound', $result, 'the loop was left spinning behind its 504');

        // And the worker that ran it is still serving.
        [$status, $body] = $this->request(method: 'GET', path: '/');

        self::assertSame(200, $status);
        self::assertSame('ok', $body);
    }

    /**
     * @return array<string, int>
     */
    protected static function serverOptions(): array
    {
        return ['handlerTimeoutMs' => 250];
    }

    /**
     * Performs a GET and returns [status, received body], keeping whatever bytes
     * arrived even if the connection is aborted mid-stream.
     *
     * @return array{int, string}
     */
    private function captureStream(string $url): array
    {
        $received = '';

        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_TIMEOUT       => 5,
            CURLOPT_WRITEFUNCTION => static function ($_curl, string $data) use (&$received): int {
                $received .= $data;

                return strlen($data);
            },
        ]);

        curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        return [$status, $received];
    }
}
