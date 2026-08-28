<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

use SConcur\Features\Amqp\Connection;
use SConcur\Features\Amqp\ConnectionOptions;

/**
 * The broker settings the AMQP tests and benchmarks run against, taken from the
 * environment (the sc-rabbitmq service of docker-compose.yml).
 *
 * Two shapes of the same settings: the options SConcur takes, and the credentials array
 * ext-amqp takes — the behaviour parity test opens both implementations against one broker
 * and needs each in its own form.
 */
class TestAmqpResolver
{
    /**
     * @param float $readTimeoutSeconds seconds a consumer waits for a delivery before its stream
     *                           ends. 0 waits forever, which is what a supervised worker
     *                           wants; a test passes a deadline so a consumer that is
     *                           never fed fails the run instead of hanging it
     */
    public static function getOptions(float $readTimeoutSeconds = 0.0): ConnectionOptions
    {
        return new ConnectionOptions(
            host: (string) $_ENV['RABBITMQ_HOST'],
            port: (int) $_ENV['RABBITMQ_PORT'],
            login: (string) $_ENV['RABBITMQ_USER'],
            password: (string) $_ENV['RABBITMQ_PASSWORD'],
            vhost: (string) $_ENV['RABBITMQ_VHOST'],
            readTimeoutSeconds: $readTimeoutSeconds,
        );
    }

    /**
     * The same settings as the credentials array ext-amqp accepts.
     *
     * @return array<string, mixed>
     */
    public static function getCredentials(): array
    {
        return [
            'host'     => $_ENV['RABBITMQ_HOST'],
            'port'     => (int) $_ENV['RABBITMQ_PORT'],
            'login'    => $_ENV['RABBITMQ_USER'],
            'password' => $_ENV['RABBITMQ_PASSWORD'],
            'vhost'    => $_ENV['RABBITMQ_VHOST'],
        ];
    }

    /** An open SConcur connection to the test broker. */
    public static function getConnection(float $readTimeoutSeconds = 0.0): Connection
    {
        $connection = new Connection(static::getOptions(readTimeoutSeconds: $readTimeoutSeconds));

        $connection->connect();

        return $connection;
    }

    /**
     * What the broker itself is holding: connections, channels and consumers.
     *
     * The half a soak cannot see from inside the process — a worker whose own memory is flat
     * can still leave sockets, channels or consumers behind on the other side.
     *
     * The management API serves these from its statistics database, which is a few seconds
     * behind the sockets; over a long run that lag is noise, and a leak is a trend.
     *
     * @return array{connections: int, channels: int, consumers: int}
     */
    public static function brokerCounts(): array
    {
        return [
            'connections' => static::countOf(path: '/api/connections'),
            'channels'    => static::countOf(path: '/api/channels'),
            'consumers'   => static::countOf(path: '/api/consumers'),
        ];
    }

    protected static function countOf(string $path): int
    {
        $listed = static::management(path: $path);

        return is_array($listed) ? count($listed) : 0;
    }

    /**
     * Closes every broker connection whose name contains the given text, through the
     * management API, and answers how many it closed.
     *
     * What a broker restart, a proxy timeout or an operator pressing the button does to a
     * worker — the one failure a pooled connection cannot dial its way out of, and therefore
     * the one a test has to be able to cause on purpose.
     */
    public static function closeConnectionsNamed(string $namePart): int
    {
        $connections = static::management(path: '/api/connections');

        if (!is_array($connections)) {
            return 0;
        }

        $closed = 0;

        foreach ($connections as $connection) {
            if (!is_array($connection)) {
                continue;
            }

            $properties = is_array($connection['client_properties'] ?? null) ? $connection['client_properties'] : [];
            $name       = (string) ($properties['connection_name'] ?? '');

            if ($name === '' || !str_contains($name, $namePart)) {
                continue;
            }

            static::management(
                path: '/api/connections/' . rawurlencode((string) ($connection['name'] ?? '')),
                method: 'DELETE',
            );

            ++$closed;
        }

        return $closed;
    }

    /**
     * One management-API call. The port is the broker's own inside the compose network; the
     * host mapping in RABBITMQ_MANAGEMENT_DOCKER_PORT is for a browser, not for this.
     */
    protected static function management(string $path, string $method = 'GET'): mixed
    {
        $url = sprintf('http://%s:15672%s', (string) $_ENV['RABBITMQ_HOST'], $path);

        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => 'Authorization: Basic ' . base64_encode(
                    $_ENV['RABBITMQ_USER'] . ':' . $_ENV['RABBITMQ_PASSWORD'],
                ),
                'timeout'       => 5,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, context: $context);

        if ($body === false || $body === '') {
            return null;
        }

        return json_decode($body, associative: true);
    }

    /**
     * A name no other test run uses, so tests never fight over a queue or an exchange.
     */
    public static function uniqueName(string $prefix): string
    {
        return sprintf('sconcur_test_%s_%s', $prefix, bin2hex(random_bytes(6)));
    }
}
