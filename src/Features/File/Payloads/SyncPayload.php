<?php

declare(strict_types=1);

namespace SConcur\Features\File\Payloads;

use SConcur\Features\File\FileCommandEnum;
use SConcur\Features\File\Payloads\Base\BaseFilePayload;

/**
 * The Sync command: flush the handle to stable storage (fsync).
 *
 * Go: payloads.HandleRefParams (ext/internal/features/file/payloads/payloads.go).
 */
readonly class SyncPayload extends BaseFilePayload
{
    public function __construct(
        protected string $handleId,
        int $timeoutMs,
    ) {
        parent::__construct(timeoutMs: $timeoutMs);
    }

    protected function getCommand(): FileCommandEnum
    {
        return FileCommandEnum::Sync;
    }

    protected function getCommandData(): array
    {
        return [
            'h' => $this->handleId,
        ];
    }
}
