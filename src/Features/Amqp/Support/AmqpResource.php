<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Support;

use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\Amqp\AmqpException;
use SConcur\Exceptions\Amqp\ChannelException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\TaskExecutionException;
use SConcur\Features\Amqp\Channel;
use SConcur\Features\Amqp\Connection;
use SConcur\Features\FeatureExecutor;
use SConcur\Transport\PayloadInterface;
use WeakReference;

/**
 * The internal base of the objects that own a resource living on the Go side.
 *
 * Two pieces of state are shared rather than private to each class, because PHP only lets
 * one object read another's protected state through a common declaring class:
 *
 * - the id of the resource on the Go side (the connection handle for Connection, the
 *   channel for Channel), which a Channel reads off its Connection and a Queue or an
 *   Exchange reads off its Channel;
 * - whether that resource is still open, which a failed command has to be able to clear on
 *   the channel it ran on, whichever object issued the command.
 *
 * runCommand lives here for the same reason: it is the one place that needs both, and it
 * pairs running a command with the bookkeeping a failure implies.
 */
abstract class AmqpResource
{
    /** The id of the resource on the Go side; empty while nothing is open. */
    protected string $internalId = '';

    /**
     * Whether the resource is open: connected for Connection, an open channel for Channel.
     */
    protected bool $internalOpen = false;

    /**
     * The channels opened on this connection, by their Go-side id. Only Connection fills
     * it, and only so close() can tell them they are gone: releasing the handle closes
     * them on the Go side, and a channel object that still reported itself open would send
     * the caller into a command that cannot succeed.
     *
     * Weak on purpose: a strong reference would keep every channel an application dropped
     * alive for as long as the connection lives.
     *
     * @var array<string, WeakReference<Channel>>
     */
    protected array $internalChannels = [];

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

    /** Seconds, as the API takes them, into the milliseconds the wire carries. */
    protected static function toMilliseconds(float $seconds): int
    {
        return (int) round($seconds * 1000);
    }

    /**
     * Runs one command against the broker and keeps the channel's state honest.
     *
     * A channel the broker closed over a failure cannot be used again: the failure marks
     * the channel closed, and the guard in runStreamCommand turns every later call into a
     * ChannelException instead of a round trip that cannot succeed.
     *
     * @param class-string<AmqpException> $exceptionClass the exception a protocol-level
     *                                                    failure is raised as
     * @param Channel|null                $channel        the channel the command runs on,
     *                                                    null for the connection-level ones
     * @param string                      $operation      what the caller was doing, for the
     *                                                    message of a closed-channel failure
     *
     * @return array<mixed>
     */
    protected function runCommand(
        PayloadInterface $payload,
        string $exceptionClass,
        ?Channel $channel = null,
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
     * cannot be used again, and a connection that died takes its channels with it.
     */
    protected function markFailure(CommandFailure $failure, ?Channel $channel): void
    {
        if ($failure->scope === FailureScopeEnum::Command) {
            return;
        }

        if ($channel !== null) {
            $channel->internalOpen = false;
        }

        if ($failure->scope !== FailureScopeEnum::Connection) {
            return;
        }

        // The connection handle itself is kept: close() still has to hand it back, or the
        // pooled connection behind it would never be released.
        $connection = $this->connectionOf($channel);

        if ($connection !== null) {
            $connection->internalOpen = false;
        }
    }

    /**
     * The connection a failed command was running on: the channel's, or this object when
     * it is the connection itself.
     */
    protected function connectionOf(?Channel $channel): ?Connection
    {
        if ($this instanceof Connection) {
            return $this;
        }

        return $channel?->connection();
    }

    /**
     * runCommand for a streaming command: the raw task result is kept, because its key is
     * the handle every later next() is pulled by.
     *
     * @param class-string<AmqpException> $exceptionClass
     */
    protected function runStreamCommand(
        PayloadInterface $payload,
        string $exceptionClass,
        ?Channel $channel = null,
        string $operation = '',
    ): TaskResultDto {
        if ($channel !== null && !$channel->internalOpen) {
            throw new ChannelException(
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
     * @param class-string<AmqpException> $exceptionClass
     */
    protected function nextStream(string $taskKey, string $exceptionClass, ?Channel $channel = null): TaskResultDto
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
