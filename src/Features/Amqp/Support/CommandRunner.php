<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Support;

use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\TaskExecutionException;
use SConcur\Features\Amqp\AMQPConnectionException;
use SConcur\Features\Amqp\AMQPException;
use SConcur\Features\FeatureExecutor;
use SConcur\Transport\MessagePackTransport;
use SConcur\Transport\PayloadInterface;

/**
 * The boundary between the feature's internals and the calque's public API: it runs one
 * command through FeatureExecutor and turns whatever went wrong into the AMQP*Exception
 * the caller expects.
 *
 * A failure the Go side marked as network-class (the broker is unreachable, the
 * connection died mid-command) always becomes AMQPConnectionException, whichever class
 * the caller asked for — the same distinction ext-amqp makes between a dead connection
 * and a protocol error.
 */
readonly class CommandRunner
{
    /** The marker the Go side prefixes onto a network-class failure. */
    protected const string NETWORK_ERROR_MARKER = 'net:';

    /**
     * Runs one command and returns its decoded result (an empty array for the commands
     * that answer with nothing).
     *
     * @param class-string<AMQPException> $exceptionClass the exception class for a
     *                                                    protocol-level failure
     *
     * @return array<mixed>
     */
    public static function run(PayloadInterface $payload, string $exceptionClass): array
    {
        return static::decode(static::execute(payload: $payload, exceptionClass: $exceptionClass));
    }

    /**
     * Runs one command and returns the raw task result — for the streaming commands,
     * whose result key is the handle every later next() is pulled by.
     *
     * @param class-string<AMQPException> $exceptionClass
     */
    public static function execute(PayloadInterface $payload, string $exceptionClass): TaskResultDto
    {
        try {
            return FeatureExecutor::exec(payload: $payload);
        } catch (TaskErrorException | TaskExecutionException $exception) {
            throw static::exception(exceptionClass: $exceptionClass, exception: $exception);
        }
    }

    /**
     * Pulls the next batch of a streaming command (one delivery of a consumer).
     *
     * @param class-string<AMQPException> $exceptionClass
     */
    public static function next(string $taskKey, string $exceptionClass): TaskResultDto
    {
        try {
            return FeatureExecutor::next(taskKey: $taskKey);
        } catch (TaskErrorException | TaskExecutionException $exception) {
            throw static::exception(exceptionClass: $exceptionClass, exception: $exception);
        }
    }

    /**
     * @return array<mixed>
     */
    public static function decode(TaskResultDto $result): array
    {
        if ($result->payload === '') {
            return [];
        }

        return MessagePackTransport::unpack($result->payload);
    }

    /**
     * @param class-string<AMQPException> $exceptionClass
     */
    protected static function exception(string $exceptionClass, TaskExecutionException|TaskErrorException $exception): AMQPException
    {
        $message = $exception->getMessage();

        if (str_starts_with($message, self::NETWORK_ERROR_MARKER)) {
            return new AMQPConnectionException(
                message: $message,
                previous: $exception,
            );
        }

        return new $exceptionClass(
            message: $message,
            previous: $exception,
        );
    }
}
