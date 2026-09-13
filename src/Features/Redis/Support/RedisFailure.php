<?php

declare(strict_types=1);

namespace SConcur\Features\Redis\Support;

use SConcur\Exceptions\Redis\InvalidRedisArgumentException;
use SConcur\Exceptions\Redis\InvalidRedisDsnException;
use SConcur\Exceptions\Redis\RedisCommandException;
use SConcur\Exceptions\Redis\RedisConnectionException;
use SConcur\Exceptions\Redis\RedisException;
use SConcur\Exceptions\Redis\RedisTimeoutException;
use SConcur\Exceptions\Redis\SubscriptionClosedException;
use SConcur\Exceptions\Redis\UnsupportedRedisCommandException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\TaskExecutionException;
use Throwable;

/**
 * Turns a failed task into the exception the caller expects.
 *
 * The kind is read, not guessed: the core writes it as `redis[<kind>]: <text>`
 * (ext/src/features/redis/errors.rs) and everything after that prefix is opaque.
 *
 * This used to classify by matching the message, which was wrong in a way worth
 * remembering: half of that message belongs to the server, and a Lua script
 * answering `redis.error_reply("connect: ...")` could pick which exception the
 * application caught — including the two that are LogicExceptions and so slip
 * past a catch written for RedisException.
 */
readonly class RedisFailure
{
    /**
     * The kinds the core writes, and what each is raised as. A kind missing from
     * here is a core newer than this package, and becomes a plain RedisException
     * rather than a guess.
     *
     * @var array<string, class-string<Throwable>>
     */
    protected const array KINDS = [
        'cmd'     => RedisCommandException::class,
        'conn'    => RedisConnectionException::class,
        'timeout' => RedisTimeoutException::class,
        'refused' => UnsupportedRedisCommandException::class,
        'arg'     => InvalidRedisArgumentException::class,
        'dsn'     => InvalidRedisDsnException::class,
        'stopped' => RedisConnectionException::class,
        'state'   => SubscriptionClosedException::class,
    ];

    public static function from(
        TaskErrorException|TaskExecutionException|Throwable $exception,
    ): Throwable {
        [$kind, $message] = static::split($exception->getMessage());

        $class = static::KINDS[$kind] ?? null;

        if ($class === null) {
            return new RedisException(
                message: $message,
                previous: $exception,
            );
        }

        if ($class === RedisCommandException::class) {
            return new RedisCommandException(
                errorCode: static::errorCode($message),
                message: $message,
                previous: $exception,
            );
        }

        return new $class(
            message: $message,
            previous: $exception,
        );
    }

    /**
     * The kind and the text. A message without the prefix did not come from this
     * feature — a failure raised before the core saw the payload, say — and is
     * passed through whole.
     *
     * @return array{0: string, 1: string}
     */
    protected static function split(string $message): array
    {
        if (preg_match('/^redis\[([a-z]+)]: (.*)$/s', $message, $matches) !== 1) {
            return ['', $message];
        }

        return [$matches[1], $matches[2]];
    }

    /**
     * The Redis error code a command failure starts with: a bare word in
     * capitals, which is what the protocol puts first in an error reply. A
     * message without one keeps an empty code rather than borrowing a word.
     */
    protected static function errorCode(string $message): string
    {
        $first = explode(' ', $message, 2)[0];

        return preg_match('/^[A-Z]{3,}$/', $first) === 1 ? $first : '';
    }
}
