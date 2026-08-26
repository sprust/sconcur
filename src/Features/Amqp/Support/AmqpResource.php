<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Support;

use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\Amqp\AmqpException;
use SConcur\Exceptions\Amqp\ChannelException;
use SConcur\Exceptions\Amqp\InvalidPrefetchException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\TaskExecutionException;
use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\Amqp\Channel;
use SConcur\Features\Amqp\Connection;
use SConcur\Features\Amqp\Payloads\AmqpPayload;
use SConcur\Features\FeatureExecutor;
use SConcur\Transport\MessagePackTransport;
use WeakReference;

/**
 * The internal base of the two objects that own something on the Go side — a Connection and
 * a Channel.
 *
 * They share a base because PHP only lets one object read another's protected state through
 * a common declaring class, and these two have to read each other's handle and clear each
 * other's open flag: a failed command marks whatever the broker took away closed, whichever
 * object issued it.
 *
 * Queue and Exchange own nothing there, and go through Channel::run().
 */
abstract class AmqpResource
{
    protected const int MAX_PREFETCH_COUNT = 65535;

    protected const int MAX_PREFETCH_SIZE_BYTES = 4294967295;

    /** The id on the Go side; empty while nothing is open. */
    protected string $internalId = '';

    protected bool $internalOpen = false;

    /**
     * The channels opened on this connection, by their Go-side id. Only Connection fills it,
     * and only so close() can tell them they are gone.
     *
     * Weak on purpose: a strong reference would keep every channel an application dropped
     * alive for as long as the connection lives.
     *
     * @var array<string, WeakReference<Channel>>
     */
    protected array $internalChannels = [];

    /**
     * The connection a failure on this resource speaks for: a Connection answers with
     * itself, a Channel with the one it was opened on. Never null — both are known from
     * the moment the object exists, which is what makes this usable from a constructor.
     */
    abstract protected function ownConnection(): Connection;

    /**
     * Marks every channel of this connection closed and forgets the handle each held.
     *
     * Releasing the connection handle is what closed them on the Go side, so there is
     * nothing left to hand back — and a channel that kept its id would send a ChannelClose
     * for a channel that no longer exists.
     */
    protected function forgetChannels(): void
    {
        foreach ($this->internalChannels as $reference) {
            $channel = $reference->get();

            if ($channel !== null) {
                $channel->internalOpen = false;
                $channel->internalId   = '';
            }
        }

        $this->internalChannels = [];
    }

    /** Screens the prefetch values a channel is opened with, and the ones basic.qos changes. */
    protected static function assertPrefetch(int $count, int $sizeBytes): void
    {
        if ($count < 0 || $count > self::MAX_PREFETCH_COUNT) {
            throw new InvalidPrefetchException(
                message: "Parameter 'prefetchCount' must be between 0 and " . self::MAX_PREFETCH_COUNT . '.',
            );
        }

        if ($sizeBytes < 0 || $sizeBytes > self::MAX_PREFETCH_SIZE_BYTES) {
            throw new InvalidPrefetchException(
                message: "Parameter 'prefetchSizeBytes' must be between 0 and "
                    . self::MAX_PREFETCH_SIZE_BYTES . '.',
            );
        }
    }

    /** Seconds, as the API takes them, into the milliseconds the wire carries. */
    protected static function toMilliseconds(float $seconds): int
    {
        return (int) round($seconds * 1000);
    }

    /**
     * An empty payload is an empty answer, not a decoding failure: several commands report
     * only that they succeeded.
     *
     * @return array<mixed>
     */
    protected static function decode(TaskResultDto $result): array
    {
        if ($result->payload === '') {
            return [];
        }

        return MessagePackTransport::unpack($result->payload);
    }

    /**
     * @param array<string, mixed>        $data           the parameters, by their wire keys
     * @param class-string<AmqpException> $exceptionClass what a protocol-level failure is
     *                                                    raised as
     * @param Channel|null                $channel        null for a connection-level command
     * @param string                      $operation      what the caller was doing, for the
     *                                                    message of a closed-channel failure
     *
     * @return array<mixed>
     */
    protected function runCommand(
        AmqpCommandEnum $command,
        array $data,
        string $exceptionClass,
        ?Channel $channel = null,
        string $operation = '',
    ): array {
        return static::decode(
            $this->runStreamCommand(
                command: $command,
                data: $data,
                exceptionClass: $exceptionClass,
                channel: $channel,
                operation: $operation,
            ),
        );
    }

    /**
     * runCommand for a streaming command: the raw result is kept, because its key is the
     * handle every later next() is pulled by.
     *
     * @param array<string, mixed>        $data
     * @param class-string<AmqpException> $exceptionClass
     */
    protected function runStreamCommand(
        AmqpCommandEnum $command,
        array $data,
        string $exceptionClass,
        ?Channel $channel = null,
        string $operation = '',
    ): TaskResultDto {
        // Refused here rather than in a round trip that cannot succeed.
        if ($channel !== null && !$channel->internalOpen) {
            throw new ChannelException(
                message: trim("$operation No channel available."),
            );
        }

        try {
            return FeatureExecutor::exec(payload: new AmqpPayload(command: $command, data: $data));
        } catch (TaskErrorException | TaskExecutionException $exception) {
            throw $this->translate(
                exception: $exception,
                exceptionClass: $exceptionClass,
                channel: $channel,
            );
        }
    }

    /**
     * The next batch of a streaming command, with the same bookkeeping: a stream that fails
     * because its channel is gone leaves that channel marked closed.
     *
     * @param class-string<AmqpException> $exceptionClass
     */
    protected function nextStream(string $taskKey, string $exceptionClass, ?Channel $channel = null): TaskResultDto
    {
        try {
            return FeatureExecutor::next(taskKey: $taskKey);
        } catch (TaskErrorException | TaskExecutionException $exception) {
            throw $this->translate(
                exception: $exception,
                exceptionClass: $exceptionClass,
                channel: $channel,
            );
        }
    }

    /**
     * Turns a failed command into the exception the caller expects, and records what the
     * failure did to the resources it touched on the way.
     *
     * The exception itself is AmqpFailure's business; what is left here is the bookkeeping,
     * which is the only reason a Connection and a Channel share a base at all.
     *
     * @param class-string<AmqpException> $exceptionClass
     */
    protected function translate(
        TaskExecutionException|TaskErrorException $exception,
        string $exceptionClass,
        ?Channel $channel,
    ): AmqpException {
        $failure = AmqpFailure::from($exception);

        if ($failure === null) {
            // Raised before the feature could scope it, so it touched nothing.
            return new $exceptionClass(
                message: $exception->getMessage(),
                previous: $exception,
            );
        }

        if ($failure->scope !== FailureScopeEnum::Command && $channel !== null) {
            $channel->internalOpen = false;
        }

        if ($failure->scope === FailureScopeEnum::Connection) {
            // The handle is kept: close() still has to hand it back, or the pooled
            // connection behind it would never be released.
            //
            // Asked of the resource rather than read off $channel: a channel that fails
            // while it is being opened has not been passed to runCommand yet, and reading
            // the connection off that argument left the one that died reporting itself open
            // for good.
            $this->ownConnection()->internalOpen = false;
        }

        return $failure->exception(
            exceptionClass: $exceptionClass,
            previous: $exception,
        );
    }
}
