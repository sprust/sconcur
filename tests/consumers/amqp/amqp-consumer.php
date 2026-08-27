<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use SConcur\Features\Amqp\Consumer\QueueConsumer;
use SConcur\Features\Amqp\Delivery;
use SConcur\Features\Amqp\Message;
use SConcur\Features\Amqp\RetrySchedule;
use SConcur\Features\Amqp\RetryTopology;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Tests\Impl\TestAmqpResolver;
use SConcur\Tests\Impl\TestApplication;

/**
 * Demo / test AMQP consumer worker: the shape a supervised consumer takes.
 *
 * Everything about the queues comes from argv, which is how WorkerMaster configures a
 * group's workers:
 *
 *   --queues=[{"name":"orders","coroutineCount":4}]  the queues and their weights
 *   --prefetchCount=1                                 default prefetch per coroutine
 *   --maxMessages=0                                   stop after N messages (0 = never)
 *   --masterPid=<pid>                                 injected by the master
 *
 * The credentials are the worker's own business and never travel in argv — a password
 * would be visible in `ps` to every user of the machine.
 *
 * What each message does is decided by its body, so a test can drive the worker:
 *   "sleep:<ms>"      -> async sleep, then acknowledge (concurrency demo)
 *   "reject"          -> reject without requeue
 *   "retry:<n>"       -> fail n times, each time coming back after a delay, then succeed
 *   anything else     -> acknowledge, and print what was handled
 */
/**
 * A job that fails and comes back later, which is what the delay is for.
 *
 * The body says how many times it should fail: `retry:2` fails twice and succeeds on the
 * third delivery. The attempt count rides in a header, so the worker can pick a longer wait
 * each time rather than the one fixed delay dead-lettering would give it.
 *
 * Note the order and the seam it leaves. The job is republished with confirms before the
 * original is acknowledged, so a republish the broker refused leaves the original in the
 * queue rather than dropping the job — but a worker that dies between the two leaves both
 * copies. Where a single fixed delay is enough, `nack(requeue: false)` into a
 * dead-lettering queue has no seam at all, because the broker does the move itself.
 */
function handleRetry(Delivery $delivery, RetrySchedule $schedule, string $attemptHeader): void
{
    $failuresWanted = (int) substr($delivery->body, strlen('retry:'));
    $attempt        = (int) ($delivery->header($attemptHeader) ?? 0) + 1;

    if ($attempt > $failuresWanted) {
        fwrite(STDOUT, "handled {$delivery->body} on attempt $attempt" . PHP_EOL);
        fflush(STDOUT);

        return;
    }

    $channel = $delivery->channel();

    if ($channel === null) {
        // The channel died under the handler; the job is unacknowledged, so the broker
        // hands it back on its own.
        return;
    }

    $delayMs = (int) round($schedule->delaySecondsFor($attempt) * 1000);

    fwrite(STDOUT, "failed {$delivery->body} on attempt $attempt, back in {$delayMs}ms" . PHP_EOL);
    fflush(STDOUT);

    // The channel the consumer lent this handler, not one from the outer scope: it is
    // nobody else's for as long as the handler runs, so the confirms below are this
    // message's own.
    //
    // Confirmed, and retried when the broker refuses. Returning from this handler is what
    // acknowledges the delivery that brought the job here, so by then this publish is the
    // only copy of it left: a republish that quietly failed would lose the job outright.
    $channel->queue($delivery->routingKey)->publishConfirmed(
        message: new Message(
            body: $delivery->body,
            persistent: true,
            headers: [$attemptHeader => $attempt] + $delivery->properties->headers,
        ),
        timeoutSeconds: 5.0,
        delayMs: $delayMs,
        retries: 3,
        retryDelaysSeconds: [0.1, 0.2],
    );
}

TestApplication::init();

$queueConsumer = QueueConsumer::fromArgs($_SERVER['argv'] ?? []);

$connection = TestAmqpResolver::getConnection();

// The worker declares its own topology before consuming. QueueConsumer never does —
// a runtime that redeclared a queue with the wrong flags would take the channel down
// with a 406 — but this script owns these queues, and without the declaration a pool
// started before its first publisher would crash-loop on a 404.
$topologyChannel = $connection->channel();

// How long a failed job waits before it comes back, by attempt number. The same list
// drives both sides: the wait queues declared below, and the delay the handler asks for.
// Short here because tests wait for it; a real worker measures these in seconds.
$retrySchedule = new RetrySchedule([0.2, 0.5]);

$retryDelaysMs = array_map(
    static fn(float $seconds): int => (int) round($seconds * 1000),
    $retrySchedule->delaysSeconds,
);

// The header the attempt count travels in. x-death would do for a single fixed delay, but
// this worker picks the delay per attempt, and for that it needs a number of its own.
$attemptHeader = 'x-attempt';

foreach ($queueConsumer->queueSpecs() as $spec) {
    $topologyChannel->queue($spec->name)->declare(durable: true);

    // The wait queues a delayed publish goes through. Declared here for the same reason
    // the queues above are: the runtime declares nothing, and a delay nothing serves would
    // route the job into a queue that is not there.
    RetryTopology::declare(
        channel: $topologyChannel,
        queue: $spec->name,
        delaysMs: $retryDelaysMs,
    );
}

$topologyChannel->close();

$handled = $queueConsumer->consume(
    connection: $connection,
    handler: static function (Delivery $delivery) use ($retrySchedule, $attemptHeader): void {
        $body = $delivery->body;

        if (str_starts_with($body, 'sleep:')) {
            Sleeper::usleep(
                microseconds: (int) substr($body, strlen('sleep:')) * 1000,
            );
        }

        if ($body === 'reject') {
            $delivery->reject();

            return;
        }

        if (str_starts_with($body, 'retry:')) {
            handleRetry(
                delivery: $delivery,
                schedule: $retrySchedule,
                attemptHeader: $attemptHeader,
            );

            return;
        }

        // Returning acknowledges it: the runtime settles what the handler left open.
        fwrite(STDOUT, 'handled ' . $body . PHP_EOL);
        fflush(STDOUT);
    },
);

fwrite(STDOUT, "consumer finished handled=$handled" . PHP_EOL);

$connection->close();
