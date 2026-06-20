<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

class HttpServerRequestStreamTest extends BaseHttpServerTestCase
{
    public function testLargeBinaryBodyIsStreamedAndFullyReceived(): void
    {
        // 150 KiB (> the 64 KiB inline chunk) of binary data: several streamed
        // chunks, under the 200 KiB limit.
        $body = random_bytes(150000);

        [$status, $hash] = $this->request(
            method: 'POST',
            path: '/upload',
            body: $body,
        );

        self::assertSame(200, $status);
        self::assertSame(hash('sha256', $body), $hash, 'every streamed byte must arrive in order');
    }

    public function testBodyOverTheLimitWhileStreamingReturns413(): void
    {
        // 250 KiB > the 200 KiB limit: the limit is hit mid-stream, mapped to 413.
        $body = random_bytes(250000);

        [$status] = $this->request(
            method: 'POST',
            path: '/upload',
            body: $body,
        );

        self::assertSame(413, $status);
    }

    public function testClientAbortDuringBodyStreamDoesNotBreakServer(): void
    {
        $url  = (array) parse_url($this->baseUrl());
        $host = (string) ($url['host'] ?? '127.0.0.1');
        $port = (int) ($url['port'] ?? 0);

        $socket = fsockopen($host, $port, $errno, $errstr, 5);

        self::assertIsResource($socket);

        // Announce a large body but send only part of it (> the 64 KiB inline chunk,
        // so the handler is in the streamed-read path), then abort — the handler is
        // mid body->read() awaiting the rest when the connection drops.
        $partial = str_repeat('x', 70000);

        $request = "POST /upload HTTP/1.1\r\n"
            . "Host: $host\r\n"
            . "Content-Length: 1000000\r\n"
            . "Connection: close\r\n\r\n"
            . $partial;

        fwrite($socket, $request);

        // Let the server consume what was sent and block awaiting the rest.
        usleep(150_000);

        // Abort mid-body.
        fclose($socket);

        // The server must survive the aborted streamed read and keep serving.
        [$status, $body] = $this->request(
            method: 'GET',
            path: '/',
        );

        self::assertSame(200, $status);
        self::assertSame('ok', $body);
    }

    public function testSmallBodyReadViaContents(): void
    {
        [$status, $echoed] = $this->request(
            method: 'POST',
            path: '/echo',
            body: 'tiny body',
        );

        self::assertSame(200, $status);
        self::assertSame('tiny body', $echoed);
    }

    /**
     * @return array<string, int>
     */
    protected static function serverOptions(): array
    {
        // A modest limit so the over-limit case is reachable without huge uploads;
        // bodies above the fixed 64 KiB inline chunk exercise the streaming path.
        return ['maxRequestBody' => 200000];
    }
}
