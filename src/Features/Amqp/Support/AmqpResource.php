<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Support;

use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\TaskExecutionException;
use SConcur\Features\Amqp\AMQPChannel;
use SConcur\Features\Amqp\AMQPChannelException;
use SConcur\Features\Amqp\AMQPException;
use SConcur\Features\Amqp\AMQPQueue;
use SConcur\Features\FeatureExecutor;
use SConcur\Transport\PayloadInterface;

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
     * Consumers registered on this channel, by the consumer tag the broker assigned.
     * Only AMQPChannel fills it.
     *
     * @var array<string, AMQPQueue>
     */
    protected array $internalConsumers = [];

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

            // A dead channel and a dead connection both leave this channel unusable; only
            // a plain command failure leaves it open.
            if ($channel !== null && $failure->scope !== FailureScopeEnum::Command) {
                $channel->internalOpen      = false;
                $channel->internalConsumers = [];
            }

            throw CommandRunner::exception(
                failure: $failure,
                exceptionClass: $exceptionClass,
                exception: $exception,
            );
        }
    }
}
