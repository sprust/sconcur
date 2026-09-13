<?php

declare(strict_types=1);

namespace SConcur\Features\Redis\Dto;

/**
 * The failure of one command inside a pipeline.
 *
 * A pipeline answers with one reply per command, and the server ran the rest of them, so a
 * failed command takes its own place in the list instead of failing the whole call. A
 * single command outside a pipeline throws, like every other call in the library.
 *
 * Rust: values::encode_pipeline (ext/src/features/redis/values.rs).
 */
readonly class ErrorReply
{
    public function __construct(
        /** The Redis error code: WRONGTYPE, NOSCRIPT, ERR … */
        public string $code,
        public string $message,
    ) {
    }

    public function __toString(): string
    {
        return trim("$this->code $this->message");
    }
}
