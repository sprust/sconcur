<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

use SConcur\Features\Amqp\AMQPConnection;

/**
 * The broker settings the AMQP tests and benchmarks run against, taken from the
 * environment (the sc-rabbitmq service of docker-compose.yml). The credentials array is
 * shaped like ext-amqp's, so the same one opens either implementation — which is what the
 * behaviour parity test needs.
 */
class TestAmqpResolver
{
    /**
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

    /**
     * An open SConcur connection to the test broker.
     */
    public static function getConnection(): AMQPConnection
    {
        $connection = new AMQPConnection(static::getCredentials());

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
