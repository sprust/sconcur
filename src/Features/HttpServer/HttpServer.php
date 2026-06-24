<?php

declare(strict_types=1);

namespace SConcur\Features\HttpServer;

use Closure;
use SConcur\Connection\Extension;
use SConcur\Exceptions\HttpServer\InvalidHandlerResponseException;
use SConcur\Exceptions\HttpServer\RequestBodyTooLargeException;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\HttpServer\Dto\Request;
use SConcur\Features\HttpServer\Dto\Response;
use SConcur\Features\HttpServer\Dto\ResponseStream;
use SConcur\Features\HttpServer\Dto\StreamedResponse;
use SConcur\Features\HttpServer\Payloads\RespondPayload;
use SConcur\Features\HttpServer\Payloads\ServePayload;
use SConcur\Features\Server\ServerRuntimeSupportTrait;
use SConcur\Scheduler\Scheduler;
use Throwable;

/**
 * HTTP server: the network lives in the Go extension, each accepted request is
 * streamed back as a result and handled in its own coroutine (spawn-on-request).
 * See docs/http-server.ru.md.
 */
readonly class HttpServer
{
    use ServerRuntimeSupportTrait;

    /**
     * @param int                                         $maxRequestBody   request body read limit, in bytes
     * @param int                                         $maxConcurrency   max requests handled at once (0 = unlimited).
     *                                                                      Bounds goroutines, buffered request bodies (memory) and request
     *                                                                      coroutines; excess connections wait for a free slot. Set it to
     *                                                                      cap resource use under load.
     * @param int                                         $handlerTimeoutMs max total time to handle a request, including a streamed
     *                                                                      response, before it is cut off and the slot freed (default 60s;
     *                                                                      0 disables). If nothing was written yet the client gets a 504;
     *                                                                      mid-stream the response is aborted (status is already on the wire).
     *                                                                      Note: a CPU-bound handler still blocks the single-threaded loop;
     *                                                                      this guards handlers waiting on async work.
     * @param int                                         $maxRequests      stop the server after it has handled this many requests
     *                                                                      (0 = unlimited). Meant against handler memory leaks: once the
     *                                                                      count is reached the server shuts down gracefully (closes the
     *                                                                      listener first, drains in-flight, exits cleanly) so a master /
     *                                                                      supervisor can respawn a fresh process. Reuses the graceful-
     *                                                                      shutdown path, so no already-accepted request is bounced.
     * @param bool                                        $reusePort        set SO_REUSEPORT so several processes can bind this same
     *                                                                      address; the kernel load-balances connections across them
     *                                                                      (run one process per core). Linux only; each process must set it.
     * @param null|Closure(Throwable, Request): ?Response $onError          observes a handler
     *                                                                      failure (exception or a non-Response return). It may return a Response
     *                                                                      to send instead of the default 500; returning null (or being absent)
     *                                                                      falls back to a bare 500. Lets the caller log/trace otherwise-swallowed
     *                                                                      errors.
     * @param null|int                                    $masterPid        if set, the server self-terminates (graceful shutdown) once it is
     *                                                                      no longer a child of this pid — i.e. its WorkerMaster died — so an
     *                                                                      orphaned worker drains and exits instead of holding the port.
     *                                                                      Under WorkerMaster this is set automatically from the injected
     *                                                                      --masterPid flag via fromArgs(); null (default) off.
     * @param string                                      $adminToken       gates the dedicated stats server (with $statsPort): when both are
     *                                                                      set, each worker binds the stats port (SO_REUSEPORT) and serves
     *                                                                      GET /api/stats to a request with "Authorization: Bearer <token>".
     *                                                                      Empty (default) off. fromArgs() reads it from SCONCUR_ADMIN_TOKEN.
     * @param string                                      $statsDir         directory for the per-worker snapshot files the stats endpoint
     *                                                                      aggregates (empty = sys_get_temp_dir()/sconcur/stats). Workers of
     *                                                                      one reuse-port pool must share this dir to be aggregated together.
     * @param string                                      $serverName       snapshot file prefix and aggregation scope: only servers of the
     *                                                                      same name are summed together (default "sconcur-server").
     * @param int                                         $statsPort        port for the dedicated stats HTTP server (0 = off). With a token
     *                                                                      set, each worker binds it via SO_REUSEPORT and serves GET
     *                                                                      /api/stats. fromArgs() reads it from SCONCUR_STATS_PORT.
     *
     * Defaults mirror the Go server defaults.
     */
    public function __construct(
        private string $address = '0.0.0.0:7832',
        private int $readHeaderTimeoutMs = 10_000,
        private int $readTimeoutMs = 30_000,
        private int $writeTimeoutMs = 30_000,
        private int $idleTimeoutMs = 60_000,
        private int $shutdownTimeoutMs = 5_000,
        private int $maxRequestBody = 10_485_760,
        private int $maxConcurrency = 0,
        private int $handlerTimeoutMs = 60_000,
        private int $maxRequests = 0,
        private bool $reusePort = false,
        private ?Closure $onError = null,
        private ?int $masterPid = null,
        private string $adminToken = '',
        private string $statsDir = '',
        private string $serverName = 'sconcur-server',
        private int $statsPort = 0,
    ) {
    }

    /**
     * Create a new server from command line arguments.
     *
     * @param array<int, string>                          $argv    Command line arguments.
     * @param Closure(Throwable, Request): ?Response|null $onError Error handler.
     */
    public static function fromArgs(array $argv, ?Closure $onError = null): HttpServer
    {
        $overrides = self::parseArgs($argv);

        if ($onError !== null) {
            $overrides['onError'] = $onError;
        }

        $overrides = self::applyStatsEnvironment($overrides);

        return new HttpServer(...$overrides);
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

        $statsDir = $this->statsDir !== ''
            ? $this->statsDir
            : sys_get_temp_dir() . '/sconcur/stats';

        $stopRequested = false;

        // Install handlers before starting the listener so a signal arriving during
        // startup is not missed, and restore the previous ones when serving ends.
        $restoreSignals = $this->installSignalHandlers($stopRequested);

        try {
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
                    maxConcurrency: $this->maxConcurrency,
                    handlerTimeoutMs: $this->handlerTimeoutMs,
                    reusePort: $this->reusePort,
                    adminToken: $this->adminToken,
                    statsDir: $statsDir,
                    serverName: $this->serverName,
                    statsPort: $this->statsPort,
                ),
            );

            $onError   = $this->onError;
            $masterPid = $this->masterPid;

            Scheduler::get()->serve(
                serverFlowKey: $flowKey,
                serverTaskKey: $runningTask->key,
                maxRequests: $this->maxRequests,
                onRequest: static function (string $payload) use ($handler, $onError): void {
                    self::handle(
                        handler: $handler,
                        onError: $onError,
                        payload: $payload,
                    );
                },
                shouldStop: static function () use (&$stopRequested, $masterPid): bool {
                    // Stop on a shutdown signal, or when this worker has been orphaned
                    // — its WorkerMaster (parent pid) is gone.
                    return $stopRequested || ($masterPid !== null && self::isOrphaned($masterPid));
                },
                onDrainStart: static function () use ($flowKey): void {
                    // Leave the SO_REUSEPORT group early: stop accepting so new
                    // connections go to sibling processes, then drain in-flight.
                    Extension::get()->httpStopAccepting($flowKey);
                },
            );
        } finally {
            $restoreSignals();
        }
    }

    /**
     * Runs inside a spawned coroutine: decode the request, resolve the handler's
     * result, then send it back to Go. A plain Response is one atomic write; a
     * StreamedResponse is driven head/chunk/end. Resolution is guarded so the
     * connection is always answered — a handler that throws or returns the wrong
     * type still gets a 500 instead of hanging the client until a timeout.
     *
     * @param Closure(Request): (Response|StreamedResponse) $handler
     * @param null|Closure(Throwable, Request): ?Response   $onError
     */
    private static function handle(Closure $handler, ?Closure $onError, string $payload): void
    {
        $request = Request::fromPayload($payload);

        $response = self::resolveResponse(
            handler: $handler,
            onError: $onError,
            request: $request,
        );

        // The access log is written on the Go side (see ext httpserver server.go),
        // so the per-request hot path here makes no extra PHP->Go crossing for it.
        if ($response instanceof StreamedResponse) {
            self::stream($request, $response, $onError);
        } else {
            FeatureExecutor::exec(
                payload: RespondPayload::full(
                    requestId: $request->requestId,
                    status: $response->status,
                    headers: $response->headers,
                    body: $response->body,
                ),
            );
        }
    }

    /**
     * Drives a streamed response: send the head, run the writer (which pushes
     * flushed chunks), then always end the stream. Once the head is on the wire
     * the status can no longer change, so a failure inside the writer is only
     * reported to $onError, not turned into a 500.
     *
     * @param null|Closure(Throwable, Request): ?Response $onError
     */
    private static function stream(Request $request, StreamedResponse $response, ?Closure $onError): void
    {
        FeatureExecutor::exec(
            payload: RespondPayload::head(
                requestId: $request->requestId,
                status: $response->status,
                headers: $response->headers,
            ),
        );

        try {
            ($response->writer)(new ResponseStream($request->requestId));
        } catch (Throwable $exception) {
            self::notifyOnError($onError, $exception, $request);
        } finally {
            try {
                FeatureExecutor::exec(
                    payload: RespondPayload::end($request->requestId),
                );
            } catch (Throwable) {
                // Best-effort stream teardown: if the connection is already gone
                // (aborted/timed out) the end write fails — nothing left to do, and
                // it must not mask the original failure or skip the access log.
            }
        }
    }

    /**
     * Calls the handler and validates its result. Any throwable — or a result that
     * is neither a Response nor a StreamedResponse — is reported to $onError (if
     * given) and turned into a 500.
     *
     * $handler is typed as a bare Closure here on purpose: PHP does not enforce a
     * closure's declared return type at runtime, so the instanceof guard below is a
     * real check, not dead code.
     *
     * @param null|Closure(Throwable, Request): ?Response $onError
     */
    private static function resolveResponse(
        Closure $handler,
        ?Closure $onError,
        Request $request,
    ): Response|StreamedResponse {
        try {
            $response = $handler($request);

            if (!$response instanceof Response && !$response instanceof StreamedResponse) {
                throw new InvalidHandlerResponseException(
                    message: sprintf(
                        'HTTP handler must return %s or %s, got %s.',
                        Response::class,
                        StreamedResponse::class,
                        get_debug_type($response),
                    ),
                );
            }

            return $response;
        } catch (RequestBodyTooLargeException $exception) {
            // The body exceeded maxRequestBody mid-read and the response has not
            // started: answer 413 rather than a generic 500.
            self::notifyOnError($onError, $exception, $request);

            return new Response(body: 'Payload Too Large', status: 413);
        } catch (Throwable $exception) {
            return self::handleError($onError, $exception, $request);
        }
    }

    /**
     * Builds the error response: let $onError observe the failure (and optionally
     * supply its own Response); fall back to a bare 500 if it is absent, returns
     * null, or itself throws.
     *
     * @param null|Closure(Throwable, Request): ?Response $onError
     */
    private static function handleError(?Closure $onError, Throwable $exception, Request $request): Response
    {
        if ($onError !== null) {
            try {
                $custom = $onError($exception, $request);

                if ($custom instanceof Response) {
                    return $custom;
                }
            } catch (Throwable) {
                // The error hook itself failed: still answer the client with a 500.
            }
        }

        return new Response(body: 'Internal Server Error', status: 500);
    }

    /**
     * Reports a failure to $onError for observability, swallowing anything the hook
     * itself throws. Used on the streaming path where the head is already sent and
     * the hook's return value cannot be applied.
     *
     * @param null|Closure(Throwable, Request): ?Response $onError
     */
    private static function notifyOnError(?Closure $onError, Throwable $exception, Request $request): void
    {
        if ($onError === null) {
            return;
        }

        try {
            $onError($exception, $request);
        } catch (Throwable) {
            // Observability only: a failing hook must not break stream teardown.
        }
    }
}
