<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Support;

use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\TaskExecutionException;
use SConcur\Features\Amqp\AMQPChannel;
use SConcur\Features\Amqp\AMQPConnection;
use SConcur\Features\Amqp\AMQPChannelException;
use SConcur\Features\Amqp\AMQPException;
use SConcur\Features\Amqp\AMQPQueue;
use SConcur\Features\FeatureExecutor;
use SConcur\Transport\PayloadInterface;
use WeakReference;

/**
 * The internal base of the four calque classes that own a resource living on the Go side.
 * Everything here is on the base rather than on the classes themselves because PHP only
 * lets one object read another's protected state through a common declaring class:
 *
 * - the id of the resource on the Go side (the connection handle for AMQPConnection, the
 *   channel for AMQPChannel), which AMQPChannel, AMQPQueue and AMQPExchange must read off
 *   the object they were constructed with;
 * - whether that resource is still open, which a failed command has to be able to clear on
 *   the channel it ran on, whichever object issued the command;
 * - the channel's consumer registry, which AMQPQueue writes when it starts consuming and
 *   reads when a delivery has to be routed back to the queue that owns its consumer tag.
 *
 * runCommand lives here for the same reason: it is the one place that needs all three.
 *
 * Keeping them here is what lets the public surface of the calque stay byte-for-byte the
 * one ext-amqp exposes — no extra getters had to be invented for the feature's own use.
 */
abstract class AmqpResource
{
    /** The id of the resource on the Go side; empty while nothing is open. */
    protected string $internalId = '';

    /**
     * Whether the resource is open: connected for AMQPConnection, an open channel for
     * AMQPChannel.
     */
    protected bool $internalOpen = false;

    /**
     * The channels opened on this connection, by their Go-side id. Only AMQPConnection
     * fills it, and only so disconnect() can tell them they are gone: releasing the
     * handle closes them on the Go side, and a channel object that still reported itself
     * open would send the caller into a command that cannot succeed — the ext-amqp guard
     * `if (!$channel->isConnected()) { …reopen… }` would never fire.
     *
     * Weak, for the same reason the consumer registry is: a strong reference would keep
     * every channel an application dropped alive for as long as the connection lives.
     *
     * @var array<string, WeakReference<AMQPChannel>>
     */
    protected array $internalChannels = [];

    /**
     * Consumers registered on this channel, by the consumer tag the broker assigned. Only
     * AMQPChannel fills it.
     *
     * The references are weak on purpose: a strong one would keep an AMQPQueue — and
     * through it the channel it consumes on — alive for as long as the channel object
     * lives, so a coroutine that ends without cancelling its consumer would leave the
     * Go-side channel open with nothing left to close it.
     *
     * @var array<string, WeakReference<AMQPQueue>>
     */
    protected array $internalConsumers = [];

    /**
     * Marks every channel of this connection closed. Called when the handle behind them
     * is released, which is what actually closed them on the Go side.
     */
    protected function forgetChannels(): void
    {
        foreach ($this->internalChannels as $reference) {
            $channel = $reference->get();

            if ($channel !== null) {
                $channel->internalOpen = false;
            }
        }

        $this->internalChannels = [];
    }

    /**
     * Releases the delivery stream this object holds, if it holds one. Only AMQPQueue
     * does; it is declared here so an AMQPChannel can release the streams of its consumers
     * when it closes.
     */
    protected function releaseConsumeStream(): void
    {
    }

    /**
     * ext-amqp keeps its timeouts in (fractional) seconds; the wire carries milliseconds,
     * as everywhere else in the project.
     */
    protected static function toMilliseconds(float $seconds): int
    {
        return (int) round($seconds * 1000);
    }

    /**
     * Runs one command against the broker and keeps the channel's state honest.
     *
     * A channel the broker closed over a failure cannot be used again: the extension
     * refuses the next call locally with "No channel available.", and so does this. The
     * failure marks the channel closed, and the guard below turns every later call into
     * that same AMQPChannelException instead of a round trip that cannot succeed.
     *
     * @param class-string<AMQPException> $exceptionClass the exception a protocol-level
     *                                                    failure is raised as
     * @param AMQPChannel|null            $channel        the channel the command runs on,
     *                                                    null for the connection-level ones
     * @param string                      $operation      what the caller was doing, for the
     *                                                    message of a closed-channel failure
     *
     * @return array<mixed>
     */
    protected function runCommand(
        PayloadInterface $payload,
        string $exceptionClass,
        ?AMQPChannel $channel = null,
        string $operation = '',
    ): array {
        return CommandRunner::decode(
            $this->runStreamCommand(
                payload: $payload,
                exceptionClass: $exceptionClass,
                channel: $channel,
                operation: $operation,
            ),
        );
    }

    /**
     * Records what a failure did to the resources it touched: a channel the broker closed
     * cannot be used again, and a connection that died takes its channels with it. The
     * extension keeps the same bookkeeping, which is what makes `if
     * (!$connection->isConnected()) { $connection->reconnect(); }` work.
     */
    protected function markFailure(CommandFailure $failure, ?AMQPChannel $channel): void
    {
        if ($failure->scope === FailureScopeEnum::Command) {
            return;
        }

        if ($channel !== null) {
            $channel->internalOpen = false;

            // The streams go with the consumers, exactly as AMQPChannel::close() does
            // it. Dropping the registry alone would leave every other AMQPQueue of this
            // channel holding a live stream key: outside a coroutine that flow lives for
            // the process, and AMQP_JUST_CONSUME would later pull a key that leads
            // nowhere.
            foreach ($channel->getConsumers() as $queue) {
                $queue->releaseConsumeStream();
            }

            $channel->internalConsumers = [];
        }

        if ($failure->scope !== FailureScopeEnum::Connection) {
            return;
        }

        // The connection handle itself is kept: disconnect() still has to hand it back,
        // or the pooled connection behind it would never be released.
        $connection = $this->connectionOf($channel);

        if ($connection !== null) {
            $connection->internalOpen = false;
        }
    }

    /**
     * The connection a failed command was running on: the channel's, or this object when
     * it is the connection itself.
     */
    protected function connectionOf(?AMQPChannel $channel): ?AMQPConnection
    {
        if ($this instanceof AMQPConnection) {
            return $this;
        }

        return $channel?->getConnection();
    }

    /**
     * runCommand for a streaming command: the raw task result is kept, because its key is
     * the handle every later next() is pulled by.
     *
     * @param class-string<AMQPException> $exceptionClass
     */
    protected function runStreamCommand(
        PayloadInterface $payload,
        string $exceptionClass,
        ?AMQPChannel $channel = null,
        string $operation = '',
    ): TaskResultDto {
        if ($channel !== null && !$channel->internalOpen) {
            throw new AMQPChannelException(
                message: trim("$operation No channel available."),
            );
        }

        try {
            return FeatureExecutor::exec(payload: $payload);
        } catch (TaskErrorException | TaskExecutionException $exception) {
            $failure = CommandRunner::failure($exception);

            $this->markFailure(failure: $failure, channel: $channel);

            throw CommandRunner::exception(
                failure: $failure,
                exceptionClass: $exceptionClass,
                exception: $exception,
            );
        }
    }

    /**
     * Pulls the next batch of a streaming command, keeping the same bookkeeping: a stream
     * that fails because its channel is gone must leave that channel marked closed.
     *
     * @param class-string<AMQPException> $exceptionClass
     */
    protected function nextStream(string $taskKey, string $exceptionClass, ?AMQPChannel $channel = null): TaskResultDto
    {
        try {
            return FeatureExecutor::next(taskKey: $taskKey);
        } catch (TaskErrorException | TaskExecutionException $exception) {
            $failure = CommandRunner::failure($exception);

            $this->markFailure(failure: $failure, channel: $channel);

            throw CommandRunner::exception(
                failure: $failure,
                exceptionClass: $exceptionClass,
                exception: $exception,
            );
        }
    }
}
