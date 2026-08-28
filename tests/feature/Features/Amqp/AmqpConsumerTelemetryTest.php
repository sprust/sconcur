<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Tests\Impl\Worker\TestWorkerMaster;

/**
 * A supervised consumer reports to the same panel the servers do. The counters come
 * off the traffic that already crosses the boundary — a delivery is counted when it
 * leaves for PHP, and settled by the acknowledgement or the refusal that follows — so
 * nothing extra is sent for the sake of telemetry.
 */
class AmqpConsumerTelemetryTest extends AmqpTestCase
{
    public function testTheConsumerSectionReachesThePanel(): void
    {
        $channel = $this->channel();

        $queue = $this->declareQueue(channel: $channel, durable: true);

        for ($index = 0; $index < 3; ++$index) {
            $this->publishToQueue($channel, $queue->name(), "message-$index");
        }

        // One message is refused, so the refused counter is not just zero on both sides.
        $this->publishToQueue($channel, $queue->name(), 'reject');

        $panelPort  = $this->freePort();
        $adminToken = bin2hex(random_bytes(16));

        $master = TestWorkerMaster::start(
            options: [
                'panelPort'  => $panelPort,
                'adminToken' => $adminToken,
                'groups'     => [
                    [
                        'name'         => 'consumers',
                        'workerScript' => dirname(__DIR__, 3) . '/consumers/amqp/amqp-consumer.php',
                        'workerCount'  => 1,
                        // No maxMessages: the counters live in the worker process, so a
                        // worker that finished and was replaced would report the empty
                        // queue its replacement sees, not the messages it handled.
                        'server'       => [
                            'queues' => [['name' => $queue->name()]],
                        ],
                    ],
                ],
            ],
            waitReachable: false,
        );

        try {
            $consumers = $this->waitForConsumersSection($panelPort, $adminToken);

            self::assertNotNull(
                $consumers,
                'the panel must carry a consumers section. Journal: ' . $master->logText(),
            );

            self::assertSame(4, $consumers['delivered']);
            self::assertSame(3, $consumers['acked']);
            self::assertSame(1, $consumers['refused']);
            self::assertSame(0, $consumers['inFlight'], 'everything was settled');

            // The section is the consumer's; a worker that serves no requests must not
            // claim a requests section it has no numbers for.
            self::assertArrayNotHasKey('requests', $this->readStats($panelPort, $adminToken)['totals'] ?? []);
        } finally {
            $master->stop();
        }
    }

    /**
     * @return array<string, int>|null
     */
    protected function waitForConsumersSection(int $panelPort, string $adminToken): ?array
    {
        $deadline = microtime(true) + 20.0;

        while (microtime(true) < $deadline) {
            $stats = $this->readStats($panelPort, $adminToken);

            $consumers = $stats['totals']['consumers'] ?? null;

            if (is_array($consumers) && ($consumers['delivered'] ?? 0) >= 4 && ($consumers['inFlight'] ?? 1) === 0) {
                /** @var array<string, int> $consumers */
                return $consumers;
            }

            usleep(250_000);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function readStats(int $panelPort, string $adminToken): array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 2,
                // The endpoint is content-negotiated and answers Prometheus text by
                // default, so the Accept header is what asks it for JSON.
                'header'        => "Authorization: Bearer $adminToken\r\nAccept: application/json",
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents("http://127.0.0.1:$panelPort/api/stats", false, $context);

        if (!is_string($body)) {
            return [];
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        self::assertNotFalse($socket, "could not allocate a port: $errstr");

        $name = (string) stream_socket_get_name($socket, false);

        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
