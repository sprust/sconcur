<?php

declare(strict_types=1);

namespace SConcur\Features\HttpServer;

use Closure;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use SConcur\Connection\Extension;
use SConcur\Exceptions\HttpServer\InvalidHandlerResponseException;
use SConcur\Exceptions\HttpServer\RequestBodyTooLargeException;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\HttpServer\Dto\RequestBody;
use SConcur\Features\HttpServer\Dto\RequestBodyStream;
use SConcur\Features\HttpServer\Payloads\RespondPayload;
use SConcur\Features\HttpServer\Payloads\ServePayload;
use SConcur\Features\Server\ServerRuntimeSupportTrait;
use SConcur\Scheduler\Scheduler;
use SConcur\Transport\MessagePackTransport;
use Throwable;

/**
 * HTTP server: the network lives in the Go extension, each accepted request is
 * streamed back as a result and handled in its own coroutine (spawn-on-request).
 *
 * The public surface is PSR-7: the handler receives a ServerRequestInterface and
 * returns a ResponseInterface (built by the caller's own PSR-17 implementation, so
 * the library stays implementation-agnostic — the mirror of the PSR-18 HttpClient).
 * Streaming responses (chunked / SSE) are expressed by returning a response whose
 * body is a lazy StreamInterface of unknown size; the framework drains it chunk by
 * chunk with write backpressure. See docs/http-server.md.
 */
readonly class HttpServer
{
    use ServerRuntimeSupportTrait;

    /** Read granularity used when draining a streamed (unknown-length) response body. */
    private const int RESPONSE_STREAM_CHUNK_SIZE = 65_536;

    /**
     * @param ServerRequestFactoryInterface                                       $serverRequestFactory PSR-17 factory used to
     *                                                                                                  build the ServerRequestInterface handed to the handler.
     * @param ResponseFactoryInterface                                            $responseFactory      PSR-17 factory used to build
     *                                                                                                  the fallback 413/500 responses (and any error response).
     * @param int                                                                 $maxRequestBody       request body read limit, in bytes
     * @param int                                                                 $maxConcurrency       max requests handled at once (0 = unlimited).
     *                                                                                                  Bounds goroutines, buffered request bodies (memory) and request
     *                                                                                                  coroutines; excess connections wait for a free slot. Set it to
     *                                                                                                  cap resource use under load.
     * @param int                                                                 $handlerTimeoutMs     max total time to handle a request, including a streamed
     *                                                                                                  response, before it is cut off and the slot freed (default 60s;
     *                                                                                                  0 disables). If nothing was written yet the client gets a 504;
     *                                                                                                  mid-stream the response is aborted (status is already on the wire).
     *                                                                                                  Note: a CPU-bound handler still blocks the single-threaded loop;
     *                                                                                                  this guards handlers waiting on async work.
     * @param int                                                                 $maxRequests          stop the server after it has handled this many requests
     *                                                                                                  (0 = unlimited). Meant against handler memory leaks: once the
     *                                                                                                  count is reached the server shuts down gracefully (closes the
     *                                                                                                  listener first, drains in-flight, exits cleanly) so a master /
     *                                                                                                  supervisor can respawn a fresh process. Reuses the graceful-
     *                                                                                                  shutdown path, so no already-accepted request is bounced.
     * @param bool                                                                $reusePort            set SO_REUSEPORT so several processes can bind this same
     *                                                                                                  address; the kernel load-balances connections across them
     *                                                                                                  (run one process per core). Linux only; each process must set it.
     * @param null|Closure(Throwable, ServerRequestInterface): ?ResponseInterface $onError              observes a handler
     *                                                                                                  failure (exception or a non-ResponseInterface return). It may return a
     *                                                                                                  ResponseInterface to send instead of the default 500; returning null (or
     *                                                                                                  being absent) falls back to a bare 500. Lets the caller log/trace
     *                                                                                                  otherwise-swallowed errors.
     * @param null|int                                                            $masterPid            if set, the server self-terminates (graceful shutdown) once it is
     *                                                                                                  no longer a child of this pid — i.e. its WorkerMaster died — so an
     *                                                                                                  orphaned worker drains and exits instead of holding the port.
     *                                                                                                  Under WorkerMaster this is set automatically from the injected
     *                                                                                                  --masterPid flag via fromArgs(); null (default) off.
     * @param string                                                              $telemetrySocket      unix socket of the stats collector the worker pushes snapshots
     *                                                                                                  to (empty = push off). Best-effort and lossy: an absent collector
     *                                                                                                  never affects serving. fromArgs() reads it from
     *                                                                                                  SCONCUR_TELEMETRY_SOCKET (the master injects it from runtimeDir/name).
     * @param string                                                              $serverName           labels the pushed snapshot — the pool scope the collector
     *                                                                                                  aggregates by (default "sconcur-server").
     * @param int                                                                 $telemetryIntervalMs  snapshot sample/push cadence in ms (0 = default).
     *
     * Defaults mirror the Go server defaults.
     */
    public function __construct(
        private ServerRequestFactoryInterface $serverRequestFactory,
        private ResponseFactoryInterface $responseFactory,
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
        private string $telemetrySocket = '',
        private string $serverName = 'sconcur-server',
        private int $telemetryIntervalMs = 0,
    ) {
    }

    /**
     * Create a new server from command line arguments. The PSR-17 factories are
     * supplied by the caller (argv only carries scalar tuning options).
     *
     * @param array<int, string>                                                  $argv    Command line arguments.
     * @param null|Closure(Throwable, ServerRequestInterface): ?ResponseInterface $onError Error handler.
     */
    public static function fromArgs(
        array $argv,
        ServerRequestFactoryInterface $serverRequestFactory,
        ResponseFactoryInterface $responseFactory,
        ?Closure $onError = null,
    ): HttpServer {
        $overrides = self::parseArgs($argv);

        if ($onError !== null) {
            $overrides['onError'] = $onError;
        }

        $overrides = self::applyTelemetryEnvironment($overrides);

        $overrides['serverRequestFactory'] = $serverRequestFactory;
        $overrides['responseFactory']      = $responseFactory;

        return new HttpServer(...$overrides);
    }

    /**
     * Starts the listener and serves forever (until the flow is stopped or a
     * shutdown signal arrives). The handler receives a ServerRequestInterface and
     * must return a ResponseInterface; it runs in its own coroutine, so it may issue
     * concurrent async calls (Mongodb, Sleeper, ...).
     *
     * @param Closure(ServerRequestInterface): ResponseInterface $handler
     */
    public function serve(Closure $handler): void
    {
        $flowKey = uniqid('http_', more_entropy: true);

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
                    telemetrySocket: $this->telemetrySocket,
                    serverName: $this->serverName,
                    telemetryIntervalMs: $this->telemetryIntervalMs,
                ),
            );

            self::logServerEvent(
                sprintf(
                    'sconcur http server listening on %s pid=%d version=%s maxConcurrency=%d maxRequests=%d reusePort=%d',
                    $this->address,
                    getmypid(),
                    Extension::REQUIRED_EXTENSION_VERSION,
                    $this->maxConcurrency,
                    $this->maxRequests,
                    (int) $this->reusePort,
                ),
            );

            $onError              = $this->onError;
            $masterPid            = $this->masterPid;
            $serverRequestFactory = $this->serverRequestFactory;
            $responseFactory      = $this->responseFactory;

            Scheduler::get()->serve(
                serverFlowKey: $flowKey,
                serverTaskKey: $runningTask->key,
                maxRequests: $this->maxRequests,
                onRequest: static function (string $payload) use (
                    $handler,
                    $onError,
                    $serverRequestFactory,
                    $responseFactory,
                ): void {
                    self::handle(
                        handler: $handler,
                        onError: $onError,
                        serverRequestFactory: $serverRequestFactory,
                        responseFactory: $responseFactory,
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
                onShutdownStep: static function (string $step): void {
                    self::logServerEvent('sconcur http server shutdown: ' . $step);
                },
            );
        } finally {
            $restoreSignals();
        }
    }

    /**
     * Runs inside a spawned coroutine: decode the request, resolve the handler's
     * result, then send it back to Go. A response of known size is one atomic write;
     * a response whose body is a streaming, unknown-size StreamInterface is driven
     * head/chunk/end. Resolution is guarded so the connection is always answered — a
     * handler that throws or returns the wrong type still gets a 500 instead of
     * hanging the client until a timeout.
     *
     * @param Closure(ServerRequestInterface): ResponseInterface                  $handler
     * @param null|Closure(Throwable, ServerRequestInterface): ?ResponseInterface $onError
     */
    private static function handle(
        Closure $handler,
        ?Closure $onError,
        ServerRequestFactoryInterface $serverRequestFactory,
        ResponseFactoryInterface $responseFactory,
        string $payload,
    ): void {
        [$requestId, $request] = self::decodeRequest(
            serverRequestFactory: $serverRequestFactory,
            payload: $payload,
        );

        $response = self::resolveResponse(
            handler: $handler,
            onError: $onError,
            responseFactory: $responseFactory,
            request: $request,
        );

        $body = $response->getBody();

        // The access log is written on the Go side (see ext httpserver server.go),
        // so the per-request hot path here makes no extra PHP->Go crossing for it.
        //
        // A known-size body is sent whole in one write; an unknown-size (null) body
        // is a streaming StreamInterface, drained chunk by chunk with backpressure.
        if ($body->getSize() !== null) {
            FeatureExecutor::exec(
                payload: RespondPayload::full(
                    requestId: $requestId,
                    status: $response->getStatusCode(),
                    headers: $response->getHeaders(),
                    body: (string) $body,
                ),
            );
        } else {
            self::stream(
                requestId: $requestId,
                request: $request,
                response: $response,
                onError: $onError,
            );
        }
    }

    /**
     * Drives a streamed response: send the head, drain the body StreamInterface
     * (each read() pushed as a flushed chunk), then always end the stream. Once the
     * head is on the wire the status can no longer change, so a failure while reading
     * the body is only reported to $onError, not turned into a 500.
     *
     * @param null|Closure(Throwable, ServerRequestInterface): ?ResponseInterface $onError
     */
    private static function stream(
        string $requestId,
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?Closure $onError,
    ): void {
        FeatureExecutor::exec(
            payload: RespondPayload::head(
                requestId: $requestId,
                status: $response->getStatusCode(),
                headers: $response->getHeaders(),
            ),
        );

        $body = $response->getBody();

        try {
            while (($chunk = $body->read(self::RESPONSE_STREAM_CHUNK_SIZE)) !== '') {
                FeatureExecutor::exec(
                    payload: RespondPayload::chunk($requestId, $chunk),
                );
            }
        } catch (Throwable $exception) {
            self::notifyOnError($onError, $exception, $request);
        } finally {
            try {
                FeatureExecutor::exec(
                    payload: RespondPayload::end($requestId),
                );
            } catch (Throwable) {
                // Best-effort stream teardown: if the connection is already gone
                // (aborted/timed out) the end write fails — nothing left to do, and
                // it must not mask the original failure or skip the access log.
            }
        }
    }

    /**
     * Decodes the streaming payload the Go server emits (payloads.RequestEvent) into
     * a PSR-7 ServerRequestInterface, returning it together with the request id used
     * to address the response. The body is wrapped in a lazy RequestBodyStream so it
     * is never buffered whole.
     *
     * @return array{0: string, 1: ServerRequestInterface}
     */
    private static function decodeRequest(
        ServerRequestFactoryInterface $serverRequestFactory,
        string $payload,
    ): array {
        /** @var array<string, mixed> $data */
        $data = MessagePackTransport::unpack($payload);

        $requestId  = (string) ($data['rid'] ?? '');
        $method     = (string) ($data['mt'] ?? '');
        $path       = (string) ($data['pt'] ?? '');
        $query      = (string) ($data['qr'] ?? '');
        $host       = (string) ($data['ho'] ?? '');
        $proto      = (string) ($data['pr'] ?? '');
        $remoteAddr = (string) ($data['ra'] ?? '');

        $uri = 'http://' . ($host !== '' ? $host : 'localhost') . ($path !== '' ? $path : '/');

        if ($query !== '') {
            $uri .= '?' . $query;
        }

        $request = $serverRequestFactory->createServerRequest(
            $method,
            $uri,
            self::serverParams(
                method: $method,
                path: $path,
                query: $query,
                host: $host,
                proto: $proto,
                remoteAddr: $remoteAddr,
            ),
        );

        // An empty header map decodes to stdClass (a MessagePack quirk), and nested
        // values may too; normalize to array<string, array<int, string>> and set each.
        foreach ((array) ($data['hd'] ?? []) as $name => $values) {
            $request = $request->withHeader((string) $name, array_values((array) $values));
        }

        if ($query !== '') {
            parse_str($query, $queryParams);

            $request = $request->withQueryParams($queryParams);
        }

        $protocolVersion = str_starts_with($proto, 'HTTP/') ? substr($proto, 5) : $proto;

        if ($protocolVersion !== '') {
            $request = $request->withProtocolVersion($protocolVersion);
        }

        $request = $request->withBody(
            new RequestBodyStream(
                new RequestBody(
                    firstChunk: (string) ($data['bd'] ?? ''),
                    bodyKey: (string) ($data['bk'] ?? ''),
                ),
            ),
        );

        return [$requestId, $request];
    }

    /**
     * Builds the SAPI-style server parameters exposed via getServerParams().
     *
     * @return array<string, string>
     */
    private static function serverParams(
        string $method,
        string $path,
        string $query,
        string $host,
        string $proto,
        string $remoteAddr,
    ): array {
        $lastColon  = strrpos($remoteAddr, ':');
        $remoteHost = $lastColon === false ? $remoteAddr : substr($remoteAddr, 0, $lastColon);
        $remotePort = $lastColon === false ? '' : substr($remoteAddr, $lastColon + 1);

        return [
            'REQUEST_METHOD'  => $method,
            'REQUEST_URI'     => $path . ($query !== '' ? '?' . $query : ''),
            'QUERY_STRING'    => $query,
            'SERVER_PROTOCOL' => $proto !== '' ? $proto : 'HTTP/1.1',
            'HTTP_HOST'       => $host,
            'REMOTE_ADDR'     => $remoteHost,
            'REMOTE_PORT'     => $remotePort,
        ];
    }

    /**
     * Calls the handler and validates its result. Any throwable — or a result that
     * is not a ResponseInterface — is reported to $onError (if given) and turned into
     * a 500. A body-too-large failure becomes a 413.
     *
     * $handler is typed as a bare Closure here on purpose: PHP does not enforce a
     * closure's declared return type at runtime, so the instanceof guard below is a
     * real check, not dead code.
     *
     * @param null|Closure(Throwable, ServerRequestInterface): ?ResponseInterface $onError
     */
    private static function resolveResponse(
        Closure $handler,
        ?Closure $onError,
        ResponseFactoryInterface $responseFactory,
        ServerRequestInterface $request,
    ): ResponseInterface {
        try {
            $response = $handler($request);

            if (!$response instanceof ResponseInterface) {
                throw new InvalidHandlerResponseException(
                    message: sprintf(
                        'HTTP handler must return %s, got %s.',
                        ResponseInterface::class,
                        get_debug_type($response),
                    ),
                );
            }

            return $response;
        } catch (RequestBodyTooLargeException $exception) {
            // The body exceeded maxRequestBody mid-read and the response has not
            // started: answer 413 rather than a generic 500.
            self::notifyOnError($onError, $exception, $request);

            return self::plainResponse(
                responseFactory: $responseFactory,
                status: 413,
                body: 'Payload Too Large',
            );
        } catch (Throwable $exception) {
            return self::handleError(
                onError: $onError,
                responseFactory: $responseFactory,
                exception: $exception,
                request: $request,
            );
        }
    }

    /**
     * Builds the error response: let $onError observe the failure (and optionally
     * supply its own ResponseInterface); fall back to a bare 500 if it is absent,
     * returns null, or itself throws.
     *
     * @param null|Closure(Throwable, ServerRequestInterface): ?ResponseInterface $onError
     */
    private static function handleError(
        ?Closure $onError,
        ResponseFactoryInterface $responseFactory,
        Throwable $exception,
        ServerRequestInterface $request,
    ): ResponseInterface {
        if ($onError !== null) {
            try {
                $custom = $onError($exception, $request);

                if ($custom instanceof ResponseInterface) {
                    return $custom;
                }
            } catch (Throwable) {
                // The error hook itself failed: still answer the client with a 500.
            }
        }

        return self::plainResponse(
            responseFactory: $responseFactory,
            status: 500,
            body: 'Internal Server Error',
        );
    }

    /**
     * Builds a plain text/* response (the 413/500 fallbacks) via the caller's PSR-17
     * factory, writing the body into the factory-provided stream.
     */
    private static function plainResponse(
        ResponseFactoryInterface $responseFactory,
        int $status,
        string $body,
    ): ResponseInterface {
        $response = $responseFactory->createResponse($status);

        $response->getBody()->write($body);

        return $response;
    }

    /**
     * Reports a failure to $onError for observability, swallowing anything the hook
     * itself throws. Used on the streaming path where the head is already sent and
     * the hook's return value cannot be applied.
     *
     * @param null|Closure(Throwable, ServerRequestInterface): ?ResponseInterface $onError
     */
    private static function notifyOnError(
        ?Closure $onError,
        Throwable $exception,
        ServerRequestInterface $request,
    ): void {
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
