<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

use SConcur\Exceptions\Amqp\InvalidConnectionOptionException;

/**
 * Everything a Connection needs to reach a broker, settled once and never changed after: a
 * connection cannot have its host changed underneath the channels already open on it.
 *
 * The timeouts are seconds because that is the unit an AMQP URI and every broker's own
 * documentation state them in; the wire carries milliseconds, and the conversion happens
 * on the way out (Connection::rpcTimeoutMs and its neighbours).
 */
readonly class ConnectionOptions
{
    /**
     * Channels one connection may hold open. The protocol's own ceiling is 65535 (channel-max
     * is a 16-bit short); this is the lower one ext-amqp settled on, kept because 256
     * channels on one socket is already past the point where a second connection is better.
     */
    public const int MAX_CHANNELS = 256;

    /** The frame size asked for when none is named. */
    public const int DEFAULT_FRAME_MAX_BYTES = 131072;

    /** The longest login or password the protocol accepts. */
    protected const int MAX_CREDENTIAL_LENGTH = 1024;

    /** The longest host or vhost the protocol accepts. */
    protected const int MAX_IDENTIFIER_LENGTH = 512;

    protected const int MIN_PORT = 1;

    protected const int MAX_PORT = 65535;

    /**
     * @param float $connectTimeoutSeconds how long to wait for the broker to answer the dial;
     *                                     0 leaves the Go side to apply its own default
     * @param float $readTimeoutSeconds    how long a consumer waits for a delivery before the
     *                                     wait fails; 0 waits forever
     * @param float $writeTimeoutSeconds   how long a publish may take
     * @param float $rpcTimeoutSeconds     how long any other single broker method may take
     * @param int   $heartbeatSeconds      the heartbeat interval; 0 lets the broker choose
     * @param int   $frameMaxBytes         the largest frame the connection will send or accept
     */
    public function __construct(
        public string $host = 'localhost',
        public int $port = 5672,
        public string $login = 'guest',
        public string $password = 'guest',
        public string $vhost = '/',
        public float $connectTimeoutSeconds = 0.0,
        public float $readTimeoutSeconds = 0.0,
        public float $writeTimeoutSeconds = 0.0,
        public float $rpcTimeoutSeconds = 0.0,
        public int $heartbeatSeconds = 0,
        public int $channelMax = self::MAX_CHANNELS,
        public int $frameMaxBytes = self::DEFAULT_FRAME_MAX_BYTES,
        public ?TlsOptions $tls = null,
        public SaslMethodEnum $saslMethod = SaslMethodEnum::Plain,
        public ?string $connectionName = null,
    ) {
        static::assertLength(
            name: 'host',
            value: $host,
            limit: self::MAX_IDENTIFIER_LENGTH,
        );
        static::assertLength(
            name: 'vhost',
            value: $vhost,
            limit: self::MAX_IDENTIFIER_LENGTH,
        );
        static::assertLength(
            name: 'login',
            value: $login,
            limit: self::MAX_CREDENTIAL_LENGTH,
        );
        static::assertLength(
            name: 'password',
            value: $password,
            limit: self::MAX_CREDENTIAL_LENGTH,
        );

        if ($port < self::MIN_PORT || $port > self::MAX_PORT) {
            throw new InvalidConnectionOptionException(
                message: "Parameter 'port' must be between " . self::MIN_PORT . ' and ' . self::MAX_PORT . '.',
            );
        }

        static::assertNotNegative(
            name: 'connectTimeoutSeconds',
            value: $connectTimeoutSeconds,
        );
        static::assertNotNegative(
            name: 'readTimeoutSeconds',
            value: $readTimeoutSeconds,
        );
        static::assertNotNegative(
            name: 'writeTimeoutSeconds',
            value: $writeTimeoutSeconds,
        );
        static::assertNotNegative(
            name: 'rpcTimeoutSeconds',
            value: $rpcTimeoutSeconds,
        );

        if ($heartbeatSeconds < 0) {
            throw new InvalidConnectionOptionException(message: "Parameter 'heartbeatSeconds' must not be negative.");
        }

        if ($channelMax < 1 || $channelMax > self::MAX_CHANNELS) {
            throw new InvalidConnectionOptionException(
                message: "Parameter 'channelMax' must be between 1 and " . self::MAX_CHANNELS . '.',
            );
        }

        if ($frameMaxBytes < 1) {
            throw new InvalidConnectionOptionException(message: "Parameter 'frameMaxBytes' must be positive.");
        }

        if ($saslMethod === SaslMethodEnum::External && $tls?->cert === null) {
            throw new InvalidConnectionOptionException(
                message: 'SASL EXTERNAL authenticates with a client certificate, so TLS options naming'
                    . ' a cert and a key are required.',
            );
        }
    }

    /**
     * The same options under another connection name — which is what asks the Go-side pool
     * for a socket of its own, since the name is part of its key.
     *
     * The one thing a caller may vary after the fact: everything else settles what the
     * broker agreed on for the channels already open, while the name only tells two
     * connections apart.
     */
    public function withConnectionName(string $connectionName): self
    {
        return new self(
            host: $this->host,
            port: $this->port,
            login: $this->login,
            password: $this->password,
            vhost: $this->vhost,
            connectTimeoutSeconds: $this->connectTimeoutSeconds,
            readTimeoutSeconds: $this->readTimeoutSeconds,
            writeTimeoutSeconds: $this->writeTimeoutSeconds,
            rpcTimeoutSeconds: $this->rpcTimeoutSeconds,
            heartbeatSeconds: $this->heartbeatSeconds,
            channelMax: $this->channelMax,
            frameMaxBytes: $this->frameMaxBytes,
            tls: $this->tls,
            saslMethod: $this->saslMethod,
            connectionName: $connectionName,
        );
    }

    /**
     * Builds the options from an AMQP URI, the form RabbitMQ documents:
     *
     *     amqp://login:password@host:5672/vhost?heartbeat=30&connection_timeout=3000
     *     amqps://host/%2f?cacertfile=/certs/ca.pem&verify=0
     *
     * The path is the vhost, and its encoding follows the specification rather than
     * intuition: no path at all means the default `/`, a bare `/` means the empty vhost,
     * and a vhost containing a slash is written `%2f`. Everything else is a query
     * parameter, named as the broker's own URI documentation names it.
     */
    public static function fromDsn(string $dsn): self
    {
        $parts = parse_url($dsn);

        if ($parts === false || !isset($parts['scheme'])) {
            throw new InvalidConnectionOptionException(message: "Could not parse the AMQP URI '$dsn'.");
        }

        $scheme = strtolower((string) $parts['scheme']);

        if ($scheme !== 'amqp' && $scheme !== 'amqps') {
            throw new InvalidConnectionOptionException(
                message: "Unknown AMQP URI scheme '$scheme'; expected 'amqp' or 'amqps'.",
            );
        }

        $query = static::queryOf($parts['query'] ?? null);

        $secure = $scheme === 'amqps';

        $tls = static::tlsFrom(
            query: $query,
            secure: $secure,
        );

        $defaults = new self();

        return new self(
            host: isset($parts['host']) ? rawurldecode((string) $parts['host']) : $defaults->host,
            port: isset($parts['port']) ? (int) $parts['port'] : ($secure ? 5671 : $defaults->port),
            login: isset($parts['user']) ? rawurldecode((string) $parts['user']) : $defaults->login,
            password: isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : $defaults->password,
            vhost: static::vhostOf($parts['path'] ?? null),
            connectTimeoutSeconds: static::secondsFromMilliseconds(
                query: $query,
                key: 'connection_timeout',
            ),
            heartbeatSeconds: isset($query['heartbeat'])
                ? (int) $query['heartbeat']
                : $defaults->heartbeatSeconds,
            channelMax: isset($query['channel_max']) ? (int) $query['channel_max'] : $defaults->channelMax,
            frameMaxBytes: isset($query['frame_max']) ? (int) $query['frame_max'] : $defaults->frameMaxBytes,
            tls: $tls,
            saslMethod: static::saslMethodOf($query),
            connectionName: isset($query['connection_name'])
                ? (string) $query['connection_name']
                : $defaults->connectionName,
        );
    }

    /**
     * The vhost the URI's path names. Absent means the default `/`; a bare `/` means the
     * empty vhost, which is a legal one and not the same thing.
     */
    protected static function vhostOf(?string $path): string
    {
        if ($path === null || $path === '') {
            return '/';
        }

        return rawurldecode(substr($path, 1));
    }

    /**
     * @return array<string, string>
     */
    protected static function queryOf(?string $query): array
    {
        if ($query === null || $query === '') {
            return [];
        }

        /** @var array<string, string> $parsed */
        $parsed = [];

        parse_str($query, $parsed);

        return $parsed;
    }

    /**
     * @param array<string, string> $query
     */
    protected static function tlsFrom(array $query, bool $secure): ?TlsOptions
    {
        $caCert = isset($query['cacertfile']) ? (string) $query['cacertfile'] : null;
        $cert   = isset($query['certfile']) ? (string) $query['certfile'] : null;
        $key    = isset($query['keyfile']) ? (string) $query['keyfile'] : null;

        $verifyGiven = isset($query['verify']);

        // `verify` alone does not turn a plaintext URI into a TLS one: it says how to
        // check a certificate, not that there is one. Naming a file does, and so does
        // the `amqps` scheme.
        if (!$secure && $caCert === null && $cert === null && $key === null) {
            return null;
        }

        return new TlsOptions(
            caCert: $caCert,
            cert: $cert,
            key: $key,
            verify: $verifyGiven ? static::verifyOf((string) $query['verify']) : true,
        );
    }

    /**
     * Whether the URI asked for the broker's certificate to be checked.
     *
     * The broker's own URI parameter spells the two states `verify_peer` and `verify_none`.
     * A boolean is accepted too, since that is what most configuration files carry — and it
     * is read the way a configuration file means it: `false`, `off` and `no` turn the check
     * off, where a plain cast would read every one of them as true and quietly verify.
     */
    protected static function verifyOf(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            ?? ($value !== 'verify_none');
    }

    /**
     * @param array<string, string> $query
     */
    protected static function saslMethodOf(array $query): SaslMethodEnum
    {
        $mechanism = isset($query['auth_mechanism']) ? strtoupper((string) $query['auth_mechanism']) : 'PLAIN';

        return $mechanism === 'EXTERNAL' ? SaslMethodEnum::External : SaslMethodEnum::Plain;
    }

    /**
     * @param array<string, string> $query
     */
    protected static function secondsFromMilliseconds(array $query, string $key): float
    {
        if (!isset($query[$key])) {
            return 0.0;
        }

        return ((float) $query[$key]) / 1000;
    }

    protected static function assertLength(string $name, string $value, int $limit): void
    {
        if (strlen($value) > $limit) {
            throw new InvalidConnectionOptionException(message: "Parameter '$name' must be at most $limit bytes long.");
        }
    }

    protected static function assertNotNegative(string $name, float $value): void
    {
        if ($value < 0) {
            throw new InvalidConnectionOptionException(message: "Parameter '$name' must not be negative.");
        }
    }
}
