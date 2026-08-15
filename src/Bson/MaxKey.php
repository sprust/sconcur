<?php

declare(strict_types=1);

namespace SConcur\Bson;

use JsonSerializable;

/** BSON MaxKey, mirroring MongoDB\BSON\MaxKey: compares higher than every value. */
readonly class MaxKey implements Type, JsonSerializable
{
    /** @return array<string, int> */
    public function jsonSerialize(): array
    {
        return ['$maxKey' => 1];
    }
}
