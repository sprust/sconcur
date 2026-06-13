<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;

/**
 * Go: payloads.UpdateManyPayload (ext/internal/features/mongodb/payloads/payloads.go).
 */
readonly class UpdateManyPayload extends UpdateOnePayload
{
    protected function getCommand(): CommandEnum
    {
        return CommandEnum::UpdateMany;
    }
}
