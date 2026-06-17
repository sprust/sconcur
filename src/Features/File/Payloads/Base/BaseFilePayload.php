<?php

declare(strict_types=1);

namespace SConcur\Features\File\Payloads\Base;

use SConcur\Features\File\FileCommandEnum;
use SConcur\Features\MethodEnum;
use SConcur\Transport\PayloadInterface;

/**
 * Builds the command envelope (cm/to/dt) every File payload sends: the
 * sub-operation command, the per-command execution-time limit and the command
 * body. Mirrors Base\BaseSqlPayload.
 *
 * The timeout bounds a single sub-command on the Go side (read/write/sync); the
 * held Open task is not bound by it (it lives for the whole handle).
 *
 * Go: payloads.Envelope (ext/internal/features/file/payloads/payloads.go).
 */
abstract readonly class BaseFilePayload implements PayloadInterface
{
    abstract protected function getCommand(): FileCommandEnum;

    /**
     * @return array<string, mixed>
     */
    abstract protected function getCommandData(): array;

    public function __construct(
        protected int $timeoutMs,
    ) {
    }

    public function getMethod(): MethodEnum
    {
        return MethodEnum::File;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return [
            'cm' => $this->getCommand()->value,
            'to' => $this->timeoutMs,
            'dt' => $this->getCommandData(),
        ];
    }
}
