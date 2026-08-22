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

/**
 * The boundary between the feature's internals and the calque's public API: it runs one
 * command through FeatureExecutor and turns whatever went wrong into the AMQP*Exception
 * the caller expects, with the reply code the broker named.
 *
 * Only the failure translation lives here. Running a command belongs to AmqpResource,
 * which pairs it with the bookkeeping a failure implies — a channel the broker closed has
 * to be marked closed — and a second entry point that skipped that bookkeeping was a trap
 * waiting for its first caller.
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
