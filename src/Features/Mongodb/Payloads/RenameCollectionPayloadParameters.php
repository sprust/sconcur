<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Transport\PayloadParametersInterface;

readonly class RenameCollectionPayloadParameters implements PayloadParametersInterface
{
    public function __construct(
        private string $target,
        private bool $dropTarget,
    ) {
    }

    public function getData(): array
    {
        return [
            't'  => $this->target,
            'dt' => $this->dropTarget,
        ];
    }
}
