<?php

declare(strict_types=1);

namespace SConcur\Features\File\Payloads;

use SConcur\Features\File\FileCommandEnum;
use SConcur\Features\File\Payloads\Base\BaseFilePayload;

/**
 * The Seek command: reposition the handle. `whence` mirrors PHP/Go constants:
 * 0 = SEEK_SET, 1 = SEEK_CUR, 2 = SEEK_END. Returns the new absolute position.
 *
 * Go: payloads.SeekParams (ext/internal/features/file/payloads/payloads.go).
 */
readonly class SeekPayload extends BaseFilePayload
{
    public function __construct(
        protected string $handleId,
        protected int $offset,
        protected int $whence,
        int $timeoutMs,
    ) {
        parent::__construct(timeoutMs: $timeoutMs);
    }

    protected function getCommand(): FileCommandEnum
    {
        return FileCommandEnum::Seek;
    }

    protected function getCommandData(): array
    {
        return [
            'h' => $this->handleId,
            'o' => $this->offset,
            'w' => $this->whence,
        ];
    }
}
