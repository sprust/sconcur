<?php

declare(strict_types=1);

namespace SConcur\Features\File\Payloads;

use SConcur\Features\File\FileCommandEnum;
use SConcur\Features\File\Payloads\Base\BaseFilePayload;

/**
 * The Write command: write the bytes at the handle's current position (always at
 * the end in append mode). Returns the bytes written and the new position.
 *
 * Go: payloads.WriteParams (ext/internal/features/file/payloads/payloads.go).
 */
readonly class WritePayload extends BaseFilePayload
{
    public function __construct(
        protected string $handleId,
        protected string $bytes,
        int $timeoutMs,
    ) {
        parent::__construct(timeoutMs: $timeoutMs);
    }

    protected function getCommand(): FileCommandEnum
    {
        return FileCommandEnum::Write;
    }

    protected function getCommandData(): array
    {
        return [
            'h' => $this->handleId,
            'b' => $this->bytes,
        ];
    }
}
