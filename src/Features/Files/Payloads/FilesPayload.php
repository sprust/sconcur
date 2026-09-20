<?php

declare(strict_types=1);

namespace SConcur\Features\Files\Payloads;

use SConcur\Features\Files\FilesCommandEnum;
use SConcur\Features\MethodEnum;
use SConcur\Transport\PayloadInterface;

/**
 * The envelope every file operation travels in: the sub-operation (`cm`), the execution
 * deadline (`to`) and the operation's body (`dt`).
 *
 * One class for all of them, like Redis\Payloads\RedisPayload: a file operation's
 * parameters are paths, sizes and flags, and a class per command would carry nothing a
 * named argument at the call site does not already carry. The Rust struct each
 * sub-operation's `dt` is decoded into is named on its FilesCommandEnum case.
 *
 * Rust: payloads::Envelope (ext/src/features/files/payloads.rs).
 */
readonly class FilesPayload implements PayloadInterface
{
    /**
     * @param array<string, mixed> $data the sub-operation's body, by its wire keys
     */
    public function __construct(
        protected FilesCommandEnum $command,
        protected int $timeoutMs,
        protected array $data,
    ) {
    }

    public function getMethod(): MethodEnum
    {
        return MethodEnum::Files;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return [
            'cm' => $this->command->value,
            'to' => $this->timeoutMs,
            'dt' => $this->data,
        ];
    }
}
