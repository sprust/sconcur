<?php

declare(strict_types=1);

namespace SConcur\Telemetry;

use Closure;

/**
 * The telemetry control plane embedded in the worker master: a unix-socket collector
 * (workers push snapshots in) and an HTTP/SSE panel (operators read the pool out),
 * sharing one in-memory Store. It owns no thread — the master drives it by calling
 * poll() once per supervision tick, in place of the old blind usleep. poll() selects
 * over all telemetry sockets with the tick as its timeout, so supervision keeps its
 * cadence and is never blocked on telemetry I/O.
 *
 * Telemetry is optional: if either listener fails to bind, the runtime disables
 * itself (poll() falls back to a plain sleep) and the master runs on unaffected.
 */
class TelemetryRuntime
{
    protected const int SSE_PUSH_INTERVAL_MS = 1_000;

    protected Store $store;

    protected Collector $collector;

    protected PanelServer $panel;

    protected MasterMetrics $masterMetrics;

    protected bool $enabled = false;

    protected int $lastSsePushMs = 0;

    /**
     * @param int                        $masterStartedAtMs the master's serve start (epoch ms),
     *                                                      reported as the master's start datetime
     * @param null|Closure(string): void $logError
     */
    public function __construct(
        string $socketPath,
        int $panelPort,
        string $adminToken,
        string $name,
        int $masterStartedAtMs = 0,
        ?Closure $logError = null,
    ) {
        $this->store         = new Store();
        $this->masterMetrics = new MasterMetrics($masterStartedAtMs);
        $this->collector     = new Collector(
            socketPath: $socketPath,
            store: $this->store,
            logError: $logError,
        );
        $this->panel = new PanelServer(
            port: $panelPort,
            token: $adminToken,
            name: $name,
            store: $this->store,
            aggregator: new Aggregator(),
            masterMetrics: $this->masterMetrics,
            logError: $logError,
        );
    }

    /**
     * Binds both listeners. If either fails the runtime stays disabled (and both are
     * torn down) so the master is never left with a half-open telemetry plane.
     */
    public function start(): void
    {
        $collectorBound = $this->collector->start();
        $panelBound     = $this->panel->start();

        if (!$collectorBound || !$panelBound) {
            $this->collector->stop();
            $this->panel->stop();

            return;
        }

        $this->enabled = true;
    }

    /**
     * Services telemetry I/O for up to $timeoutMicros, then returns so the master can
     * run its supervision pass. When disabled this is just the tick sleep, so the
     * master loop cadence is identical to before.
     */
    public function poll(int $timeoutMicros): void
    {
        if (!$this->enabled) {
            usleep($timeoutMicros);

            return;
        }

        $nowMs = (int) (microtime(true) * 1000);

        if ($nowMs - $this->lastSsePushMs >= self::SSE_PUSH_INTERVAL_MS) {
            // Refresh the master's rolling CPU% on the same 1s cadence (one consistent
            // interval), then emit the SSE tick carrying the fresh master section.
            $this->masterMetrics->sample($nowMs / 1000);

            $this->panel->pushSse($nowMs);

            $this->lastSsePushMs = $nowMs;
        }

        $read  = $this->collector->readStreams() + $this->panel->readStreams();
        $write = $this->panel->writeStreams();

        if ($read === [] && $write === []) {
            usleep($timeoutMicros);

            return;
        }

        $except  = [];
        $seconds = intdiv($timeoutMicros, 1_000_000);
        $micros  = $timeoutMicros % 1_000_000;
        $ready   = @stream_select($read, $write, $except, $seconds, $micros);

        if ($ready === false || $ready === 0) {
            return;
        }

        foreach ($read as $stream) {
            $streamId = (int) $stream;

            if ($this->collector->owns($streamId)) {
                $this->collector->onReadable($stream);
            } elseif ($this->panel->owns($streamId)) {
                $this->panel->onReadable($stream);
            }
        }

        foreach ($write as $stream) {
            if ($this->panel->owns((int) $stream)) {
                $this->panel->onWritable($stream);
            }
        }
    }

    public function stop(): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->panel->stop();
        $this->collector->stop();

        $this->enabled = false;
    }
}
