<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

use SConcur\Exceptions\Amqp\ConnectionException;
use SConcur\Features\Amqp\Support\AmqpResource;

/**
 * A connection to an AMQP broker: the options plus the handle the Go side gave back for
 * them. The socket lives there, pooled by those options, so building one per request is
 * cheap and the connection outlives this object.
 *
 * Opening is lazy — a constructor that dialled would do network work where a caller expects
 * none, and suspend the coroutine that merely wired its objects together. `connect()` is
 * for a worker that would rather fail at start-up than under load.
 */
class Connection extends AmqpResource
{
    public readonly ConnectionOptions $options;

    /** What the broker agreed on in the handshake; null until connected. */
    protected ?int $negotiatedChannelMax = null;

    protected ?int $negotiatedFrameMaxBytes = null;

    protected ?int $negotiatedHeartbeatSeconds = null;

    /**
     * @param ConnectionOptions|string $options the options, or an AMQP URI —
     *                                          `amqp://login:password@host:5672/vhost`
     */
    public function __construct(ConnectionOptions|string $options = new ConnectionOptions())
    {
        $this->options = is_string($options) ? ConnectionOptions::fromDsn($options) : $options;
    }

    /**
     * Whether this object holds an open connection. It reports what happened on this side
     * and does not probe the broker.
     */
    public function isOpen(): bool
    {
        return $this->internalOpen;
    }

    /**
     * Opens the connection: the Go side dials the broker, or hands out a connection
     * already open for the same options. A connection already open here is closed first.
     */
    public function connect(): void
    {
        // Keyed on the handle, not the open flag: a connection that died is closed here and
        // still holds one. Overwriting it would strand the pooled connection behind it and
        // leave that connection's channels reporting themselves open; close() tells them.
        if ($this->internalId !== '') {
            $this->close();
        }

        $tls = $this->options->tls;

        $result = $this->runCommand(
            command: AmqpCommandEnum::Connect,
            data: [
                'ho' => $this->options->host,
                'po' => $this->options->port,
                'vh' => $this->options->vhost,
                'lg' => $this->options->login,
                'pw' => $this->options->password,
                'ct' => static::toMilliseconds($this->options->connectTimeoutSeconds),
                'cx' => $this->options->channelMax,
                'fx' => $this->options->frameMaxBytes,
                'hb' => $this->options->heartbeatSeconds,
                // Asked for, not inferred from the certificate paths: TLS with the system
                // trust store names none, and a fallback to plaintext would put the login
                // and password on the wire.
                'sc' => $tls !== null,
                'ca' => $tls?->caCert,
                'ce' => $tls?->cert,
                'ke' => $tls?->key,
                'vf' => $tls === null || $tls->verify,
                'sm' => $this->options->saslMethod->value,
                'cn' => $this->options->connectionName,
            ],
            exceptionClass: ConnectionException::class,
        );

        $connectionId = isset($result['cid']) ? (string) $result['cid'] : '';

        // Dialling suspends, so another coroutine may have opened this connection while
        // this call was in flight. Both answers name the same socket — the Go side pools by
        // options — so the one that arrived second gives its handle straight back instead
        // of overwriting a handle nothing would ever release.
        if ($this->internalId !== '' && $this->internalId !== $connectionId) {
            $this->disconnect($connectionId);

            return;
        }

        $this->internalId                 = $connectionId;
        $this->negotiatedChannelMax       = isset($result['mc']) ? (int) $result['mc'] : null;
        $this->negotiatedFrameMaxBytes    = isset($result['mf']) ? (int) $result['mf'] : null;
        $this->negotiatedHeartbeatSeconds = isset($result['hb']) ? (int) $result['hb'] : null;
        $this->internalOpen               = true;
    }

    /**
     * Releases the handle; the connection stays pooled until nothing holds it and its idle
     * time runs out. Idempotent.
     */
    public function close(): void
    {
        // Keyed on the handle: a connection that died still holds one, and the pooled
        // connection behind it is only released when it is handed back.
        if ($this->internalId === '') {
            return;
        }

        $connectionId = $this->internalId;

        $this->internalOpen               = false;
        $this->internalId                 = '';
        $this->negotiatedChannelMax       = null;
        $this->negotiatedFrameMaxBytes    = null;
        $this->negotiatedHeartbeatSeconds = null;

        // Releasing the handle closes the channels on the Go side, so a reconnect must not
        // leave an application holding ones that still pass isOpen().
        $this->forgetChannels();

        $this->disconnect($connectionId);
    }

    /**
     * Every coroutine needs a channel of its own: the commands of one are serialized, so a
     * shared channel turns concurrent work back into a queue.
     *
     * @param int $prefetchCount     how many unacknowledged deliveries the broker may push
     *                               to a consumer on this channel at a time
     * @param int $prefetchSizeBytes the same limit in octets; 0 means no size limit
     */
    public function channel(int $prefetchCount = Channel::DEFAULT_PREFETCH_COUNT, int $prefetchSizeBytes = 0): Channel
    {
        $this->ensureOpen();

        static::assertPrefetch(
            count: $prefetchCount,
            sizeBytes: $prefetchSizeBytes,
        );

        $result = $this->runCommand(
            command: AmqpCommandEnum::ChannelOpen,
            data: [
                'cid' => $this->internalId,
                'sz'  => $prefetchSizeBytes,
                'ct'  => $prefetchCount,
                'to'  => $this->rpcTimeoutMs(),
            ],
            exceptionClass: ConnectionException::class,
        );

        return new Channel(
            connection: $this,
            channelId: isset($result['chid']) ? (string) $result['chid'] : '',
            channelNumber: isset($result['no']) ? (int) $result['no'] : 0,
        );
    }

    /**
     * How many channels are open, counted in the Go-side registry — where the sweeper may
     * also have closed ones an application dropped without closing.
     */
    public function usedChannels(): int
    {
        if (!$this->internalOpen) {
            throw new ConnectionException(message: 'Could not count the channels. No connection available.');
        }

        $result = $this->runCommand(
            command: AmqpCommandEnum::UsedChannels,
            data: [
                'cid' => $this->internalId,
                'to'  => $this->rpcTimeoutMs(),
            ],
            exceptionClass: ConnectionException::class,
        );

        return isset($result['uc']) ? (int) $result['uc'] : 0;
    }

    /**
     * The handle the Go side answers to for this connection.
     *
     * @internal what a supervised consumer opens its delivery stream on — that stream is
     *           pushed with a flow key of its own, so it cannot go through the executor the
     *           ordinary commands use.
     */
    public function connectionId(): string
    {
        return $this->internalId;
    }

    /** What the broker agreed on while connected, the requested value otherwise. */
    public function maxChannels(): int
    {
        return $this->negotiatedChannelMax ?? $this->options->channelMax;
    }

    /** What the broker agreed on while connected, the requested value otherwise. */
    public function maxFrameSizeBytes(): int
    {
        return $this->negotiatedFrameMaxBytes ?? $this->options->frameMaxBytes;
    }

    /** What the broker agreed on while connected, the requested value otherwise. */
    public function heartbeatIntervalSeconds(): int
    {
        return $this->negotiatedHeartbeatSeconds ?? $this->options->heartbeatSeconds;
    }

    /**
     * The deadline every one-shot command carries, in milliseconds; 0 leaves the Go side to
     * apply its own default.
     *
     * @internal the one place the channels, queues and exchanges of this connection read it
     *           from — a copy per class is a place for it to drift.
     */
    public function rpcTimeoutMs(): int
    {
        return static::toMilliseconds($this->options->rpcTimeoutSeconds);
    }

    /** @internal see rpcTimeoutMs() */
    public function writeTimeoutMs(): int
    {
        return static::toMilliseconds($this->options->writeTimeoutSeconds);
    }

    /** @internal 0 waits indefinitely; see rpcTimeoutMs() */
    public function readTimeoutMs(): int
    {
        return static::toMilliseconds($this->options->readTimeoutSeconds);
    }

    protected function ownConnection(): Connection
    {
        return $this;
    }

    /** Hands one handle back to the Go side. */
    protected function disconnect(string $connectionId): void
    {
        $this->runCommand(
            command: AmqpCommandEnum::Disconnect,
            data: [
                'cid' => $connectionId,
                'to'  => $this->rpcTimeoutMs(),
            ],
            exceptionClass: ConnectionException::class,
        );
    }

    /**
     * Opens the connection on first use — which is what keeps the constructor free of
     * network work.
     *
     * Several coroutines finding it closed at once all dial, and that is fine: connect()
     * keeps the first handle to arrive and gives the others back, so nothing has to be
     * locked here.
     *
     * A connection that died is not reopened here; the handle it still holds tells the two
     * apart. Reconnecting silently would hand back a connection whose channels and
     * consumers are gone while their objects still point at them, and would turn a broker
     * that is down into a retry loop nobody asked for.
     */
    protected function ensureOpen(): void
    {
        if ($this->internalOpen) {
            return;
        }

        if ($this->internalId !== '') {
            throw new ConnectionException(
                message: 'No connection available. The connection failed; close() and connect() to open a new one.',
            );
        }

        $this->connect();
    }

    /**
     * Releases the handle best-effort, so a dropped connection does not keep a pooled one
     * alive. Detached: a destructor has nothing to await on.
     */
    public function __destruct()
    {
        if ($this->internalId === '') {
            return;
        }

        $connectionId = $this->internalId;

        $this->internalOpen = false;
        $this->internalId   = '';

        $this->pushDetached(
            command: AmqpCommandEnum::Disconnect,
            data: [
                'cid' => $connectionId,
                'to'  => $this->rpcTimeoutMs(),
            ],
        );
    }
}
