<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;

readonly class DeleteManyPayload extends DeleteOnePayload
{
    protected function getCommand(): CommandEnum
    {
        return CommandEnum::DeleteMany;
    }
}
