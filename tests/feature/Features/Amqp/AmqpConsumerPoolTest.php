<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use SConcur\Features\Amqp\RetryTopology;
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

            // Stopped from outside rather than by maxMessages: under a master the limit is
            // spread over up to half again as many messages, so it would not end the run
            // at six. A stop ends it the way a deploy does, and the tally says nothing was
            // left half-done.
            $master->signal(SIGTERM);

            self::assertTrue(
                $this->waitForLine($master, 'consumer finished handled=6'),
                'the worker must report its tally and exit: ' . $master->logText(),
            );

            self::assertSame(0, $master->waitForExit(10.0), 'the master must stop cleanly');
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
     * maxRuntimeSeconds is a life limit like maxMessages: the worker that reaches it says
     * so, and under OnFailure the master replaces it rather than taking its clean exit
     * for the end of the job.
     */
    public function testAConsumerAtItsRuntimeLimitIsReplacedUnderOnFailure(): void
    {
        $channel = $this->channel();

        $queue = $this->declareQueue(channel: $channel, durable: true);

        $this->publishToQueue($channel, $queue->name(), 'one');

        $master = TestWorkerMaster::start(
            options: [
                'groups' => [
                    [
                        'name'         => 'consumers',
                        'workerScript' => self::consumerScript(),
                        'workerCount'  => 1,
                        'server'       => [
                            'queues'            => [['name' => $queue->name()]],
                            'maxRuntimeSeconds' => 1,
                        ],
                    ],
                ],
                'restartPolicy' => 'on-failure',
            ],
            waitReachable: false,
        );

        try {
            $this->waitForHandledLines($master, expected: 1);

            self::assertTrue(
                $this->waitForLine($master, 'stop accepting (reason=limit, maxRuntimeSeconds=1)'),
                'the worker must stop at its runtime limit: ' . $master->logText(),
            );

            self::assertTrue(
                $this->waitForLine($master, 'its limit;'),
                'the master must replace a worker that stopped at its limit: ' . $master->logText(),
            );

            self::assertStringNotContainsString('not restarting', $master->logText());

            $this->publishToQueue($channel, $queue->name(), 'two');

            self::assertSame(
                ['handled one', 'handled two'],
                $this->waitForHandledLines($master, expected: 2),
            );
        } finally {
            $master->stop();
        }
    }

    /**
     * The delayed-retry shape the demo worker implements: a job that fails comes back after
     * a wait that grows with the attempt, and the wait happens on the broker — the worker
     * acknowledges the failed delivery and is free while the job is away.
     */
    public function testAFailedJobComesBackAfterAGrowingDelay(): void
    {
        $channel = $this->channel();

        $queue = $this->declareQueue(channel: $channel, durable: true);

        // The worker declares these; the test only remembers them for cleanup.
        foreach ([200, 500] as $delayMs) {
            $this->declaredQueues[] = RetryTopology::waitQueueName(
                queue: $queue->name(),
                delayMs: $delayMs,
            );
        }

        $this->publishToQueue($channel, $queue->name(), 'retry:2');

        $master = TestWorkerMaster::start(
            options: [
                'groups' => [
                    [
                        'name'         => 'retrying',
                        'workerScript' => self::consumerScript(),
                        'workerCount'  => 1,
                        'server'       => [
                            'queues'      => [['name' => $queue->name()]],
                            'maxMessages' => 3,
                        ],
                    ],
                ],
                'restartPolicy' => 'never',
            ],
            waitReachable: false,
        );

        try {
            self::assertTrue(
                $this->waitForLine($master, 'handled retry:2 on attempt 3'),
                'the job must succeed on its third delivery: ' . $master->logText(),
            );

            $log = $master->logText();

            // Two failures, and the second waits longer than the first.
            self::assertStringContainsString('failed retry:2 on attempt 1, back in 200ms', $log);
            self::assertStringContainsString('failed retry:2 on attempt 2, back in 500ms', $log);
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
