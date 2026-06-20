<?php

declare(strict_types=1);

namespace SConcur\Features\SocketServer;

use Closure;
use SConcur\Connection\Extension;
use SConcur\Exceptions\SocketServer\InvalidHandlerResponseException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\Server\ServerRuntimeSupportTrait;
use SConcur\Features\SocketServer\Dto\Message;
use SConcur\Features\SocketServer\Dto\MessageResponse;
use SConcur\Features\SocketServer\Payloads\RespondPayload;
use SConcur\Features\SocketServer\Payloads\ServePayload;
use SConcur\Scheduler\Scheduler;
use SConcur\Transport\MessagePackTransport;
use Throwable;

/**
 * TCP socket server: the network lives in the Go extension, each accepted connection
 * is streamed back as a result and handled in its own coroutine. Within a connection
 * the handler is called once per inbound message (a length-prefixed frame) in order
 * and returns one response per message. See docs/socket-server.ru.md.
 */
readonly class SocketServer
{
    use ServerRuntimeSupportTrait;

    /**
     * @param int                                       $readTimeoutMs    idle timeout between messages on a connection (no frame
     *                                                                    within → close). 0 disables it (a connection may stay idle forever).
     * @param int                                       $writeTimeoutMs   max time to write one response frame before the write fails.
     * @param int                                       $handlerTimeoutMs max time to handle one message before the connection is cut off
     *                                                                    and the slot freed (default 60s; 0 disables). The timer runs on the
     *                                                                    Go side, independently of PHP, so it also catches a natively
     *                                                                    blocked handler. Note: a CPU-bound handler still blocks the
     *                                                                    single-threaded loop; this guards handlers waiting on async work.
     * @param int                                       $maxMessageBytes  max length of a single inbound frame (guards against a huge
     *                                                                    length prefix); an oversize frame closes the connection.
     * @param int                                       $maxConcurrency   max connections handled at once (0 = unlimited). Bounds
     *                                                                    goroutines and connection coroutines; excess connections wait
     *                                                                    for a free slot.
     * @param int                                       $maxConnections   stop the server after it has handled this many connections
     *                                                                    (0 = unlimited). Meant against handler memory leaks: once reached
     *                                                                    the server shuts down gracefully so a master can respawn a fresh
     *                                                                    process. Reuses the graceful-shutdown path.
     * @param bool                                      $reusePort        set SO_REUSEPORT so several processes can bind this same address;
     *                                                                    the kernel load-balances connections across them (run one process
     *                                                                    per core). Linux only; each process must set it.
     * @param null|Closure(Throwable, Message): ?string $onError          observes a handler failure (exception or a non-string/
     *                                                                    non-MessageResponse return). It may return a string to send as the
     *                                                                    reply instead; returning null (or being absent) sends no reply.
     * @param null|Closure(string, string): void        $onConnect        called once when a connection opens, with (connectionId, remoteAddr).
     * @param null|Closure(string): void                $onClose          called once when a connection closes, with (connectionId).
     * @param null|int                                  $masterPid        if set, the server self-terminates (graceful shutdown) once it is
     *                                                                    no longer a child of this pid — i.e. its WorkerMaster died. Under
     *                                                                    WorkerMaster this is set automatically from the injected
     *                                                                    --masterPid flag via fromArgs(); null (default) off.
     *
     * Defaults mirror the Go server defaults.
     */
    public function __construct(
        private string $address = '0.0.0.0:9100',
        private int $readTimeoutMs = 0,
        private int $writeTimeoutMs = 30_000,
        private int $handlerTimeoutMs = 60_000,
        private int $maxMessageBytes = 1_048_576,
        private int $maxConcurrency = 0,
        private int $maxConnections = 0,
        private int $shutdownTimeoutMs = 5_000,
        private bool $reusePort = false,
        private ?Closure $onError = null,
        private ?Closure $onConnect = null,
        private ?Closure $onClose = null,
        private ?int $masterPid = null,
    ) {
    }

    /**
     * Create a new server from command line arguments (scalar constructor params as
     * --name=value). Under WorkerMaster the injected --masterPid wires the orphan
     * check.
     *
     * @param array<int, string>                        $argv
     * @param Closure(Throwable, Message): ?string|null $onError
     */
    public static function fromArgs(array $argv, ?Closure $onError = null): SocketServer
    {
        $overrides = self::parseArgs($argv);

        if ($onError !== null) {
            $overrides['onError'] = $onError;
        }

        return new SocketServer(...$overrides);
    }

    /**
     * Starts the listener and serves forever (until the flow is stopped or a
     * shutdown signal arrives). The handler receives a Message and returns a string
     * (a response frame), a MessageResponse (to also close the connection), or null
     * (no reply). It runs in its own coroutine, so it may issue concurrent async
     * calls (Mongodb, Sleeper, ...).
     *
     * @param Closure(Message): (string|MessageResponse|null) $handler
     */
    public function serve(Closure $handler): void
    {
        $flowKey = uniqid('sock_', more_entropy: true);

        $stopRequested = false;

        // Install handlers before starting the listener so a signal arriving during
        // startup is not missed, and restore the previous ones when serving ends.
        $restoreSignals = $this->installSignalHandlers($stopRequested);

        try {
            $runningTask = Extension::get()->push(
                flowKey: $flowKey,
                payload: new ServePayload(
                    address: $this->address,
                    readTimeoutMs: $this->readTimeoutMs,
                    writeTimeoutMs: $this->writeTimeoutMs,
                    handlerTimeoutMs: $this->handlerTimeoutMs,
                    maxMessageBytes: $this->maxMessageBytes,
                    maxConcurrency: $this->maxConcurrency,
                    shutdownTimeoutMs: $this->shutdownTimeoutMs,
                    reusePort: $this->reusePort,
                ),
            );

            $onError   = $this->onError;
            $onConnect = $this->onConnect;
            $onClose   = $this->onClose;
            $masterPid = $this->masterPid;

            Scheduler::get()->serve(
                serverFlowKey: $flowKey,
                serverTaskKey: $runningTask->key,
                maxRequests: $this->maxConnections,
                onRequest: static function (string $payload) use ($handler, $onError, $onConnect, $onClose): void {
                    self::handleConnection(
                        handler: $handler,
                        onError: $onError,
                        onConnect: $onConnect,
                        onClose: $onClose,
                        payload: $payload,
                    );
                },
                shouldStop: static function () use (&$stopRequested, $masterPid): bool {
                    // Stop on a shutdown signal, or when this worker has been orphaned
                    // — its WorkerMaster (parent pid) is gone.
                    return $stopRequested || ($masterPid !== null && self::isOrphaned($masterPid));
                },
                onDrainStart: static function () use ($flowKey): void {
                    // Leave the SO_REUSEPORT group early: stop accepting and half-close
                    // in-flight connections so new connections go to siblings.
                    Extension::get()->socketStopAccepting($flowKey);
                },
            );
        } finally {
            $restoreSignals();
        }
    }

    /**
     * Runs inside a spawned coroutine for one connection: decode the connection
     * event, then loop reading one inbound frame at a time, calling the handler and
     * sending its reply, until the connection ends. The connection is always closed
     * and onClose always fires, even on error.
     *
     * @param Closure(Message): (string|MessageResponse|null) $handler
     * @param null|Closure(Throwable, Message): ?string       $onError
     * @param null|Closure(string, string): void              $onConnect
     * @param null|Closure(string): void                      $onClose
     */
    private static function handleConnection(
        Closure $handler,
        ?Closure $onError,
        ?Closure $onConnect,
        ?Closure $onClose,
        string $payload,
    ): void {
        /** @var array<string, mixed> $event */
        $event = MessagePackTransport::unpack($payload);

        $connectionId = (string) ($event['cid'] ?? '');
        $remoteAddr   = (string) ($event['ra'] ?? '');
        $localAddr    = (string) ($event['la'] ?? '');

        $inboundKey = $connectionId . ':in';

        $messageIndex = 0;

        self::notifyOnConnect(
            onConnect: $onConnect,
            connectionId: $connectionId,
            remoteAddr: $remoteAddr,
        );

        try {
            while (true) {
                try {
                    $result = FeatureExecutor::next(taskKey: $inboundKey);
                } catch (TaskErrorException) {
                    // The connection was reset/abandoned on the Go side.
                    break;
                }

                // EOF: the inbound stream ended (peer closed or graceful drain).
                if (!$result->hasNext) {
                    break;
                }

                $message = new Message(
                    connectionId: $connectionId,
                    data: $result->payload,
                    remoteAddr: $remoteAddr,
                    localAddr: $localAddr,
                    messageIndex: $messageIndex++,
                );

                $response = self::resolveResponse(
                    handler: $handler,
                    onError: $onError,
                    message: $message,
                );

                try {
                    self::respond(
                        connectionId: $connectionId,
                        response: $response,
                    );
                } catch (TaskErrorException) {
                    // The connection is gone (write failed/abandoned): stop.
                    break;
                }

                if ($response instanceof MessageResponse && $response->close) {
                    break;
                }
            }
        } finally {
            self::sendClose($connectionId);

            self::notifyOnClose(
                onClose: $onClose,
                connectionId: $connectionId,
            );
        }
    }

    /**
     * Calls the handler and validates its result. Any throwable — or a result that is
     * not a string, MessageResponse or null — is reported to $onError (which may
     * supply a string reply instead) and otherwise becomes a no-reply.
     *
     * $handler is typed as a bare Closure here on purpose: PHP does not enforce a
     * closure's declared return type at runtime, so the guard below is a real check.
     *
     * @param null|Closure(Throwable, Message): ?string $onError
     */
    private static function resolveResponse(
        Closure $handler,
        ?Closure $onError,
        Message $message,
    ): string|MessageResponse|null {
        try {
            $response = $handler($message);

            if (($response !== null) && !is_string($response) && !$response instanceof MessageResponse) {
                throw new InvalidHandlerResponseException(
                    message: sprintf(
                        'Socket handler must return string, %s or null, got %s.',
                        MessageResponse::class,
                        get_debug_type($response),
                    ),
                );
            }

            return $response;
        } catch (Throwable $exception) {
            return self::handleError(
                onError: $onError,
                exception: $exception,
                message: $message,
            );
        }
    }

    /**
     * Lets $onError observe the failure and optionally supply a string reply; falls
     * back to no reply if it is absent, returns a non-string, or itself throws.
     *
     * @param null|Closure(Throwable, Message): ?string $onError
     */
    private static function handleError(?Closure $onError, Throwable $exception, Message $message): ?string
    {
        if ($onError === null) {
            return null;
        }

        try {
            $custom = $onError($exception, $message);

            if (is_string($custom)) {
                return $custom;
            }
        } catch (Throwable) {
            // The error hook itself failed: send no reply.
        }

        return null;
    }

    /**
     * Sends one response back to Go for a handled message: a frame (string), a frame
     * with close (MessageResponse), a close-only (empty MessageResponse with close),
     * or a no-reply acknowledgement (null) — which still disarms the Go handler timer.
     */
    private static function respond(string $connectionId, string|MessageResponse|null $response): void
    {
        $payload = match (true) {
            $response === null     => RespondPayload::skip(connectionId: $connectionId),
            is_string($response)   => RespondPayload::frame(connectionId: $connectionId, data: $response),
            $response->data === '' => RespondPayload::skip(connectionId: $connectionId, close: $response->close),
            default                => RespondPayload::frame(
                connectionId: $connectionId,
                data: $response->data,
                close: $response->close,
            ),
        };

        FeatureExecutor::exec(payload: $payload);
    }

    /**
     * Best-effort close of the connection at the end of its message loop. The
     * connection may already be gone (peer disconnected), so a failure is ignored.
     */
    private static function sendClose(string $connectionId): void
    {
        try {
            FeatureExecutor::exec(payload: RespondPayload::close($connectionId));
        } catch (Throwable) {
            // The connection is already gone — nothing to close.
        }
    }

    /**
     * @param null|Closure(string, string): void $onConnect
     */
    private static function notifyOnConnect(?Closure $onConnect, string $connectionId, string $remoteAddr): void
    {
        if ($onConnect === null) {
            return;
        }

        try {
            $onConnect($connectionId, $remoteAddr);
        } catch (Throwable) {
            // Observability only: a failing hook must not break the connection loop.
        }
    }

    /**
     * @param null|Closure(string): void $onClose
     */
    private static function notifyOnClose(?Closure $onClose, string $connectionId): void
    {
        if ($onClose === null) {
            return;
        }

        try {
            $onClose($connectionId);
        } catch (Throwable) {
            // Observability only: a failing hook must not break teardown.
        }
    }
}
