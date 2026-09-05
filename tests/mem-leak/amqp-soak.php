<?php

declare(strict_types=1);

// Soak test for the AMQP feature: runs one scenario in a loop and prints, every five
// seconds, what is held on both sides — the PHP heap and its dangling tasks, and what the
// broker itself still has open.
//
// Everything a cycle creates is released inside that cycle, so any value that only grows
// is a leak.
//
// Run it through `make mem-leak-amqp scenario=<name> seconds=<n>`, or by hand:
//
//   php -d extension=./ext/build/sconcur.so \
//       tests/mem-leak/amqp-soak.php <scenario> <seconds>

use SConcur\Connection\Extension;
use SConcur\Exceptions\Amqp\UnroutableMessageException;
use SConcur\Features\Amqp\Connection;
use SConcur\Features\Amqp\Consumer\QueueConsumer;
use SConcur\Features\Amqp\Delivery;
use SConcur\Exceptions\Amqp\ConnectionException;
use SConcur\Features\Amqp\ExchangeTypeEnum;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Tests\Impl\TestAmqpResolver;
use SConcur\Tests\Impl\TestApplication;
use SConcur\WaitGroup;

require_once __DIR__ . '/../../vendor/autoload.php';

TestApplication::init();

$scenario       = (string) ($_SERVER['argv'][1] ?? 'publish');
$durationSecond = (int) ($_SERVER['argv'][2] ?? 120);


$options = TestAmqpResolver::getOptions();

$queueName    = 'sconcur_soak_' . $scenario;
$exchangeName = 'sconcur_soak_exchange_' . $scenario;

// The long-lived topology every scenario works on. A scenario that needs its own
// connection builds one per cycle instead.
$connection = new Connection($options);
$connection->connect();

$channel = $connection->channel();

$exchange = $channel->exchange($exchangeName);

$exchange->declare(
    type: ExchangeTypeEnum::Direct,
    durable: true,
);

$queue = $channel->queue($queueName);

$queue->declare(durable: true);
$queue->purge();
$queue->bind(
    exchange: $exchangeName,
    routingKey: 'soak',
);

// Where the consumer scenarios publish what their handlers succeed at, and the two names
// nothing answers to: a routing key no queue is bound to, and an exchange that is not there.
$sinkName    = $queueName . '_sink';
$nowhereKey  = $queueName . '_nowhere';
$missingName = $queueName . '_missing_exchange';

$sink = $channel->queue($sinkName);

$sink->declare(durable: true);
$sink->purge();

// Where the consumer scenarios keep their place: which ending the next handler takes, and
// which cycle takes the queue away.
$outcome   = 0;
$lostCycle = 0;

/**
 * One cycle of each scenario. Whatever it opens, it closes.
 */
$scenarios = [
    // Publishing and reading back on a channel that lives for the whole run.
    'publish' => static function () use ($exchange, $queue): void {
        $exchange->publish(
            message: 'soak',
            routingKey: 'soak',
        );

        if ($queue->get(autoAck: true) === null) {
            usleep(1000);
        }
    },

    // A connection and a channel per cycle: the pool, the handle registry and the channel
    // registry all have to give the resources back.
    'churn' => static function () use ($options, $queueName): void {
        $connection = new Connection($options);

        $connection->connect();

        $channel = $connection->channel();

        $channel->queue($queueName)->declare(durable: true);

        $channel->close();
        $connection->close();
    },

    // A consumer per cycle, opened, fed one message and cancelled.
    'consume' => static function () use ($connection, $exchange, $queueName): void {
        $exchange->publish(
            message: 'soak',
            routingKey: 'soak',
        );

        $channel = $connection->channel();

        foreach ($channel->queue($queueName)->consume() as $delivery) {
            $delivery->ack();

            break;
        }

        $channel->close();
    },

    // Ten coroutines publishing and reading at the same time, each on its own channel.
    'fanout' => static function () use ($connection, $exchangeName, $queueName): void {
        $waitGroup = WaitGroup::create();

        for ($index = 0; $index < 10; ++$index) {
            $waitGroup->add(static function () use ($connection, $exchangeName, $queueName): int {
                $channel = $connection->channel();

                $channel->exchange($exchangeName)->publish(message: 'soak', routingKey: 'soak');

                $delivery = $channel->queue($queueName)->get(autoAck: true);

                $channel->close();

                return $delivery === null ? 0 : 1;
            });
        }

        $waitGroup->waitAll();
    },

    // The failure path: every cycle kills a channel with a 404 and throws it away. This is
    // where a dead channel would pile up if the registries did not release it.
    //
    // On its own connection, and it replaces it when the core retires one. Every
    // cycle costs a channel number — a channel the broker closes never hands its
    // number back — so a long enough run has to reconnect, which is what an
    // application doing this has to do too. Closing the retired one is part of
    // that: dropping it instead leaves its flow registered and the task count
    // climbs by one per swap.
    'errors' => static function () use ($options): void {
        static $own = null;

        if ($own === null) {
            $own = new Connection($options);

            $own->connect();
        }

        try {
            $channel = $own->channel();
        } catch (ConnectionException) {
            $own->close();

            $own = new Connection($options);

            $own->connect();

            return;
        }

        try {
            $channel->queue('sconcur_soak_missing_' . bin2hex(random_bytes(4)))->declarePassive();
        } catch (Throwable) {
            // The point of the cycle: the broker closes the channel over this.
        }

        $channel->close();
    },

    // Publisher confirms and a returned message, the two wait loops.
    'confirms' => static function () use ($connection, $exchangeName): void {
        $channel = $connection->channel();

        $exchange = $channel->exchange($exchangeName);

        $exchange->publishConfirmed(
            message: 'soak',
            routingKey: 'soak',
            timeoutSeconds: 2.0,
        );

        try {
            // Routes nowhere, so the broker sends it back and the publish fails with it —
            // the return and the confirm are drained by the same wait.
            $exchange->publishConfirmed(
            message: 'returned',
            routingKey: 'nowhere',
            timeoutSeconds: 2.0,
        );
        } catch (UnroutableMessageException) {
            // The point of the cycle.
        }

        $channel->close();
    },

    // A coroutine that opens and cancels consumers in a loop, the way a worker switching
    // between queues does. Its flow lives as long as the coroutine, so every stream it
    // leaves behind stays with it.
    'consume-async' => static function () use ($connection, $exchange, $queueName): void {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(static function () use ($connection, $exchange, $queueName): int {
            $channel = $connection->channel();

            $queue = $channel->queue($queueName);

            $taken = 0;

            for ($round = 0; $round < 10; ++$round) {
                $exchange->publish(
                    message: 'soak',
                    routingKey: 'soak',
                );

                foreach ($queue->consume() as $delivery) {
                    ++$taken;

                    $delivery->ack();

                    break;
                }
            }

            $channel->close();

            return $taken;
        });

        $waitGroup->waitAll();
    },

    // A consumer stopped from the outside: the flow is cancelled while the coroutine waits
    // for a delivery that never comes.
    'stop' => static function () use ($connection, $queueName): void {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(static function () use ($connection, $queueName): string {
            $channel = $connection->channel();

            foreach ($channel->queue($queueName)->consume() as $delivery) {
                $delivery->ack();
            }

            return 'ended';
        });

        $waitGroup->add(static function () use ($waitGroup): string {
            Sleeper::usleep(microseconds: 20_000);

            $waitGroup->stop();

            return 'stopped';
        });

        $waitGroup->waitAll();
    },

    // The supervised consumer and the channels it lends its handlers, put through every way
    // a handler can end. What must stay flat is the pool — channels, connections and the
    // handles over the channels the deliveries arrive on — and the broker's own three
    // columns beside it.
    'consumer' => static function () use (
        $connection,
        $channel,
        $queueName,
        $sinkName,
        $nowhereKey,
        $missingName,
        &$outcome,
    ): void {
        $messages = 60;

        for ($index = 0; $index < $messages; ++$index) {
            $channel->publish(
                message: "job-$index",
                exchange: '',
                routingKey: $queueName,
            );
        }

        $queueConsumer = new QueueConsumer(
            queues: (string) json_encode([['name' => $queueName, 'coroutineCount' => 2]]),
            prefetchCount: 4,
            handlerTimeoutMs: 150,
            // Exactly what was published: the ones put back unjudged come round again and
            // take the place of the ones their handlers refused, and whatever is left over
            // is purged at the end of the cycle. The wall is only there so a cycle in which
            // the ground moves still ends.
            maxMessages: $messages,
            maxRuntimeSeconds: 10,
        );

        $queueConsumer->consume(
            connection: $connection,
            handler: static function (Delivery $delivery) use (
                $sinkName,
                $nowhereKey,
                $missingName,
                &$outcome,
            ): void {
                $own = $delivery->channel();

                if ($own === null) {
                    return;
                }

                // Counted rather than derived from the body, so a message put back takes a
                // different path the second time instead of repeating its own for ever.
                ++$outcome;

                switch ($outcome % 12) {
                    case 0:
                        // Nothing at all: the runtime acknowledges it.
                        return;

                    case 1:
                        $own->publish(
                            message: $delivery->body,
                            exchange: '',
                            routingKey: $sinkName,
                        );

                        return;

                    case 2:
                        // The ordinary success: the channel comes back clean and is reused.
                        $own->publishConfirmed(
                            message: $delivery->body,
                            exchange: '',
                            routingKey: $sinkName,
                            timeoutSeconds: 2.0,
                            mandatory: false,
                        );

                        return;

                    case 3:
                        $delivery->ack();

                        return;

                    case 4:
                        $delivery->nack(requeue: false);

                        return;

                    case 5:
                        $delivery->reject();

                        return;

                    case 6:
                        throw new RuntimeException('the handler failed on purpose');

                    case 7:
                        // Past the deadline: unwound mid-handler, its channel given back by
                        // the finally rather than by the handler.
                        Sleeper::usleep(microseconds: 400_000);

                        return;

                    case 8:
                        // An answer nobody reads: the pool has to give this channel up.
                        $own->publish(
                            message: $delivery->body,
                            exchange: '',
                            routingKey: $nowhereKey,
                            mandatory: true,
                        );

                        return;

                    case 9:
                        // A verdict the broker does give: routed nowhere.
                        $own->publishConfirmed(
                            message: $delivery->body,
                            exchange: '',
                            routingKey: $nowhereKey,
                            timeoutSeconds: 2.0,
                        );

                        return;

                    case 10:
                        // The same, through the retry loop.
                        $own->publishConfirmed(
                            message: $delivery->body,
                            exchange: '',
                            routingKey: $nowhereKey,
                            timeoutSeconds: 2.0,
                            retries: 1,
                            retryDelaysSeconds: [0.01],
                        );

                        return;

                    default:
                        // A 404 the handler asked for: it takes the lent channel down with
                        // it, and the pool must not lend that one again.
                        $own->publish(
                            message: $delivery->body,
                            exchange: $missingName,
                            routingKey: 'soak',
                        );
                }
            },
            onError: static function (): void {
                // Every failure above is deliberate; the run is not the place to report it.
            },
        );

        $channel->queue($sinkName)->purge();
        $channel->queue($queueName)->purge();
    },

    // The same worker while the ground moves: the connection its handlers publish on is
    // taken away, and the consumer itself is taken away with its queue. Both are the paths
    // where a handle, a channel or a socket is most likely to be left behind.
    'consumer-lost' => static function () use (
        $connection,
        $channel,
        $queueName,
        $sinkName,
        &$lostCycle,
    ): void {
        $messages = 20;

        ++$lostCycle;

        for ($index = 0; $index < $messages; ++$index) {
            $channel->publish(
                message: "job-$index",
                exchange: '',
                routingKey: $queueName,
            );
        }

        $takeQueueAway = $lostCycle % 3 === 0;

        $queueConsumer = new QueueConsumer(
            queues: (string) json_encode([['name' => $queueName, 'coroutineCount' => 2]]),
            prefetchCount: 4,
            handlerTimeoutMs: 500,
            maxMessages: $messages,
            maxRuntimeSeconds: 8,
        );

        $handled = 0;

        $queueConsumer->consume(
            connection: $connection,
            handler: static function (Delivery $delivery) use (
                $sinkName,
                $queueName,
                $takeQueueAway,
                &$handled,
            ): void {
                $own = $delivery->channel();

                if ($own === null) {
                    return;
                }

                ++$handled;

                if ($handled === 5) {
                    // Closes whatever the pool has open. The broker lists a connection a few
                    // seconds after it is made, so this lands on one from an earlier cycle as
                    // often as on this one — which is the point: the loss arrives unannounced.
                    TestAmqpResolver::closeConnectionsNamed(' publish ');
                }

                if ($takeQueueAway && $handled === 9) {
                    // The consumer is cancelled by the broker and reopened on a channel with
                    // an id of its own, which is what leaves a handle behind if nothing
                    // sweeps them. Done on this handler's own channel, never the shared one.
                    $own->queue($queueName)->delete();
                    $own->queue($queueName)->declare(durable: true);

                    return;
                }

                $own->publishConfirmed(
                    message: $delivery->body,
                    exchange: '',
                    routingKey: $sinkName,
                    timeoutSeconds: 2.0,
                    mandatory: false,
                );
            },
            onError: static function (): void {
                // Losing the ground under a handler is the scenario, not a failure of it.
            },
        );

        $channel->queue($sinkName)->purge();
        $channel->queue($queueName)->purge();
    },
];

if (!isset($scenarios[$scenario])) {
    echo 'unknown scenario ', $scenario, '; known: ', implode(', ', array_keys($scenarios)), PHP_EOL;

    exit(1);
}

$cycle     = $scenarios[$scenario];
$extension = Extension::get();

$startTime  = microtime(true);
$deadline   = $startTime + $durationSecond;
$cycleCount = 0;
$failures   = 0;
$lastReport = 0.0;

echo "scenario=$scenario duration={$durationSecond}s\n";
echo "elapsed cycles  php_mb  php_peak_mb  tasks"
    . "  br_conn  br_chan  br_cons  failures\n";

while (microtime(true) < $deadline) {
    try {
        $cycle();
    } catch (Throwable $exception) {
        ++$failures;

        if ($failures <= 3) {
            echo '  failure: ', get_class($exception), ': ', $exception->getMessage(), PHP_EOL;
        }
    }

    ++$cycleCount;

    $elapsed = microtime(true) - $startTime;

    if ($elapsed - $lastReport < 5) {
        continue;
    }

    $lastReport = $elapsed;

    gc_collect_cycles();

    $broker  = TestAmqpResolver::brokerCounts();

    printf(
        "%7.1f %6d %7.2f %12.2f %6d %8d %8d %8d %9d\n",
        $elapsed,
        $cycleCount,
        memory_get_usage() / 1024 / 1024,
        memory_get_peak_usage() / 1024 / 1024,
        $extension->count(),
        $broker['connections'],
        $broker['channels'],
        $broker['consumers'],
        $failures,
    );
}

$queue->delete();
$sink->delete();
$exchange->delete();

$channel->close();
$connection->close();

printf(
    "done: %d cycles in %.1fs (%.0f/s), %d failures\n",
    $cycleCount,
    microtime(true) - $startTime,
    $cycleCount / max(microtime(true) - $startTime, 0.001),
    $failures,
);
