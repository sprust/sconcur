<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

use SConcur\Connection\Extension;
use SConcur\Exceptions\Amqp\ConnectionException;
use SConcur\Features\Amqp\Payloads\ConnectionPayloadParameters;
use SConcur\Features\Amqp\Payloads\ConnectPayload;
use SConcur\Features\Amqp\Payloads\ConnectPayloadParameters;
use SConcur\Features\Amqp\Payloads\DisconnectPayload;
use SConcur\Features\Amqp\Payloads\UsedChannelsPayload;
use SConcur\Features\Amqp\Support\AmqpResource;
use SConcur\Features\Sleeper\Sleeper;
use Throwable;

/**
 * A connection to an AMQP broker. Nothing here touches the network until the first
 * command; the socket itself lives in the Go extension, and this object holds the options
 * plus the handle it got back.
 *
 * Connections are pooled on the Go side by their options, so building one per request is
 * cheap and the connection behind the handle outlives the PHP object.
 *
 * Opening is lazy, as with the Mongodb client: a constructor that dialled the broker would
 * do network work where a caller expects none, and would suspend the coroutine that merely
 * wired its objects together. `connect()` is there for a worker that wants to fail at
 * start-up rather than under load.
 */
class Connection extends AmqpResource
{
    /** How long a coroutine waiting for someone else's connect sleeps between looks. */
    protected const int OPENING_POLL_INTERVAL_US = 1_000;
    public readonly ConnectionOptions $options;

    /**
     * Whether a coroutine is inside connect() right now. Opening is lazy, and connect()
     * suspends, so without this every coroutine that found the connection closed would
     * start a connect of its own: each one overwrites the handle, and the ones before it
     * are never released — the pooled connection behind them stays held for the life of
     * the process and usedChannels() counts only the last.
     */
    protected bool $opening = false;

    /** The values the broker agreed on in the handshake; null until connected. */
    protected ?int $negotiatedChannelMax = null;

    protected ?int $negotiatedFrameMax = null;

    protected ?int $negotiatedHeartbeat = null;

    /**
     * @param ConnectionOptions|string $options the options, or an AMQP URI —
     *                                          `amqp://login:password@host:5672/vhost`
     *
     * @throws ConnectionException if the URI cannot be read or an option is out of range
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
     *
     * @throws ConnectionException if the broker is unreachable or refuses the login
     */
    public function connect(): void
    {
        if ($this->internalOpen) {
            $this->close();
        }

        $tls = $this->options->tls;

        $result = $this->runCommand(
            payload: new ConnectPayload(
                new ConnectPayloadParameters(
                    host: $this->options->host,
                    port: $this->options->port,
                    vhost: $this->options->vhost,
                    login: $this->options->login,
                    password: $this->options->password,
                    connectTimeoutMs: static::toMilliseconds($this->options->connectTimeout),
                    channelMax: $this->options->channelMax,
                    frameMaxBytes: $this->options->frameMax,
                    heartbeatSeconds: $this->options->heartbeat,
                    // Asked for, not inferred from the certificate paths: TLS with the
                    // system trust store names none of them, and a connection that fell
                    // back to plaintext would put the login and password on the wire.
                    secure: $tls !== null,
                    caCertPath: $tls?->caCert,
                    certPath: $tls?->cert,
                    keyPath: $tls?->key,
                    verify: $tls === null || $tls->verify,
                    saslMethod: $this->options->saslMethod->value,
                    connectionName: $this->options->connectionName,
                ),
            ),
            exceptionClass: ConnectionException::class,
        );

        $this->internalId           = isset($result['cid']) ? (string) $result['cid'] : '';
        $this->negotiatedChannelMax = isset($result['mc']) ? (int) $result['mc'] : null;
        $this->negotiatedFrameMax   = isset($result['mf']) ? (int) $result['mf'] : null;
        $this->negotiatedHeartbeat  = isset($result['hb']) ? (int) $result['hb'] : null;
        $this->internalOpen         = true;
    }

    /**
     * Releases the handle. The connection itself stays in the Go-side pool until nothing
     * holds it and its idle time runs out. Idempotent.
     *
     * @throws ConnectionException if the broker could not be told
     */
    public function close(): void
    {
        // Keyed on the handle, not on the open flag: a connection that died still holds
        // one, and the pooled connection behind it is only released when it is handed back.
        if ($this->internalId === '') {
            return;
        }

        $connectionId = $this->internalId;

        $this->internalOpen         = false;
        $this->internalId           = '';
        $this->negotiatedChannelMax = null;
        $this->negotiatedFrameMax   = null;
        $this->negotiatedHeartbeat  = null;

        // Releasing the handle closes the channels on the Go side, so the objects that
        // stand for them must stop claiming to be open — otherwise a reconnect leaves an
        // application holding channels that pass isOpen() and fail every command.
        $this->forgetChannels();

        $this->runCommand(
            payload: new DisconnectPayload(
                new ConnectionPayloadParameters(
                    connectionId: $connectionId,
                    timeoutMs: $this->rpcTimeoutMs(),
                ),
            ),
            exceptionClass: ConnectionException::class,
        );
    }

    /**
     * Opens a channel. Every coroutine needs one of its own: the commands of a channel are
     * serialized, so a shared channel turns concurrent work back into a queue.
     *
     * @param int $prefetchCount how many unacknowledged deliveries the broker may push to
     *                           a consumer on this channel at a time
     * @param int $prefetchSize  the same limit in octets; 0 means no size limit
     *
     * @throws ConnectionException if the broker is unreachable or refuses another channel
     */
    public function channel(int $prefetchCount = Channel::DEFAULT_PREFETCH_COUNT, int $prefetchSize = 0): Channel
    {
        $this->ensureOpen();

        return new Channel(connection: $this, prefetchCount: $prefetchCount, prefetchSize: $prefetchSize);
    }

    /**
     * How many channels this connection currently holds open, counted in the Go-side
     * registry — a channel may also be closed there, by the sweeper that collects the ones
     * an application dropped without closing.
     *
     * @throws ConnectionException if the connection is not open
     */
    public function usedChannels(): int
    {
        if (!$this->internalOpen) {
            throw new ConnectionException(message: 'Could not count the channels. No connection available.');
        }

        $result = $this->runCommand(
            payload: new UsedChannelsPayload(
                new ConnectionPayloadParameters(
                    connectionId: $this->internalId,
                    timeoutMs: $this->rpcTimeoutMs(),
                ),
            ),
            exceptionClass: ConnectionException::class,
        );

        return isset($result['uc']) ? (int) $result['uc'] : 0;
    }

    /**
     * The channel limit: the value the broker agreed on while connected, the requested one
     * otherwise.
     */
    public function maxChannels(): int
    {
        return $this->negotiatedChannelMax ?? $this->options->channelMax;
    }

    /**
     * The frame size limit in bytes: the value the broker agreed on while connected, the
     * requested one otherwise.
     */
    public function maxFrameSize(): int
    {
        return $this->negotiatedFrameMax ?? $this->options->frameMax;
    }

    /**
     * The heartbeat interval in seconds: the value the broker agreed on while connected,
     * the requested one otherwise.
     */
    public function heartbeatInterval(): int
    {
        return $this->negotiatedHeartbeat ?? $this->options->heartbeat;
    }

    /**
     * Opens the connection if it has not been opened yet. This is what makes the
     * constructor free of network work.
     *
     * A connection that died is not reopened here. The handle it still holds is what tells
     * the two apart: a failure keeps it, close() gives it back. Reconnecting silently would
     * hand the caller a connection whose channels and consumers are all gone while their
     * objects still point at them, and would turn a broker that is down into a retry loop
     * nobody asked for — so the caller is told, and reconnects if that is what they want.
     *
     * @throws ConnectionException if the connection died, the broker is unreachable, or it
     *                             refuses the login
     */
    protected function ensureOpen(): void
    {
        // Another coroutine got here first: park until its connect is done and then read
        // the state it left, instead of dialling a second connection beside it.
        //
        // The park is a sleep rather than Scheduler::switch(), which only yields for the
        // coroutines the scheduler itself registered — inside a WaitGroup it answers false
        // and yields nothing, so the guard would let every waiter through. This costs a
        // round trip per poll and only in the contended case: with nobody else opening,
        // the loop body never runs.
        while ($this->opening) {
            Sleeper::usleep(microseconds: self::OPENING_POLL_INTERVAL_US);
        }

        if ($this->internalOpen) {
            return;
        }

        if ($this->internalId !== '') {
            throw new ConnectionException(
                message: 'No connection available. The connection failed; close() and connect() to open a new one.',
            );
        }

        $this->opening = true;

        try {
            $this->connect();
        } finally {
            $this->opening = false;
        }
    }

    /**
     * The RPC deadline every one-shot command of this connection carries, in milliseconds.
     * 0 leaves the Go side to apply its own default.
     */
    protected function rpcTimeoutMs(): int
    {
        return static::toMilliseconds($this->options->rpcTimeout);
    }

    /**
     * A connection an application dropped without closing is released best-effort here, so
     * its handle does not keep a pooled connection alive on the Go side.
     *
     * Detached, for the same reason Channel's destructor is: there is nothing left to
     * await, and the coroutine this ran in may already be gone.
     */
    public function __destruct()
    {
        if ($this->internalId === '') {
            return;
        }

        $connectionId = $this->internalId;

        $this->internalOpen = false;
        $this->internalId   = '';

        try {
            Extension::get()->push(
                flowKey: '',
                payload: new DisconnectPayload(
                    new ConnectionPayloadParameters(
                        connectionId: $connectionId,
                        timeoutMs: $this->rpcTimeoutMs(),
                    ),
                ),
            );
        } catch (Throwable) {
            // Shutdown, a released extension, a connection the Go side has already
            // dropped — nothing here is worth failing a destructor over.
        }
    }
}
