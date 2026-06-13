<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Transport\PayloadParametersInterface;

readonly class RunCommandPayloadParameters implements PayloadParametersInterface
{
    /**
     * @param array<int|string, mixed> $command
     */
    public function __construct(
        private array $command,
    ) {
    }

    public function getData(): array
    {
        return $this->command;
    }
}
