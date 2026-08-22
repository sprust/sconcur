<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

use SConcur\Exceptions\Amqp\ConnectionException;

/**
 * Everything a Connection needs to reach a broker, settled once and never changed after.
 *
 * The calque carried these as thirty mutable setters and getters, because the extension
 * builds its connection from a property bag. Here they are constructor arguments of a
 * readonly object, as with HttpClientOptions: a connection cannot have its host changed
 * underneath the channels already open on it.
 */
readonly class ConnectionOptions
{
    /**
     * Channels one connection may hold open. AMQP 0-9-1 carries channel-max as a 16-bit
     * short, so the protocol's own ceiling is 65535; this is the lower one ext-amqp
     * settled on, kept because a consumer pool sized against it is portable between the
     * two and because 256 channels on one socket is already past the point where another
     * connection is the better answer.
     */
    public const int MAX_CHANNELS = 256;

    /** The frame size asked for when none is named. */
    public const int DEFAULT_FRAME_MAX = 131072;

    /** The longest login or password the protocol accepts. */
    protected const int MAX_CREDENTIAL_LENGTH = 1024;

    /** The longest host or vhost the protocol accepts. */
    protected const int MAX_IDENTIFIER_LENGTH = 512;

    protected const int MIN_PORT = 1;

    protected const int MAX_PORT = 65535;

    /**
     * @param float $connectTimeout seconds to wait for the broker to answer the dial;
     *                              0 leaves the Go side to apply its own default
     * @param float $readTimeout    seconds a consumer waits for a delivery before the wait
     *                              fails; 0 waits forever
     * @param float $writeTimeout   seconds a publish may take
     * @param float $rpcTimeout     seconds any other single broker method may take
     * @param int   $heartbeat      seconds between heartbeats; 0 lets the broker choose
     *
     * @throws ConnectionException if a value is outside the range the protocol allows
     */
    public function __construct(
        public string $host = 'localhost',
        public int $port = 5672,
        public string $login = 'guest',
        public string $password = 'guest',
        public string $vhost = '/',
        public float $connectTimeout = 0.0,
        public float $readTimeout = 0.0,
        public float $writeTimeout = 0.0,
        public float $rpcTimeout = 0.0,
        public int $heartbeat = 0,
        public int $channelMax = self::MAX_CHANNELS,
        public int $frameMax = self::DEFAULT_FRAME_MAX,
        public ?TlsOptions $tls = null,
        public SaslMethodEnum $saslMethod = SaslMethodEnum::Plain,
        public ?string $connectionName = null,
    ) {
        static::assertLength(name: 'host', value: $host, limit: self::MAX_IDENTIFIER_LENGTH);
        static::assertLength(name: 'vhost', value: $vhost, limit: self::MAX_IDENTIFIER_LENGTH);
        static::assertLength(name: 'login', value: $login, limit: self::MAX_CREDENTIAL_LENGTH);
        static::assertLength(name: 'password', value: $password, limit: self::MAX_CREDENTIAL_LENGTH);

        if ($port < self::MIN_PORT || $port > self::MAX_PORT) {
            throw new ConnectionException(
                message: "Parameter 'port' must be between " . self::MIN_PORT . ' and ' . self::MAX_PORT . '.',
            );
        }

        static::assertNotNegative(name: 'connectTimeout', value: $connectTimeout);
        static::assertNotNegative(name: 'readTimeout', value: $readTimeout);
        static::assertNotNegative(name: 'writeTimeout', value: $writeTimeout);
        static::assertNotNegative(name: 'rpcTimeout', value: $rpcTimeout);

        if ($heartbeat < 0) {
            throw new ConnectionException(message: "Parameter 'heartbeat' must not be negative.");
        }

        if ($channelMax < 1 || $channelMax > self::MAX_CHANNELS) {
            throw new ConnectionException(
                message: "Parameter 'channelMax' must be between 1 and " . self::MAX_CHANNELS . '.',
            );
        }

        if ($frameMax < 1) {
            throw new ConnectionException(message: "Parameter 'frameMax' must be positive.");
        }

        if ($saslMethod === SaslMethodEnum::External && $tls?->cert === null) {
            throw new ConnectionException(
                message: 'SASL EXTERNAL authenticates with a client certificate, so TLS options naming'
                    . ' a cert and a key are required.',
            );
        }
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
     *
     * @throws ConnectionException if the URI cannot be read or names an unknown scheme
     */
    public static function fromDsn(string $dsn): self
    {
        $parts = parse_url($dsn);

        if ($parts === false || !isset($parts['scheme'])) {
            throw new ConnectionException(message: "Could not parse the AMQP URI '$dsn'.");
        }

        $scheme = strtolower((string) $parts['scheme']);

        if ($scheme !== 'amqp' && $scheme !== 'amqps') {
            throw new ConnectionException(
                message: "Unknown AMQP URI scheme '$scheme'; expected 'amqp' or 'amqps'.",
            );
        }

        $query = static::queryOf($parts['query'] ?? null);

        $secure = $scheme === 'amqps';

        $tls = static::tlsFrom(query: $query, secure: $secure);

        $defaults = new self();

        return new self(
            host: isset($parts['host']) ? rawurldecode((string) $parts['host']) : $defaults->host,
            port: isset($parts['port']) ? (int) $parts['port'] : ($secure ? 5671 : $defaults->port),
            login: isset($parts['user']) ? rawurldecode((string) $parts['user']) : $defaults->login,
            password: isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : $defaults->password,
            vhost: static::vhostOf($parts['path'] ?? null),
            connectTimeout: static::secondsFromMilliseconds(query: $query, key: 'connection_timeout'),
            heartbeat: isset($query['heartbeat']) ? (int) $query['heartbeat'] : $defaults->heartbeat,
            channelMax: isset($query['channel_max']) ? (int) $query['channel_max'] : $defaults->channelMax,
            frameMax: isset($query['frame_max']) ? (int) $query['frame_max'] : $defaults->frameMax,
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
            // The broker's own URI parameter spells the two states `verify_peer` and
            // `verify_none`; a plain boolean is accepted too, since that is what most
            // configuration files carry.
            verify: $verifyGiven
                ? ((string) $query['verify'] !== 'verify_none' && (bool) $query['verify'])
                : true,
        );
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

    /**
     * @throws ConnectionException if the value is longer than the protocol allows
     */
    protected static function assertLength(string $name, string $value, int $limit): void
    {
        if (strlen($value) > $limit) {
            throw new ConnectionException(message: "Parameter '$name' must be at most $limit bytes long.");
        }
    }

    /**
     * @throws ConnectionException if the value is negative
     */
    protected static function assertNotNegative(string $name, float $value): void
    {
        if ($value < 0) {
            throw new ConnectionException(message: "Parameter '$name' must not be negative.");
        }
    }
}
