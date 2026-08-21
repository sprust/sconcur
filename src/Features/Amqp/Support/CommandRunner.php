<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Support;

use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\TaskExecutionException;
use SConcur\Features\Amqp\AMQPChannelException;
use SConcur\Features\Amqp\AMQPConnectionException;
use SConcur\Features\Amqp\AMQPException;
use SConcur\Features\FeatureExecutor;
use SConcur\Transport\MessagePackTransport;
use SConcur\Transport\PayloadInterface;

/**
 * The boundary between the feature's internals and the calque's public API: it runs one
 * command through FeatureExecutor and turns whatever went wrong into the AMQP*Exception
 * the caller expects, with the reply code the broker named.
 *
 * The Go side prefixes a failure with its scope and that code ("chn:404: Server channel
 * error: 404, message: …"). A failure the broker answered with a 5xx, or one that means the
 * connection is gone, always becomes AMQPConnectionException — whichever class the caller
 * asked for — because that is the distinction ext-amqp makes between a dead connection and
 * a method the broker refused.
 */
readonly class CommandRunner
{
    /** The shape of a scoped failure payload: "<scope>:<code>: <message>". */
    protected const string FAILURE_PATTERN = '/^(net|chn|err):(\d+): (.*)$/s';

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
     * Runs one command and returns the raw task result — for the streaming commands, whose
     * result key is the handle every later next() is pulled by.
     *
     * @param class-string<AMQPException> $exceptionClass
     */
    public static function execute(PayloadInterface $payload, string $exceptionClass): TaskResultDto
    {
        try {
            return FeatureExecutor::exec(payload: $payload);
        } catch (TaskErrorException | TaskExecutionException $exception) {
            throw static::exception(
                failure: static::failure($exception),
                exceptionClass: $exceptionClass,
                exception: $exception,
            );
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
            throw static::exception(
                failure: static::failure($exception),
                exceptionClass: $exceptionClass,
                exception: $exception,
            );
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
     * Reads the scope and the reply code out of a failure. A message with no prefix (a
     * failure raised before the feature could scope it) is a plain command failure.
     */
    public static function failure(TaskExecutionException|TaskErrorException $exception): CommandFailure
    {
        $message = $exception->getMessage();

        if (preg_match(self::FAILURE_PATTERN, $message, $matches) !== 1) {
            return new CommandFailure(
                scope: FailureScopeEnum::Command,
                code: 0,
                message: $message,
            );
        }

        return new CommandFailure(
            scope: FailureScopeEnum::from($matches[1]),
            code: (int) $matches[2],
            message: $matches[3],
        );
    }

    /**
     * @param class-string<AMQPException> $exceptionClass
     */
    public static function exception(
        CommandFailure $failure,
        string $exceptionClass,
        TaskExecutionException|TaskErrorException $exception,
    ): AMQPException {
        if ($failure->scope === FailureScopeEnum::Connection) {
            return new AMQPConnectionException(
                message: $failure->message,
                code: $failure->code,
                previous: $exception,
            );
        }

        // A channel that is simply gone — no reply code, nothing the caller did wrong — is
        // reported as such whichever method ran into it, the way the extension refuses a
        // call on a stale channel. A reply code means the broker refused this particular
        // method, and that is the caller's exception, carrying the code.
        if ($failure->scope === FailureScopeEnum::Channel && $failure->code === 0) {
            return new AMQPChannelException(
                message: $failure->message,
                previous: $exception,
            );
        }

        return new $exceptionClass(
            message: $failure->message,
            code: $failure->code,
            previous: $exception,
        );
    }
}
