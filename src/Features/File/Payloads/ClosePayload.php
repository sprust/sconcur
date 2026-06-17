<?php

declare(strict_types=1);

namespace SConcur\Features\File\Payloads;

use SConcur\Features\File\FileCommandEnum;
use SConcur\Features\File\Payloads\Base\BaseFilePayload;

/**
 * The Close command: close the descriptor and forget the session. PHP then
 * releases the held Open task via next() (mirrors Sql Transaction::finish).
 *
 * Go: payloads.HandleRefParams (ext/internal/features/file/payloads/payloads.go).
 */
readonly class ClosePayload extends BaseFilePayload
{
    public function __construct(
        protected string $handleId,
        int $timeoutMs,
    ) {
        parent::__construct(timeoutMs: $timeoutMs);
    }

    protected function getCommand(): FileCommandEnum
    {
        return FileCommandEnum::Close;
    }

    protected function getCommandData(): array
    {
        return [
            'h' => $this->handleId,
        ];
    }
}
