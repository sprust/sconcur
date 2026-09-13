<?php

declare(strict_types=1);

namespace SConcur\Features\Redis\Payloads;

use SConcur\Features\MethodEnum;
use SConcur\Features\Redis\RedisCommandEnum;
use SConcur\Transport\PayloadInterface;

/**
 * The envelope every Redis command travels in: the sub-operation (`cm`), the connection
 * settings and the command body (`dt`).
 *
 * One class for all of them, like Amqp\Payloads\AmqpPayload: a Redis command is a name and
 * a list of arguments, and a class per command would carry nothing a named argument at the
 * call site does not already carry. The Rust struct each sub-operation's `dt` is decoded
 * into is named on its RedisCommandEnum case.
 *
 * Rust: payloads::Envelope (ext/src/features/redis/payloads.rs).
 */
readonly class RedisPayload implements PayloadInterface
{
    /**
     * @param array<string, mixed> $data the sub-operation's body, by its wire keys
     */
    public function __construct(
        protected RedisCommandEnum $command,
        protected string $dsn,
        protected int $timeoutMs,
        protected int $poolSize,
        protected int $connMaxLifetimeMs,
        protected array $data,
    ) {
    }

    public function getMethod(): MethodEnum
    {
        return MethodEnum::Redis;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return [
            'cm'  => $this->command->value,
            'dsn' => $this->dsn,
            'to'  => $this->timeoutMs,
            'ps'  => $this->poolSize,
            'cl'  => $this->connMaxLifetimeMs,
            'dt'  => $this->data,
        ];
    }
}
