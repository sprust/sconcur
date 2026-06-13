<?php

declare(strict_types=1);

namespace SConcur\Features\HttpServer;

use Closure;
use SConcur\Connection\Extension;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\HttpServer\Dto\Request;
use SConcur\Features\HttpServer\Dto\Response;
use SConcur\Features\HttpServer\Payloads\RespondPayload;
use SConcur\Features\HttpServer\Payloads\ServePayload;
use SConcur\Scheduler\Scheduler;
use Throwable;

/**
 * HTTP server: the network lives in the Go extension, each accepted request is
 * streamed back as a result and handled in its own coroutine (spawn-on-request).
 * See .ai/plans/http-server.md.
 */
readonly class HttpServer
{
    /**
     * @param int $maxRequestBody request body read limit, in bytes
     *
     * Defaults mirror the Go server defaults.
     */
    public function __construct(
        private string $address,
        private int $readHeaderTimeoutMs = 10_000,
        private int $readTimeoutMs = 30_000,
        private int $writeTimeoutMs = 30_000,
        private int $idleTimeoutMs = 60_000,
        private int $shutdownTimeoutMs = 5_000,
        private int $maxRequestBody = 10_485_760,
    ) {
    }

    /**
     * Starts the listener and serves forever (until the flow is stopped or a
     * shutdown signal arrives). The handler receives a Request and must return a
     * Response; it runs in its own coroutine, so it may issue concurrent async
     * calls (Mongodb, Sleeper, ...).
     *
     * @param Closure(Request): Response $handler
     */
    public function serve(Closure $handler): void
    {
        $flowKey = uniqid('http_', more_entropy: true);

        $runningTask = Extension::get()->push(
            flowKey: $flowKey,
            payload: new ServePayload(
                address: $this->address,
                readHeaderTimeoutMs: $this->readHeaderTimeoutMs,
                readTimeoutMs: $this->readTimeoutMs,
                writeTimeoutMs: $this->writeTimeoutMs,
                idleTimeoutMs: $this->idleTimeoutMs,
                shutdownTimeoutMs: $this->shutdownTimeoutMs,
                maxRequestBody: $this->maxRequestBody,
            ),
        );

        $stopRequested = false;

        $this->installSignalHandlers($stopRequested);

        Scheduler::get()->serve(
            serverFlowKey: $flowKey,
            serverTaskKey: $runningTask->key,
            onRequest: static function (string $payload) use ($handler): void {
                self::handle($handler, $payload);
            },
            shouldStop: static function () use (&$stopRequested): bool {
                return $stopRequested;
            },
        );
    }

    /**
     * Installs SIGTERM/SIGINT handlers that flip $stopRequested so the serve loop
     * shuts down gracefully. Requires ext-pcntl; without it the server runs until
     * the process is killed.
     */
    private function installSignalHandlers(bool &$stopRequested): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        $handler = static function () use (&$stopRequested): void {
            $stopRequested = true;
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    /**
     * Runs inside a spawned coroutine: decode the request, call the user handler
     * (turning any error into a 500), then send the response back to Go.
     *
     * @param Closure(Request): Response $handler
     */
    private static function handle(Closure $handler, string $payload): void
    {
        $request = Request::fromPayload($payload);

        try {
            $response = $handler($request);
        } catch (Throwable) {
            $response = new Response(body: 'Internal Server Error', status: 500);
        }

        FeatureExecutor::exec(
            payload: new RespondPayload(
                requestId: $request->requestId,
                status: $response->status,
                headers: $response->headers,
                body: $response->body,
            ),
        );
    }
}
