<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Payload parameters for operations that carry no data (the operation is fully
 * described by the connection envelope, e.g. listDatabases, drop).
 */
readonly class EmptyPayloadParameters implements PayloadParametersInterface
{
    public function getData(): array
    {
        return [];
    }
}
