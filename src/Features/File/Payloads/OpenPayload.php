<?php

declare(strict_types=1);

namespace SConcur\Features\File\Payloads;

use SConcur\Features\File\FileCommandEnum;
use SConcur\Features\File\Payloads\Base\BaseFilePayload;

/**
 * The Open command: opens the file with the validated mode and permission bits and
 * registers a held handle on the Go side. The task is kept alive (hasNext) so the
 * pinned descriptor survives across the handle's later commands.
 *
 * Go: payloads.OpenParams (ext/internal/features/file/payloads/payloads.go).
 */
readonly class OpenPayload extends BaseFilePayload
{
    public function __construct(
        protected string $path,
        protected string $mode,
        protected int $perm,
        int $timeoutMs,
    ) {
        parent::__construct(timeoutMs: $timeoutMs);
    }

    protected function getCommand(): FileCommandEnum
    {
        return FileCommandEnum::Open;
    }

    protected function getCommandData(): array
    {
        return [
            'p'  => $this->path,
            'md' => $this->mode,
            'pm' => $this->perm,
        ];
    }
}
