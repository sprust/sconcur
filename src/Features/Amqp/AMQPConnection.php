<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

use SConcur\Connection\Extension;
use SConcur\Features\Amqp\Payloads\ConnectionPayloadParameters;
use SConcur\Features\Amqp\Payloads\ConnectPayload;
use SConcur\Features\Amqp\Payloads\ConnectPayloadParameters;
use SConcur\Features\Amqp\Payloads\DisconnectPayload;
use SConcur\Features\Amqp\Payloads\UsedChannelsPayload;
use SConcur\Features\Amqp\Support\AmqpResource;
use Throwable;

/**
 * A connection to an AMQP broker — the calque of ext-amqp's AMQPConnection. Nothing here
 * touches the network until connect() is called; the socket itself lives in the Go
 * extension, and this object holds the credentials plus the handle it got back.
 *
 * Connections are pooled on the Go side by their credentials, so building an
 * AMQPConnection per request is cheap and every connection is persistent by nature:
 * pconnect() is connect(), and isPersistent() is always true (see docs/amqp.md).
 */
class AMQPConnection extends AmqpResource
{
    /** The longest login or password the protocol accepts. */
    protected const int MAX_CREDENTIAL_LENGTH = 1024;

    /** The longest host or vhost the protocol accepts. */
    protected const int MAX_IDENTIFIER_LENGTH = 512;

    protected const int MIN_PORT = 1;

    protected const int MAX_PORT = 65535;

    /** The frame size ext-amqp asks for when the credentials name none. */
    protected const int DEFAULT_FRAME_MAX_BYTES = 131072;

    protected string $login = 'guest';

    protected string $password = 'guest';

    protected string $host = 'localhost';

    protected string $vhost = '/';

    protected int $port = 5672;

    protected float $readTimeout = 0.0;

    protected float $writeTimeout = 0.0;

    protected float $connectTimeout = 0.0;

    protected float $rpcTimeout = 0.0;

    protected int $channelMax = PHP_AMQP_MAX_CHANNELS;

    protected int $frameMax = self::DEFAULT_FRAME_MAX_BYTES;

    protected int $heartbeat = 0;

    protected ?string $cacert = null;

    protected ?string $key = null;

    protected ?string $cert = null;

    protected bool $verify = true;

    protected int $saslMethod = AMQP_SASL_METHOD_PLAIN;

    protected ?string $connectionName = null;

    /** The values the broker agreed on in the handshake; null until connected. */
    protected ?int $negotiatedChannelMax = null;

    protected ?int $negotiatedFrameMax = null;

    protected ?int $negotiatedHeartbeat = null;

    /**
     * @param array<string, mixed> $credentials host, port, vhost, login, password, the
     *                                          read_timeout / write_timeout /
     *                                          connect_timeout / rpc_timeout deadlines in
     *                                          seconds, the channel_max / frame_max /
     *                                          heartbeat tuning, the cacert / cert / key /
     *                                          verify TLS material, sasl_method and
     *                                          connection_name — the keys ext-amqp accepts
     *
     * @throws AMQPConnectionException on a credential outside the range the protocol allows
     */
    public function __construct(array $credentials = [])
    {
        // An empty string means "not given" here, exactly as in the extension: a blank
        // environment variable leaves the default in place instead of connecting nowhere.
        if (static::given($credentials, 'host')) {
            $this->setHost((string) $credentials['host']);
        }

        if (isset($credentials['port'])) {
            $this->setPort((int) $credentials['port']);
        }

        if (static::given($credentials, 'vhost')) {
            $this->setVhost((string) $credentials['vhost']);
        }

        if (static::given($credentials, 'login')) {
            $this->setLogin((string) $credentials['login']);
        }

        if (static::given($credentials, 'password')) {
            $this->setPassword((string) $credentials['password']);
        }

        if (isset($credentials['timeout'])) {
            $this->setReadTimeout((float) $credentials['timeout']);
        }

        if (isset($credentials['read_timeout'])) {
            $this->setReadTimeout((float) $credentials['read_timeout']);
        }

        if (isset($credentials['write_timeout'])) {
            $this->setWriteTimeout((float) $credentials['write_timeout']);
        }

        if (isset($credentials['connect_timeout'])) {
            $this->setConnectTimeout((float) $credentials['connect_timeout']);
        }

        if (isset($credentials['rpc_timeout'])) {
            $this->setRpcTimeout((float) $credentials['rpc_timeout']);
        }

        if (isset($credentials['channel_max'])) {
            $this->setChannelMax((int) $credentials['channel_max']);
        }

        if (isset($credentials['frame_max'])) {
            $this->setFrameMax((int) $credentials['frame_max']);
        }

        if (isset($credentials['heartbeat'])) {
            $this->setHeartbeat((int) $credentials['heartbeat']);
        }

        if (isset($credentials['cacert'])) {
            $this->setCACert((string) $credentials['cacert']);
        }

        if (isset($credentials['cert'])) {
            $this->setCert((string) $credentials['cert']);
        }

        if (isset($credentials['key'])) {
            $this->setKey((string) $credentials['key']);
        }

        if (isset($credentials['verify'])) {
            $this->setVerify((bool) $credentials['verify']);
        }

        if (isset($credentials['sasl_method'])) {
            $this->setSaslMethod((int) $credentials['sasl_method']);
        }

        if (static::given($credentials, 'connection_name')) {
            $this->setConnectionName((string) $credentials['connection_name']);
        }
    }

    /**
     * Whether this object holds an open connection. Like the extension, it reports what
     * happened on this side and does not probe the broker.
     */
    public function isConnected(): bool
    {
        return $this->internalOpen;
    }

    /**
     * Always true: the connection behind the handle lives in the Go-side pool, which
     * outlives the PHP object by design (see docs/amqp.md).
     */
    public function isPersistent(): bool
    {
        return true;
    }

    /**
     * Opens the connection: the Go side dials the broker, or hands out a connection
     * already open for the same credentials. Reconnects if this object is connected
     * already.
     *
     * @throws AMQPConnectionException if the broker is unreachable or refuses the login
     */
    public function connect(): void
    {
        if ($this->internalOpen) {
            $this->disconnect();
        }

        $result = $this->runCommand(
            payload: new ConnectPayload(
                new ConnectPayloadParameters(
                    host: $this->host,
                    port: $this->port,
                    vhost: $this->vhost,
                    login: $this->login,
                    password: $this->password,
                    connectTimeoutMs: static::toMilliseconds($this->connectTimeout),
                    channelMax: $this->channelMax,
                    frameMaxBytes: $this->frameMax,
                    heartbeatSeconds: $this->heartbeat,
                    caCertPath: $this->cacert,
                    certPath: $this->cert,
                    keyPath: $this->key,
                    verify: $this->verify,
                    saslMethod: $this->saslMethod,
                    connectionName: $this->connectionName,
                ),
            ),
            exceptionClass: AMQPConnectionException::class,
        );

        $this->internalId           = isset($result['cid']) ? (string) $result['cid'] : '';
        $this->negotiatedChannelMax = isset($result['mc']) ? (int) $result['mc'] : null;
        $this->negotiatedFrameMax   = isset($result['mf']) ? (int) $result['mf'] : null;
        $this->negotiatedHeartbeat  = isset($result['hb']) ? (int) $result['hb'] : null;
        $this->internalOpen         = true;
    }

    /**
     * Releases the handle. The connection itself stays in the Go-side pool until nothing
     * holds it and its idle time runs out.
     */
    public function disconnect(): void
    {
        // Keyed on the handle, not on the connected flag: a connection that died still
        // holds one, and the pooled connection behind it is only released when it is
        // handed back.
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
        // stand for them must stop claiming to be open — otherwise a reconnect() leaves
        // an application holding channels that pass isConnected() and fail every command.
        $this->forgetChannels();

        $this->runCommand(
            payload: new DisconnectPayload(
                new ConnectionPayloadParameters(
                    connectionId: $connectionId,
                    timeoutMs: $this->rpcTimeoutMs(),
                ),
            ),
            exceptionClass: AMQPConnectionException::class,
        );
    }

    /**
     * @throws AMQPConnectionException if the broker is unreachable
     */
    public function reconnect(): void
    {
        $this->disconnect();
        $this->connect();
    }

    /**
     * A synonym of connect(): persistent connections are a php-fpm notion, and every
     * connection the Go side hands out already outlives the PHP object.
     *
     * @throws AMQPConnectionException if the broker is unreachable or refuses the login
     */
    public function pconnect(): void
    {
        $this->connect();
    }

    /** A synonym of disconnect(). */
    public function pdisconnect(): void
    {
        $this->disconnect();
    }

    /**
     * A synonym of reconnect().
     *
     * @throws AMQPConnectionException if the broker is unreachable
     */
    public function preconnect(): void
    {
        $this->reconnect();
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getLogin(): string
    {
        return $this->login;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getVhost(): string
    {
        return $this->vhost;
    }

    /**
     * @throws AMQPConnectionException if the host is longer than the protocol allows
     */
    public function setHost(string $host): void
    {
        if (strlen($host) > self::MAX_IDENTIFIER_LENGTH) {
            throw new AMQPConnectionException(
                message: "Parameter 'host' exceeds " . self::MAX_IDENTIFIER_LENGTH . ' character limit.',
            );
        }

        $this->host = $host;
    }

    /**
     * @throws AMQPConnectionException if the login is longer than the protocol allows
     */
    public function setLogin(string $login): void
    {
        if (strlen($login) > self::MAX_CREDENTIAL_LENGTH) {
            throw new AMQPConnectionException(
                message: "Parameter 'login' exceeds " . self::MAX_CREDENTIAL_LENGTH . ' character limit.',
            );
        }

        $this->login = $login;
    }

    /**
     * @throws AMQPConnectionException if the password is longer than the protocol allows
     */
    public function setPassword(string $password): void
    {
        if (strlen($password) > self::MAX_CREDENTIAL_LENGTH) {
            throw new AMQPConnectionException(
                message: "Parameter 'password' exceeds " . self::MAX_CREDENTIAL_LENGTH . ' character limit.',
            );
        }

        $this->password = $password;
    }

    /**
     * @throws AMQPConnectionException if the port is outside 1..65535
     */
    public function setPort(int $port): void
    {
        if ($port < self::MIN_PORT || $port > self::MAX_PORT) {
            throw new AMQPConnectionException(
                message: "Parameter 'port' must be a valid port number between "
                    . self::MIN_PORT . ' and ' . self::MAX_PORT . '.',
            );
        }

        $this->port = $port;
    }

    /**
     * @throws AMQPConnectionException if the vhost is longer than the protocol allows
     */
    public function setVhost(string $vhost): void
    {
        if (strlen($vhost) > self::MAX_IDENTIFIER_LENGTH) {
            throw new AMQPConnectionException(
                message: "Parameter 'vhost' exceeds " . self::MAX_IDENTIFIER_LENGTH . ' character limit.',
            );
        }

        $this->vhost = $vhost;
    }

    /**
     * @deprecated use AMQPConnection::setReadTimeout() instead
     *
     * @throws AMQPConnectionException if the timeout is negative
     */
    public function setTimeout(float $timeout): void
    {
        trigger_error(
            'AMQPConnection::setTimeout() is deprecated; use AMQPConnection::setReadTimeout() instead',
            E_USER_DEPRECATED,
        );

        $this->setReadTimeout($timeout);
    }

    /**
     * @deprecated use AMQPConnection::getReadTimeout() instead
     */
    public function getTimeout(): float
    {
        trigger_error(
            'AMQPConnection::getTimeout() is deprecated; use AMQPConnection::getReadTimeout() instead',
            E_USER_DEPRECATED,
        );

        return $this->readTimeout;
    }

    /**
     * The time a consumer waits for the next delivery before it gives up; 0 waits
     * forever.
     *
     * @throws AMQPConnectionException if the timeout is negative
     */
    public function setReadTimeout(float $timeout): void
    {
        $this->assertTimeout(name: 'read_timeout', timeout: $timeout);

        $this->readTimeout = $timeout;
    }

    public function getReadTimeout(): float
    {
        return $this->readTimeout;
    }

    /**
     * The time one publish may take.
     *
     * @throws AMQPConnectionException if the timeout is negative
     */
    public function setWriteTimeout(float $timeout): void
    {
        $this->assertTimeout(name: 'write_timeout', timeout: $timeout);

        $this->writeTimeout = $timeout;
    }

    public function getWriteTimeout(): float
    {
        return $this->writeTimeout;
    }

    public function getConnectTimeout(): float
    {
        return $this->connectTimeout;
    }

    /**
     * The time one broker method (declare, bind, get, ack, …) may take.
     *
     * @throws AMQPConnectionException if the timeout is negative
     */
    public function setRpcTimeout(float $timeout): void
    {
        $this->assertTimeout(name: 'rpc_timeout', timeout: $timeout);

        $this->rpcTimeout = $timeout;
    }

    public function getRpcTimeout(): float
    {
        return $this->rpcTimeout;
    }

    /**
     * How many channels this connection currently holds open, counted in the Go-side
     * registry — a channel may also be closed there, by the sweeper that collects the
     * ones an application dropped without closing.
     */
    public function getUsedChannels(): int
    {
        if (!$this->internalOpen) {
            trigger_error('AMQPConnection::getUsedChannels(): Connection is not connected.', E_USER_WARNING);

            return 0;
        }

        $result = $this->runCommand(
            payload: new UsedChannelsPayload(
                new ConnectionPayloadParameters(
                    connectionId: $this->internalId,
                    timeoutMs: $this->rpcTimeoutMs(),
                ),
            ),
            exceptionClass: AMQPConnectionException::class,
        );

        return isset($result['uc']) ? (int) $result['uc'] : 0;
    }

    /**
     * The channel limit: the value the broker agreed on while connected, the requested
     * one otherwise.
     */
    public function getMaxChannels(): int
    {
        return $this->negotiatedChannelMax ?? $this->channelMax;
    }

    /**
     * The frame size limit in bytes: the value the broker agreed on while connected, the
     * requested one otherwise.
     */
    public function getMaxFrameSize(): int
    {
        return $this->negotiatedFrameMax ?? $this->frameMax;
    }

    /**
     * The heartbeat interval in seconds: the value the broker agreed on while connected,
     * the requested one otherwise.
     */
    public function getHeartbeatInterval(): int
    {
        return $this->negotiatedHeartbeat ?? $this->heartbeat;
    }

    public function getCACert(): ?string
    {
        return $this->cacert;
    }

    public function setCACert(?string $cacert): void
    {
        $this->cacert = $cacert;
    }

    public function getCert(): ?string
    {
        return $this->cert;
    }

    public function setCert(?string $cert): void
    {
        $this->cert = $cert;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function setKey(?string $key): void
    {
        $this->key = $key;
    }

    public function getVerify(): bool
    {
        return $this->verify;
    }

    public function setVerify(bool $verify): void
    {
        $this->verify = $verify;
    }

    /**
     * @throws AMQPConnectionException if the method is neither PLAIN nor EXTERNAL
     */
    public function setSaslMethod(int $saslMethod): void
    {
        if ($saslMethod !== AMQP_SASL_METHOD_PLAIN && $saslMethod !== AMQP_SASL_METHOD_EXTERNAL) {
            throw new AMQPConnectionException(
                message: 'Invalid SASL method given. Method must be AMQP_SASL_METHOD_PLAIN'
                    . ' or AMQP_SASL_METHOD_EXTERNAL.',
            );
        }

        $this->saslMethod = $saslMethod;
    }

    public function getSaslMethod(): int
    {
        return $this->saslMethod;
    }

    public function setConnectionName(?string $connectionName): void
    {
        $this->connectionName = $connectionName;
    }

    public function getConnectionName(): ?string
    {
        return $this->connectionName;
    }

    /**
     * Whether a credential was actually given: present and not an empty string.
     *
     * @param array<string, mixed> $credentials
     */
    protected static function given(array $credentials, string $key): bool
    {
        return isset($credentials[$key]) && (string) $credentials[$key] !== '';
    }

    /**
     * The RPC deadline every one-shot command of this connection carries, in
     * milliseconds. 0 leaves the Go side to apply its own default.
     */
    protected function rpcTimeoutMs(): int
    {
        return static::toMilliseconds($this->rpcTimeout);
    }

    /**
     * @throws AMQPConnectionException if the timeout is negative
     */
    protected function assertTimeout(string $name, float $timeout): void
    {
        if ($timeout < 0) {
            throw new AMQPConnectionException(
                message: "Parameter '$name' must be greater than or equal to zero.",
            );
        }
    }

    /**
     * @throws AMQPConnectionException if the timeout is negative
     */
    protected function setConnectTimeout(float $timeout): void
    {
        $this->assertTimeout(name: 'connect_timeout', timeout: $timeout);

        $this->connectTimeout = $timeout;
    }

    /**
     * @throws AMQPConnectionException if the limit is outside 1..PHP_AMQP_MAX_CHANNELS
     */
    protected function setChannelMax(int $channelMax): void
    {
        if ($channelMax < 1 || $channelMax > PHP_AMQP_MAX_CHANNELS) {
            throw new AMQPConnectionException(
                message: "Parameter 'channel_max' is out of range.",
            );
        }

        $this->channelMax = $channelMax;
    }

    /**
     * @throws AMQPConnectionException if the frame size is not positive
     */
    protected function setFrameMax(int $frameMaxBytes): void
    {
        if ($frameMaxBytes < 1) {
            throw new AMQPConnectionException(
                message: "Parameter 'frame_max' is out of range.",
            );
        }

        $this->frameMax = $frameMaxBytes;
    }

    /**
     * @throws AMQPConnectionException if the interval is negative
     */
    protected function setHeartbeat(int $heartbeatSeconds): void
    {
        if ($heartbeatSeconds < 0) {
            throw new AMQPConnectionException(
                message: "Parameter 'heartbeat' is out of range.",
            );
        }

        $this->heartbeat = $heartbeatSeconds;
    }

    /**
     * A connection an application dropped without disconnecting is released best-effort
     * here, so its handle does not keep a pooled connection alive on the Go side — the
     * extension frees its own connection resource the same way.
     *
     * Detached, for the same reason AMQPChannel's destructor is: there is nothing left to
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

        $this->forgetChannels();

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
            // The extension is already gone (the process is shutting down), and with it
            // every connection it held.
        }
    }
}
