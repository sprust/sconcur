<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Telemetry\FrameCodec;
use SConcur\Tests\Impl\FreePort;
use SConcur\Tests\Impl\PhpScript;
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

        $panelPort  = FreePort::find();
        $adminToken = bin2hex(random_bytes(16));

        $master = TestWorkerMaster::start(
            options: [
                'panelPort'  => $panelPort,
                'adminToken' => $adminToken,
                'groups'     => [
                    [
                        'name'         => 'consumers',
                        'workerScript' => PhpScript::projectRoot() . '/tests/consumers/amqp/amqp-consumer.php',
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
     * Telemetry after a fork from a process whose pusher was still up — a consumer's stays
     * for the life of the process. The child found the inherited pusher in its slot,
     * started none of its own, and reported nothing.
     */
    public function testAChildForkedAfterTheParentPushedReportsUnderItsOwnPid(): void
    {
        $socketPath = sys_get_temp_dir() . '/sconcur-fork-telemetry-' . bin2hex(random_bytes(4)) . '.sock';

        $listener = stream_socket_server('unix://' . $socketPath, $errorCode, $errorMessage);

        self::assertNotFalse($listener, "could not listen on $socketPath: $errorMessage");

        stream_set_blocking($listener, false);

        $code = <<<'PHP'
        require $argv[1] . '/vendor/autoload.php';

        use SConcur\Connection\Extension;
        use SConcur\Features\Sleeper\Sleeper;
        use SConcur\Tests\Impl\TestAmqpResolver;
        use SConcur\Tests\Impl\TestApplication;

        TestApplication::init();

        // Read when the first consumer of the process opens.
        putenv('SCONCUR_TELEMETRY_SOCKET=' . $argv[2]);
        putenv('SCONCUR_TELEMETRY_INTERVAL_MS=50');

        function consumeOne(): void
        {
            $connection = TestAmqpResolver::getConnection(readTimeoutSeconds: 5.0);

            $queue = $connection->channel()->queue(TestAmqpResolver::uniqueName('fork'));

            $queue->declare(exclusive: true);
            $queue->publish('ping');

            foreach ($queue->consume(autoAck: true) as $delivery) {
                break;
            }

            // A few intervals, so the push loop has something to send.
            Sleeper::usleep(microseconds: 300_000);

            $connection->close();
        }

        consumeOne();

        $pid = pcntl_fork();

        if ($pid === 0) {
            consumeOne();

            echo 'CHILD:', getmypid(), PHP_EOL;

            Extension::get()->destroy();

            exit(0);
        }

        pcntl_waitpid($pid, $status);

        Extension::get()->destroy();
        PHP;

        $process = proc_open(
            command: [
                PHP_BINARY,
                '-d',
                'extension=' . PhpScript::extensionPath(),
                '-r',
                $code,
                PhpScript::projectRoot(),
                $socketPath,
            ],
            descriptor_spec: [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            pipes: $pipes,
        );

        self::assertIsResource($process);

        try {
            $pushedPids = $this->collectPushedPids(
                listener: $listener,
                process: $process,
            );

            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        } finally {
            proc_close($process);
            fclose($listener);
            @unlink($socketPath);
        }

        self::assertSame(1, preg_match('~CHILD:(\d+)~', $output, $matches), 'the child did not finish: ' . $output);

        self::assertContains(
            (int) $matches[1],
            $pushedPids,
            'the child pushed no snapshot of its own; pids seen: ' . implode(', ', $pushedPids),
        );
    }

    /**
     * Reads the snapshot frames pushed to the listener until the process exits, and
     * answers the pids they came from.
     *
     * @param resource $listener
     * @param resource $process
     *
     * @return list<int>
     */
    protected function collectPushedPids(mixed $listener, mixed $process): array
    {
        $connections = [];
        $buffers     = [];
        $pids        = [];

        $deadline = microtime(true) + 30.0;

        while (microtime(true) < $deadline && proc_get_status($process)['running']) {
            $read   = [$listener, ...$connections];
            $write  = null;
            $except = null;

            if ((int) @stream_select($read, $write, $except, 0, 100_000) === 0) {
                continue;
            }

            foreach ($read as $stream) {
                if ($stream === $listener) {
                    $connection = @stream_socket_accept($listener, 0);

                    if (is_resource($connection)) {
                        stream_set_blocking($connection, false);

                        $connections[(int) $connection] = $connection;
                        $buffers[(int) $connection]     = '';
                    }

                    continue;
                }

                $chunk = (string) fread($stream, 65_536);

                if ($chunk === '') {
                    if (feof($stream)) {
                        unset($connections[(int) $stream]);

                        fclose($stream);
                    }

                    continue;
                }

                [$frames, $buffers[(int) $stream]] = FrameCodec::extractFrames(
                    buffer: $buffers[(int) $stream] . $chunk,
                    maxFrameBytes: 65_536,
                );

                foreach ($frames as $frame) {
                    $pid = json_decode($frame, true)['s']['pid'] ?? null;

                    if (is_int($pid)) {
                        $pids[$pid] = $pid;
                    }
                }
            }
        }

        foreach ($connections as $connection) {
            fclose($connection);
        }

        return array_values($pids);
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
}
