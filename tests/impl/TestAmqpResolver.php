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
     * @param float $readTimeout seconds a consumer waits for a delivery before its stream
     *                           ends. 0 waits forever, which is what a supervised worker
     *                           wants; a test passes a deadline so a consumer that is
     *                           never fed fails the run instead of hanging it
     */
    public static function getOptions(float $readTimeout = 0.0): ConnectionOptions
    {
        return new ConnectionOptions(
            host: (string) $_ENV['RABBITMQ_HOST'],
            port: (int) $_ENV['RABBITMQ_PORT'],
            login: (string) $_ENV['RABBITMQ_USER'],
            password: (string) $_ENV['RABBITMQ_PASSWORD'],
            vhost: (string) $_ENV['RABBITMQ_VHOST'],
            readTimeout: $readTimeout,
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
    public static function getConnection(float $readTimeout = 0.0): Connection
    {
        $connection = new Connection(static::getOptions(readTimeout: $readTimeout));

        $connection->connect();

        return $connection;
    }

    /**
     * A name no other test run uses, so tests never fight over a queue or an exchange.
     */
    public static function uniqueName(string $prefix): string
    {
        return sprintf('sconcur_test_%s_%s', $prefix, bin2hex(random_bytes(6)));
    }
}
