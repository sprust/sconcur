<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Tests\Impl\Worker\TestWorkerMaster;

/**
 * The whole thing end to end: a WorkerMaster group whose workers are QueueConsumers,
 * configured entirely from the config file — the queue list travels as JSON through the
 * `server` block into the worker's argv, and the messages come out the other side.
 */
class AmqpConsumerPoolTest extends AmqpTestCase
{
    public function testASupervisedPoolDrainsItsQueues(): void
    {
        $channel = $this->channel();

        $orders = $this->declareQueue(channel: $channel, durable: true);
        $emails = $this->declareQueue(channel: $channel, durable: true);

        for ($index = 0; $index < 3; ++$index) {
            $this->publishToQueue($channel, $orders->name(), "order-$index");
            $this->publishToQueue($channel, $emails->name(), "email-$index");
        }

        $master = TestWorkerMaster::start(
            options: [
                'groups' => [
                    [
                        'name'         => 'consumers',
                        'workerScript' => self::consumerScript(),
                        'workerCount'  => 1,
                        'server'       => [
                            'queues' => [
                                ['name' => $orders->name(), 'coroutineCount' => 2],
                                ['name' => $emails->name(), 'coroutineCount' => 1],
                            ],
                            'prefetchCount' => 1,
                            'maxMessages'   => 6,
                        ],
                    ],
                ],
                'restartPolicy' => 'never',
            ],
            waitReachable: false,
        );

        try {
            $handled = $this->waitForHandledLines($master, expected: 6);

            sort($handled);

            self::assertSame(
                [
                    'handled email-0',
                    'handled email-1',
                    'handled email-2',
                    'handled order-0',
                    'handled order-1',
                    'handled order-2',
                ],
                $handled,
            );

            // maxMessages ends the run, and with restartPolicy=never the master follows.
            // The line lands after the last delivery, so it is waited for rather than
            // read off the journal the moment the sixth message shows up.
            self::assertTrue(
                $this->waitForLine($master, 'consumer finished handled=6'),
                'the worker must report its tally and exit: ' . $master->logText(),
            );
        } finally {
            $master->stop();
        }
    }

    /**
     * The journal is where an operator looks, so a supervised consumer has to name its
     * group and slot there like any other worker.
     */
    public function testTheJournalNamesTheConsumerGroup(): void
    {
        $channel = $this->channel();

        $queue = $this->declareQueue(channel: $channel, durable: true);

        $this->publishToQueue($channel, $queue->name(), 'one');

        $master = TestWorkerMaster::start(
            options: [
                'groups' => [
                    [
                        'name'         => 'orders-pool',
                        'workerScript' => self::consumerScript(),
                        'workerCount'  => 1,
                        'server'       => [
                            'queues'      => [['name' => $queue->name()]],
                            'maxMessages' => 1,
                        ],
                    ],
                ],
                'restartPolicy' => 'never',
            ],
            waitReachable: false,
        );

        try {
            $this->waitForHandledLines($master, expected: 1);

            self::assertStringContainsString('orders-pool #0', $master->logText());
            self::assertStringContainsString('start groups=1 workers=1', $master->logText());
        } finally {
            $master->stop();
        }
    }

    /**
     * @return list<string>
     */
    protected function waitForHandledLines(TestWorkerMaster $master, int $expected): array
    {
        $deadline = microtime(true) + 15.0;

        $handled = [];

        while (microtime(true) < $deadline) {
            $handled = [];

            foreach (explode("\n", $master->logText()) as $line) {
                if (preg_match('/(handled [^\s\\\\]+)/', $line, $matches) === 1) {
                    $handled[] = $matches[1];
                }
            }

            if (count($handled) >= $expected) {
                return $handled;
            }

            usleep(200_000);
        }

        self::fail(sprintf(
            'expected %d handled messages, saw %d. Journal: %s Master output: %s',
            $expected,
            count($handled),
            $master->logText(),
            $master->masterOutput(),
        ));
    }

    protected function waitForLine(TestWorkerMaster $master, string $needle): bool
    {
        $deadline = microtime(true) + 10.0;

        while (microtime(true) < $deadline) {
            if (str_contains($master->logText(), $needle)) {
                return true;
            }

            usleep(200_000);
        }

        return false;
    }

    protected static function consumerScript(): string
    {
        return dirname(__DIR__, 3) . '/consumers/amqp/amqp-consumer.php';
    }
}
