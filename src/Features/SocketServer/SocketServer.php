<?php

declare(strict_types=1);

namespace SConcur\Features\SocketServer;

use Closure;
use SConcur\Connection\Extension;
use SConcur\Exceptions\FlowStoppedException;
use SConcur\Features\Server\ServerRuntimeSupportTrait;
use SConcur\Features\SocketServer\Dto\Connection;
use SConcur\Features\SocketServer\Payloads\ServePayload;
use SConcur\Scheduler\Scheduler;
use SConcur\Transport\MessagePackTransport;
use Throwable;

/**
 * TCP socket server: the network lives in the Go extension, each accepted connection
 * is streamed back as a result and handled in its own coroutine. The handler drives
 * the connection itself — it reads inbound frames and pushes frames to the client at
 * any time (server push), both length-prefix framed. See docs/socket-server.md.
 */
readonly class SocketServer
{
    use ServerRuntimeSupportTrait;

    /**
     * @param int                                       $readTimeoutMs       idle timeout while reading the next inbound frame
     *                                                                       (0 = disabled). A push-only handler that never reads is unaffected.
     * @param int                                       $writeTimeoutMs      max time to write one frame to the client before it fails.
     * @param int                                       $maxMessageBytes     max length of a single inbound frame (guards against a huge
     *                                                                       length prefix); an oversize frame ends the connection's input.
     * @param int                                       $maxConcurrency      max connections handled at once (0 = unlimited). Bounds
     *                                                                       goroutines and connection coroutines; excess connections wait
     *                                                                       for a free slot.
     * @param int                                       $handlerTimeoutMs    max time one connection handler may run before it is unwound
     *                                                                       where it stands (0 = unlimited). A handler cut this way ends
     *                                                                       quietly: its finally blocks run, onError is not told, and the
     *                                                                       connection is closed on the way out by the handler wrapper's
     *                                                                       own finally. With preemption armed (the default) it reaches a
     *                                                                       handler busy with computation too — see
     *                                                                       docs/coroutine-timeout.md.
     * @param int                                       $maxConnections      stop the server after it has handled this many connections
     *                                                                       (0 = unlimited). Meant against handler memory leaks: once reached
     *                                                                       the server shuts down gracefully so a master can respawn a fresh
     *                                                                       process. Reuses the graceful-shutdown path.
     * @param bool                                      $reusePort           set SO_REUSEPORT so several processes can bind this same address;
     *                                                                       the kernel load-balances connections across them (run one process
     *                                                                       per core). Linux only; each process must set it.
     * @param null|Closure(Throwable, Connection): void $onError             observes an uncaught handler failure (for logging/tracing); the
     *                                                                       connection is closed afterwards. The hook may itself write a final
     *                                                                       frame to the connection before it is closed.
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
     * @param int                                       $preemptionQuantumMs automatic-preemption quantum: while serving, the
     *                                                                       extension interrupts the VM every quantum and parks the
     *                                                                       running handler coroutine, so a CPU-bound handler cannot
     *                                                                       starve the other in-flight connections. Enabled by default
     *                                                                       (5 ms); 0 disables.
     *
     * Defaults mirror the Go server defaults.
     */
    public function __construct(
        protected string $address = '0.0.0.0:9100',
        protected int $readTimeoutMs = 0,
        protected int $writeTimeoutMs = 30_000,
        protected int $maxMessageBytes = 1_048_576,
        protected int $maxConcurrency = 0,
        protected int $handlerTimeoutMs = 0,
        protected int $maxConnections = 0,
        protected int $shutdownTimeoutMs = 10_000,
        protected bool $reusePort = false,
        protected ?Closure $onError = null,
        protected ?int $masterPid = null,
        protected string $telemetrySocket = '',
        protected string $serverName = 'sconcur-server',
        protected int $telemetryIntervalMs = 0,
        protected int $preemptionQuantumMs = 5,
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
    public static function fromArgs(array $argv, ?Closure $onError = null): SocketServer
    {
        $overrides = self::parseArgs($argv);

        if ($onError !== null) {
            $overrides['onError'] = $onError;
        }

        $overrides = self::applyTelemetryEnvironment($overrides);

        return new SocketServer(...$overrides);
    }

    /**
     * Starts the listener and serves forever (until the flow is stopped or a
     * shutdown signal arrives). The handler receives a Connection and drives it:
     * read() for inbound frames, write() to push frames, close() to end it. It runs
     * in its own coroutine, so it may issue concurrent async calls (Mongodb,
     * Sleeper, ...). The connection is always closed when the handler returns.
     *
     * @param Closure(Connection): void $handler
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
                    maxMessageBytes: $this->maxMessageBytes,
                    maxConcurrency: $this->maxConcurrency,
                    shutdownTimeoutMs: $this->shutdownTimeoutMs,
                    reusePort: $this->reusePort,
                    telemetrySocket: $this->telemetrySocket,
                    serverName: $this->serverName,
                    telemetryIntervalMs: $this->telemetryIntervalMs,
                ),
            );

            self::logServerEvent(
                sprintf(
                    'sconcur socket server listening on %s pid=%d version=%s maxConcurrency=%d maxConnections=%d reusePort=%d',
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
                    Extension::get()->socketStopAccepting($flowKey);
                },
                onShutdownStep: static function (string $step): void {
                    self::logServerEvent('sconcur socket server shutdown: ' . $step);
                },
                preemptionQuantumMs: $this->preemptionQuantumMs,
                handlerTimeoutMs: $this->handlerTimeoutMs,
            );
        } finally {
            $restoreSignals();
        }
    }

    /**
     * Runs inside a spawned coroutine for one connection: decode the connection event,
     * hand a Connection to the handler, and always close it afterwards. A handler that
     * throws is reported to $onError (which may write a final frame) and the connection
     * is then closed — one bad connection never takes the server down.
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
        );

        try {
            $handler($connection);
        } catch (FlowStoppedException) {
            // Unwound on purpose — the handler ran past handlerTimeoutMs, or the server is
            // shutting down. Not the handler failing, so onError is not told and the
            // coroutine simply ends; its finally blocks have already run.
            return;
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
