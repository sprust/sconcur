<?php

declare(strict_types=1);

namespace SConcur\Features\File\Payloads;

use SConcur\Features\File\FileCommandEnum;
use SConcur\Features\File\Payloads\Base\BaseFilePayload;

/**
 * The Read command: read up to `length` bytes from the handle's current position.
 *
 * Go: payloads.ReadParams (ext/internal/features/file/payloads/payloads.go).
 */
readonly class ReadPayload extends BaseFilePayload
{
    public function __construct(
        protected string $handleId,
        protected int $length,
        int $timeoutMs,
    ) {
        parent::__construct(timeoutMs: $timeoutMs);
    }

    protected function getCommand(): FileCommandEnum
    {
        return FileCommandEnum::Read;
    }

    protected function getCommandData(): array
    {
        return [
            'h' => $this->handleId,
            'n' => $this->length,
        ];
    }
}
