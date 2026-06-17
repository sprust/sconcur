<?php

declare(strict_types=1);

namespace SConcur\Features\File\Payloads;

use SConcur\Features\File\FileCommandEnum;
use SConcur\Features\File\Payloads\Base\BaseFilePayload;

/**
 * The Truncate command: resize the file to `size` bytes (ftruncate).
 *
 * Go: payloads.TruncateParams (ext/internal/features/file/payloads/payloads.go).
 */
readonly class TruncatePayload extends BaseFilePayload
{
    public function __construct(
        protected string $handleId,
        protected int $size,
        int $timeoutMs,
    ) {
        parent::__construct(timeoutMs: $timeoutMs);
    }

    protected function getCommand(): FileCommandEnum
    {
        return FileCommandEnum::Truncate;
    }

    protected function getCommandData(): array
    {
        return [
            'h' => $this->handleId,
            's' => $this->size,
        ];
    }
}
