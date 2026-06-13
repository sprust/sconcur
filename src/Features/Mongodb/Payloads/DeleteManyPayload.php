<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;

/**
 * Go: payloads.DeleteManyPayload (ext/internal/features/mongodb/payloads/payloads.go).
 */
readonly class DeleteManyPayload extends DeleteOnePayload
{
    protected function getCommand(): CommandEnum
    {
        return CommandEnum::DeleteMany;
    }
}
