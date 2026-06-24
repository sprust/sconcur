<?php

declare(strict_types=1);

namespace SConcur\Telemetry;

use Closure;
use SConcur\Exceptions\Telemetry\FrameTooLargeException;
use SConcur\Telemetry\Dto\Snapshot;

/**
 * The telemetry collector's ingest side: a non-blocking unix-socket listener that
 * accepts worker connections and reads their length-prefixed JSON snapshot frames
 * into the Store. One connection per worker; the latest snapshot wins; closing a
 * connection evicts the worker (clean dead-worker detection — no files, no liveness
 * probe). It owns no event loop — the TelemetryRuntime selects over its streams and
 * routes readability here.
 */
class Collector
{
    protected const int READ_CHUNK_BYTES = 65_536;

    protected const int MAX_FRAME_BYTES = 1_048_576;

    /**
     * Cap on concurrently accepted worker connections. A pool has one connection per
     * worker (≈ cores), so this is generous headroom; it bounds the listener against a
     * misbehaving local peer opening connections without pushing (the socket is 0600,
     * same-uid only, but the cap keeps the failure mode finite rather than unbounded).
     */
    protected const int MAX_CONNECTIONS = 1_024;

    /** @var null|resource the unix listener, or null until start() */
    protected $listener = null;

    /** @var array<int, resource> connection id (stream id) => accepted stream */
    protected array $connections = [];

    /** @var array<int, string> connection id => unconsumed inbound buffer */
    protected array $buffers = [];

    /**
     * @param null|Closure(string): void $logError
     */
    public function __construct(
        protected readonly string $socketPath,
        protected readonly Store $store,
        protected readonly ?Closure $logError = null,
    ) {
    }

    /**
     * Binds the unix listener (removing a stale socket file first) in non-blocking
     * mode. Returns false on failure — telemetry is optional and must never take the
     * master down.
     */
    public function start(): bool
    {
        if (file_exists($this->socketPath)) {
            @unlink($this->socketPath);
        }

        $listener = @stream_socket_server('unix://' . $this->socketPath, $errno, $errstr);

        if ($listener === false) {
            $this->log(sprintf('telemetry collector bind %s failed: %s', $this->socketPath, $errstr));

            return false;
        }

        stream_set_blocking($listener, false);
        @chmod($this->socketPath, 0o600);

        $this->listener = $listener;

        return true;
    }

    /**
     * Read streams to select over: the listener plus every live connection.
     *
     * @return array<int, resource>
     */
    public function readStreams(): array
    {
        $streams = [];

        if ($this->listener !== null) {
            $streams[(int) $this->listener] = $this->listener;
        }

        foreach ($this->connections as $id => $connection) {
            $streams[$id] = $connection;
        }

        return $streams;
    }

    public function owns(int $streamId): bool
    {
        if ($this->listener !== null && (int) $this->listener === $streamId) {
            return true;
        }

        return isset($this->connections[$streamId]);
    }

    /**
     * @param resource $stream
     */
    public function onReadable($stream): void
    {
        if ($this->listener !== null && (int) $stream === (int) $this->listener) {
            $this->accept();

            return;
        }

        $this->readConnection($stream);
    }

    public function stop(): void
    {
        foreach ($this->connections as $id => $connection) {
            $this->dropConnection($id);
        }

        if ($this->listener !== null) {
            fclose($this->listener);

            $this->listener = null;
        }

        if (file_exists($this->socketPath)) {
            @unlink($this->socketPath);
        }
    }

    protected function accept(): void
    {
        if ($this->listener === null) {
            return;
        }

        $connection = @stream_socket_accept($this->listener, 0);

        if ($connection === false) {
            return;
        }

        if (count($this->connections) >= self::MAX_CONNECTIONS) {
            // At the cap: refuse rather than grow unbounded. A live worker redials on
            // its next interval, so a transient overflow self-heals.
            fclose($connection);

            return;
        }

        stream_set_blocking($connection, false);

        $id = (int) $connection;

        $this->connections[$id] = $connection;
        $this->buffers[$id]     = '';
    }

    /**
     * @param resource $stream
     */
    protected function readConnection($stream): void
    {
        $id    = (int) $stream;
        $chunk = @fread($stream, self::READ_CHUNK_BYTES);

        if ($chunk === false || ($chunk === '' && feof($stream))) {
            $this->dropConnection($id);

            return;
        }

        $this->buffers[$id] .= $chunk;

        try {
            [$frames, $remainder] = FrameCodec::extractFrames($this->buffers[$id], self::MAX_FRAME_BYTES);
        } catch (FrameTooLargeException) {
            // A misbehaving or non-telemetry peer: drop it rather than buffer forever.
            $this->dropConnection($id);

            return;
        }

        $this->buffers[$id] = $remainder;

        foreach ($frames as $frame) {
            $this->ingestFrame($id, $frame);
        }
    }

    protected function ingestFrame(int $id, string $frame): void
    {
        $decoded = json_decode($frame, true);

        if (!is_array($decoded)) {
            return;
        }

        // Honour the frame envelope: only "snapshot" frames carry an "s" snapshot.
        // Unknown future kinds (worker.start/stop, ...) are ignored, not misread.
        if (($decoded['t'] ?? null) !== 'snapshot') {
            return;
        }

        $snapshot = Snapshot::fromDecoded($decoded['s'] ?? null);

        if ($snapshot === null) {
            return;
        }

        $this->store->put($id, $snapshot, $this->nowMs());
    }

    protected function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    protected function dropConnection(int $id): void
    {
        if (isset($this->connections[$id])) {
            fclose($this->connections[$id]);
        }

        unset($this->connections[$id], $this->buffers[$id]);

        $this->store->remove($id);
    }

    protected function log(string $message): void
    {
        if ($this->logError !== null) {
            ($this->logError)($message);
        }
    }
}
