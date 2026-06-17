<?php

declare(strict_types=1);

namespace SConcur\Features\File\Payloads;

use SConcur\Features\File\FileCommandEnum;
use SConcur\Features\File\Payloads\Base\BaseFilePayload;

/**
 * The Stat command: return the file size, modification time and mode bits.
 *
 * Go: payloads.HandleRefParams (ext/internal/features/file/payloads/payloads.go).
 */
readonly class StatPayload extends BaseFilePayload
{
    public function __construct(
        protected string $handleId,
        int $timeoutMs,
    ) {
        parent::__construct(timeoutMs: $timeoutMs);
    }

    protected function getCommand(): FileCommandEnum
    {
        return FileCommandEnum::Stat;
    }

    protected function getCommandData(): array
    {
        return [
            'h' => $this->handleId,
        ];
    }
}
