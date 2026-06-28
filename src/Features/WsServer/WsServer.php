<?php

declare(strict_types=1);

namespace SConcur\Features\WsServer;

use Closure;
use SConcur\Connection\Extension;
use SConcur\Features\Server\ServerRuntimeSupportTrait;
use SConcur\Features\WsServer\Dto\Connection;
use SConcur\Features\WsServer\Payloads\ServePayload;
use SConcur\Scheduler\Scheduler;
use SConcur\Transport\MessagePackTransport;
use Throwable;

/**
 * WebSocket server: the network lives in the Go extension. The listener is an HTTP
 * server; a request carrying a valid WebSocket upgrade becomes a long-lived connection
 * streamed back as a result and handled in its own coroutine, while every other request
 * is answered 426 Upgrade Required. After the upgrade the connection is a bidirectional
 * message stream — the handler reads inbound messages and pushes messages to the client
 * at any time (server push), both text and binary. See docs/websocket-server.ru.md.
 */
readonly class WsServer
{
    use ServerRuntimeSupportTrait;

    /**
     * @param string                                    $address             host:port to bind, e.g. "0.0.0.0:9200".
     * @param int                                       $handshakeTimeoutMs  max time to read the upgrade request headers.
     * @param int                                       $idleTimeoutMs       idle timeout while reading the next inbound message
     *                                                                       (0 = disabled). A push-only connection is kept alive by the
     *                                                                       server ping instead.
     * @param int                                       $writeTimeoutMs      max time to write one message (or one keepalive ping) to the
     *                                                                       client before it fails.
     * @param int                                       $pingIntervalMs      server keepalive ping cadence (0 = disabled).
     * @param int                                       $maxMessageBytes     max size of a single inbound message; an oversize message
     *                                                                       closes the connection with 1009 (message too big). 0 = no limit.
     * @param int                                       $maxConcurrency      max connections handled at once (0 = unlimited). Bounds
     *                                                                       goroutines and connection coroutines; excess connections wait
     *                                                                       for a free slot.
     * @param int                                       $maxConnections      stop the server after it has handled this many connections
     *                                                                       (0 = unlimited). Meant against handler memory leaks: once reached
     *                                                                       the server shuts down gracefully so a master can respawn a fresh
     *                                                                       process. Reuses the graceful-shutdown path.
     * @param bool                                      $reusePort           set SO_REUSEPORT so several processes can bind this same address;
     *                                                                       the kernel load-balances connections across them (run one process
     *                                                                       per core). Linux only; each process must set it.
     * @param string                                    $path                restrict the upgrade endpoint to this request path (empty = any
     *                                                                       path); a request to another path is answered 404.
     * @param list<string>                              $allowedOrigins      host patterns accepted by the origin check (empty = allow any
     *                                                                       origin, the check is skipped; rely on a firewall/the master).
     * @param list<string>                              $subprotocols        WebSocket subprotocols the server negotiates (empty = none).
     * @param null|Closure(Throwable, Connection): void $onError             observes an uncaught handler failure (for logging/tracing); the
     *                                                                       connection is closed afterwards. The hook may itself write a final
     *                                                                       message to the connection before it is closed.
     * @param null|int                                  $masterPid           if set, the server self-terminates (graceful shutdown) once it is
     *                                                                       no longer a child of this pid — i.e. its WorkerMaster died. Under
     *                                                                       WorkerMaster this is set automatically from the injected
     *                                                                       --masterPid flag via fromArgs(); null (default) off.
     * @param string                                    $telemetrySocket     unix socket of the stats collector the worker pushes snapshots
     *                                                                       to (empty = push off). Best-effort and lossy: an absent collector
     *                                                                       never affects serving. fromArgs() reads it from
     *                                                                       SCONCUR_TELEMETRY_SOCKET (the master injects it from runtimeDir/name).
     * @param string                                    $serverName          labels the pushed snapshot — the pool scope the collector
     *                                                                       aggregates by (default "sconcur-server").
     * @param int                                       $telemetryIntervalMs snapshot sample/push cadence in ms (0 = default).
     *
     * Defaults mirror the Go server defaults.
     */
    public function __construct(
        private string $address = '0.0.0.0:9200',
        private int $handshakeTimeoutMs = 10_000,
        private int $idleTimeoutMs = 0,
        private int $writeTimeoutMs = 30_000,
        private int $pingIntervalMs = 30_000,
        private int $maxMessageBytes = 1_048_576,
        private int $maxConcurrency = 0,
        private int $maxConnections = 0,
        private int $shutdownTimeoutMs = 5_000,
        private bool $reusePort = false,
        private string $path = '/',
        private array $allowedOrigins = [],
        private array $subprotocols = [],
        private ?Closure $onError = null,
        private ?int $masterPid = null,
        private string $telemetrySocket = '',
        private string $serverName = 'sconcur-server',
        private int $telemetryIntervalMs = 0,
    ) {
    }

    /**
     * Create a new server from command line arguments (scalar constructor params as
     * --name=value). Under WorkerMaster the injected --masterPid wires the orphan
     * check.
     *
     * @param array<int, string>                        $argv
     * @param Closure(Throwable, Connection): void|null $onError
     */
    public static function fromArgs(array $argv, ?Closure $onError = null): WsServer
    {
        $overrides = self::parseArgs($argv);

        if ($onError !== null) {
            $overrides['onError'] = $onError;
        }

        $overrides = self::applyTelemetryEnvironment($overrides);

        return new WsServer(...$overrides);
    }

    /**
     * Starts the listener and serves forever (until the flow is stopped or a shutdown
     * signal arrives). The handler receives a Connection and drives it: read() for
     * inbound messages, write() to push messages, close() to end it. It runs in its own
     * coroutine, so it may issue concurrent async calls (Mongodb, Sleeper, ...). The
     * connection is always closed when the handler returns.
     *
     * @param Closure(Connection): void $handler
     */
    public function serve(Closure $handler): void
    {
        $flowKey = uniqid('ws_', more_entropy: true);

        $stopRequested = false;

        // Install handlers before starting the listener so a signal arriving during
        // startup is not missed, and restore the previous ones when serving ends.
        $restoreSignals = $this->installSignalHandlers($stopRequested);

        try {
            $runningTask = Extension::get()->push(
                flowKey: $flowKey,
                payload: new ServePayload(
                    address: $this->address,
                    handshakeTimeoutMs: $this->handshakeTimeoutMs,
                    idleTimeoutMs: $this->idleTimeoutMs,
                    writeTimeoutMs: $this->writeTimeoutMs,
                    pingIntervalMs: $this->pingIntervalMs,
                    maxMessageBytes: $this->maxMessageBytes,
                    maxConcurrency: $this->maxConcurrency,
                    shutdownTimeoutMs: $this->shutdownTimeoutMs,
                    reusePort: $this->reusePort,
                    path: $this->path,
                    allowedOrigins: $this->allowedOrigins,
                    subprotocols: $this->subprotocols,
                    telemetrySocket: $this->telemetrySocket,
                    serverName: $this->serverName,
                    telemetryIntervalMs: $this->telemetryIntervalMs,
                ),
            );

            self::logServerEvent(
                sprintf(
                    'sconcur ws server listening on %s pid=%d version=%s maxConcurrency=%d maxConnections=%d reusePort=%d',
                    $this->address,
                    getmypid(),
                    Extension::REQUIRED_EXTENSION_VERSION,
                    $this->maxConcurrency,
                    $this->maxConnections,
                    (int) $this->reusePort,
                ),
            );

            $onError   = $this->onError;
            $masterPid = $this->masterPid;

            Scheduler::get()->serve(
                serverFlowKey: $flowKey,
                serverTaskKey: $runningTask->key,
                maxRequests: $this->maxConnections,
                onRequest: static function (string $payload) use ($handler, $onError): void {
                    self::handleConnection(
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
                    // Leave the SO_REUSEPORT group early: stop accepting and drain
                    // in-flight connections so new connections go to siblings.
                    Extension::get()->wsStopAccepting($flowKey);
                },
                onShutdownStep: static function (string $step): void {
                    self::logServerEvent('sconcur ws server shutdown: ' . $step);
                },
            );
        } finally {
            $restoreSignals();
        }
    }

    /**
     * Runs inside a spawned coroutine for one connection: decode the connection event,
     * hand a Connection to the handler, and always close it afterwards. A handler that
     * throws is reported to $onError (which may write a final message) and the
     * connection is then closed — one bad connection never takes the server down.
     *
     * @param Closure(Connection): void                 $handler
     * @param null|Closure(Throwable, Connection): void $onError
     */
    private static function handleConnection(Closure $handler, ?Closure $onError, string $payload): void
    {
        /** @var array<string, mixed> $event */
        $event = MessagePackTransport::unpack($payload);

        $connection = new Connection(
            id: (string) ($event['cid'] ?? ''),
            remoteAddr: (string) ($event['ra'] ?? ''),
            localAddr: (string) ($event['la'] ?? ''),
            path: (string) ($event['pa'] ?? ''),
            subprotocol: (string) ($event['su'] ?? ''),
        );

        try {
            $handler($connection);
        } catch (Throwable $exception) {
            self::notifyOnError(
                onError: $onError,
                exception: $exception,
                connection: $connection,
            );
        } finally {
            $connection->close();
        }
    }

    /**
     * Reports an uncaught handler failure to $onError for observability, swallowing
     * anything the hook itself throws.
     *
     * @param null|Closure(Throwable, Connection): void $onError
     */
    private static function notifyOnError(?Closure $onError, Throwable $exception, Connection $connection): void
    {
        if ($onError === null) {
            return;
        }

        try {
            $onError($exception, $connection);
        } catch (Throwable) {
            // Observability only: a failing hook must not break connection teardown.
        }
    }
}
