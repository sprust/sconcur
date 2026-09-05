<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Support;

use SConcur\Exceptions\Amqp\AmqpException;
use SConcur\Exceptions\Amqp\ChannelException;
use SConcur\Exceptions\Amqp\ConnectionException;
use Throwable;

/**
 * What the extension side said about a command it could not run: which of the three things failed,
 * the AMQP reply code, and the text.
 *
 * It travels as the message of the failed task — `"<scope>:<code>: <text>"` — because a task
 * result carries one payload and nothing else. This is the one place that reads it back.
 *
 * Rust: error_payload (ext/src/features/amqp/mod.rs).
 */
readonly class AmqpFailure
{
    protected const string PATTERN = '/^(net|chn|chg|err):(\d+): (.*)$/s';

    protected function __construct(
        public FailureScopeEnum $scope,
        public int $code,
        public string $text,
    ) {
    }

    /**
     * Reads the scope off a failed command, or answers null when the failure carries none —
     * it was raised before the feature could scope it, so it touched nothing.
     */
    public static function from(Throwable $exception): ?self
    {
        if (preg_match(self::PATTERN, $exception->getMessage(), $matches) !== 1) {
            return null;
        }

        return new self(
            scope: FailureScopeEnum::from($matches[1]),
            code: (int) $matches[2],
            text: $matches[3],
        );
    }

    /**
     * The exception this failure is raised as.
     *
     * A dead connection and a method the broker refused are different failures, and only one
     * is worth retrying on the same objects — so a connection-level scope always becomes a
     * ConnectionException whichever class the caller asked for, and a channel that was
     * already gone always a ChannelException. The latter carries whatever reply code closed
     * it, which is how the 404 an earlier publish ran into becomes visible.
     *
     * @param class-string<AmqpException> $exceptionClass what the caller expects otherwise
     */
    public function exception(string $exceptionClass, Throwable $previous): AmqpException
    {
        if ($this->scope === FailureScopeEnum::Connection) {
            return new ConnectionException(
                message: $this->text,
                code: $this->code,
                previous: $previous,
            );
        }

        if (
            $this->scope === FailureScopeEnum::ChannelGone
            || ($this->scope === FailureScopeEnum::Channel && $this->code === 0)
        ) {
            return new ChannelException(
                message: $this->text,
                code: $this->code,
                previous: $previous,
            );
        }

        return new $exceptionClass(
            message: $this->text,
            code: $this->code,
            previous: $previous,
        );
    }

    /**
     * The exception a failure is raised as, whether or not it carries a scope — for a caller
     * that has no resource to mark and only wants the right class.
     *
     * @param class-string<AmqpException> $exceptionClass
     */
    public static function translate(Throwable $exception, string $exceptionClass): AmqpException
    {
        $failure = static::from($exception);

        if ($failure === null) {
            return new $exceptionClass(
                message: $exception->getMessage(),
                previous: $exception,
            );
        }

        return $failure->exception(
            exceptionClass: $exceptionClass,
            previous: $exception,
        );
    }
}
