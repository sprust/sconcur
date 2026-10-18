<?php

declare(strict_types=1);

namespace SConcur\Connection;

use Closure;
use SConcur\Dto\RunningTaskDto;
use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\ExtensionCallException;
use SConcur\Exceptions\ExtensionNotLoadedException;
use SConcur\Exceptions\IncompatibleExtensionVersionException;
use SConcur\Exceptions\MsgpackObjectSupportDisabledException;
use SConcur\Transport\MessagePackTransport;
use SConcur\Transport\PayloadInterface;
use function SConcur\Extension\armPreemption;
use function SConcur\Extension\destroy;
use function SConcur\Extension\disarmPreemption;
use function SConcur\Extension\amqpReopenConsumer;
use function SConcur\Extension\amqpStopConsuming;
use function SConcur\Extension\httpStopAccepting;
use function SConcur\Extension\next;
use function SConcur\Extension\push;
use function SConcur\Extension\socketStopAccepting;
use function SConcur\Extension\stopFlow;
use function SConcur\Extension\tasksCount;
use function SConcur\Extension\version;
use function SConcur\Extension\wait;
use function SConcur\Extension\waitAny;
use function SConcur\Extension\waitAnyBatch;
use function SConcur\Extension\waitAnyTimeout;
use function SConcur\Extension\waitAnyTimeoutBatch;
use function SConcur\Extension\wsStopAccepting;

class Extension
{
    /**
     * The exact "sconcur" extension version this package is built against. The PHP
     * package and the extension are versioned and released together, so the loaded
     * .so must match this exactly (see checkExtension); bump it whenever the PHP <-> extension
     * protocol changes (payload keys, exported functions) so a mismatched .so is
     * rejected instead of silently misbehaving. Public so tooling (bin/sconcur-status)
     * can report the version the package expects.
     */
    public const string REQUIRED_EXTENSION_VERSION = '0.16.0';

    /*
     * Every wait answers with ready TaskResultDto objects. The core instantiates
     * them on this thread, inside the wait call, by writing the declared
     * properties of src/Dto/TaskResultDto.php in place (the result builder in
     * ext/sconcur.c); only the feature payload inside is still MessagePack,
     * decoded once by the feature. A wait that fails throws TaskErrorException
     * from the core itself, and a timed wait that saw nothing answers null.
     */

    protected static ?Extension $instance = null;

    protected static bool $checked     = false;
    protected static int $tasksCounter = 0;

    private function __construct()
    {
        $this->checkExtension();
    }

    public static function get(): Extension
    {
        return static::$instance ??= new Extension();
    }

    public function push(string $flowKey, PayloadInterface $payload, int $ownerFiberId = 0): RunningTaskDto
    {
        ++static::$tasksCounter;

        $taskKey = $flowKey . ':' . static::$tasksCounter;

        $response = push($flowKey, $payload->getMethod()->value, $taskKey, MessagePackTransport::pack($payload), $ownerFiberId);

        static::checkCallResponse(flowKey: $flowKey, response: $response);

        return new RunningTaskDto(
            key: $taskKey,
        );
    }

    public function next(string $flowKey, string $taskKey, int $ownerFiberId = 0): RunningTaskDto
    {
        $response = next($flowKey, $taskKey, $ownerFiberId);

        static::checkCallResponse(flowKey: $flowKey, response: $response);

        return new RunningTaskDto(
            key: $taskKey,
        );
    }

    public function wait(string $flowKey): TaskResultDto
    {
        return wait($flowKey);
    }

    /**
     * Waits for the first ready result of any flow. This is the single global
     * wait point the scheduler uses so flows progress concurrently instead of
     * each one blocking on its own channel.
     */
    public function waitAny(): TaskResultDto
    {
        return waitAny();
    }

    /**
     * waitAny with a deadline: returns null if no result became ready within
     * $timeoutMs, so a blocking caller (the HTTP serve loop) can wake to check for
     * a shutdown signal even on an idle server.
     */
    public function waitAnyTimeout(int $timeoutMs): ?TaskResultDto
    {
        return waitAnyTimeout($timeoutMs);
    }

    /**
     * waitAny draining the batch: blocks for the first ready result exactly like
     * waitAny(), then returns it together with every further result that was
     * already ready — up to $maxResults — all in one crossing. The batch
     * never waits to fill up, so the first result's latency is unchanged.
     *
     * Only the first result's totalExecutionMs carries the wait: the tail was
     * already ready when the crossing returned and waited for nothing.
     *
     * @return non-empty-list<TaskResultDto>
     */
    public function waitAnyBatch(int $maxResults): array
    {
        return waitAnyBatch($maxResults);
    }

    /**
     * waitAnyBatch with a deadline for the first result: returns null if nothing
     * became ready within $timeoutMs, so a blocking caller (the serve loop) can
     * wake to check for a shutdown signal even on an idle server.
     *
     * @return non-empty-list<TaskResultDto>|null
     */
    public function waitAnyTimeoutBatch(int $timeoutMs, int $maxResults): ?array
    {
        return waitAnyTimeoutBatch($timeoutMs, $maxResults);
    }

    public function count(): int
    {
        return tasksCount();
    }

    public function stopFlow(string $flowKey): void
    {
        stopFlow($flowKey);
    }

    /**
     * Stops the HTTP server flow's listener from accepting new connections,
     * without cancelling in-flight requests. Lets a SO_REUSEPORT sibling take over
     * new connections while this process drains on graceful shutdown.
     */
    public function httpStopAccepting(string $flowKey): void
    {
        httpStopAccepting($flowKey);
    }

    /**
     * Stops the socket server flow's listener from accepting new connections and
     * half-closes its in-flight connections, so a SO_REUSEPORT sibling takes over
     * new connections while this process drains on graceful shutdown.
     */
    public function socketStopAccepting(string $flowKey): void
    {
        socketStopAccepting($flowKey);
    }

    /**
     * Cancels the consumers of an AMQP consumer flow, leaving its channels open, so the
     * deliveries already handed to PHP can still be acknowledged while their handlers
     * finish. The channels themselves go with the flow.
     */
    public function amqpStopConsuming(string $flowKey): void
    {
        amqpStopConsuming($flowKey);
    }

    /**
     * Cancels the consumer an AMQP consumer flow is reading one channel through, so that
     * queue is opened again on a fresh channel a second later — the same recovery a
     * consumer the broker takes away already gets.
     *
     * The counterpart of amqpStopConsuming, for the failure only this side can see: a
     * delivery that could not be settled is still owed to the broker, which has heard
     * nothing about it, so the consumer holding it would be sent nothing further until
     * the broker's own consumer timeout. Giving the channel up hands the message back
     * for another attempt. A flow that is already draining is left alone.
     */
    public function amqpReopenConsumer(string $flowKey, string $channelId): void
    {
        amqpReopenConsumer($flowKey, $channelId);
    }

    /**
     * Stops the WebSocket server flow's listener from accepting new connections and
     * drains its in-flight connections, so a SO_REUSEPORT sibling takes over new
     * connections while this process drains on graceful shutdown.
     */
    public function wsStopAccepting(string $flowKey): void
    {
        wsStopAccepting($flowKey);
    }

    public function destroy(): void
    {
        destroy();
    }

    public function version(): string
    {
        return version();
    }

    /**
     * Starts automatic preemption: the extension's timer requests a VM interrupt
     * every $quantumMs, and the engine calls $preemptCallback between opcodes on
     * the PHP thread (the Scheduler's preempt hook parking the current
     * coroutine). Re-arming replaces the previous timer and callback.
     */
    public function armPreemption(int $quantumMs, Closure $preemptCallback): void
    {
        armPreemption($quantumMs, $preemptCallback);
    }

    public function disarmPreemption(): void
    {
        disarmPreemption();
    }

    protected static function checkCallResponse(string $flowKey, string $response): void
    {
        if (!str_starts_with($response, 'error:')) {
            return;
        }

        throw new ExtensionCallException(
            message: sprintf(
                'flow %s: %s',
                $flowKey,
                $response,
            ),
        );
    }

    private function checkExtension(): void
    {
        if (static::$checked) {
            return;
        }

        if (!extension_loaded('sconcur')) {
            throw new ExtensionNotLoadedException(
                message: 'The extension "sconcur" is not loaded.',
            );
        }

        $loadedVersion = version();

        if (version_compare($loadedVersion, self::REQUIRED_EXTENSION_VERSION, '!=')) {
            throw new IncompatibleExtensionVersionException(
                message: sprintf(
                    'The loaded "sconcur" extension version %s does not match the required %s.',
                    $loadedVersion,
                    self::REQUIRED_EXTENSION_VERSION,
                ),
            );
        }

        self::checkMsgpackObjectSupport();

        static::$checked = true;
    }

    /**
     * The boundary carries MessagePack, and some payloads carry PHP objects in it
     * (the BSON value objects of the MongoDB feature). ext-msgpack only reads and
     * writes those with msgpack.php_only enabled: with it off, packing loses the
     * class and unpacking warns about an illegal key type and yields plain arrays.
     *
     * The setting is PHP_INI_ALL, so it is forced here rather than merely
     * required — but a build that refuses the change must say so at startup
     * instead of mangling documents later.
     */
    private static function checkMsgpackObjectSupport(): void
    {
        if (!extension_loaded('msgpack')) {
            throw new MsgpackObjectSupportDisabledException(
                message: 'The extension "msgpack" is not loaded.',
            );
        }

        if (ini_get('msgpack.php_only') !== '1') {
            ini_set('msgpack.php_only', '1');
        }

        if (ini_get('msgpack.php_only') !== '1') {
            throw new MsgpackObjectSupportDisabledException(
                message: 'msgpack.php_only could not be enabled: payloads carry PHP objects, '
                    . 'and with it off every one of them silently decodes to a plain array.',
            );
        }
    }

    public function __destruct()
    {
        $this->destroy();
    }
}
