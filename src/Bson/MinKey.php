<?php

declare(strict_types=1);

namespace SConcur\Bson;

use JsonSerializable;

/** BSON MinKey, mirroring MongoDB\BSON\MinKey: compares lower than every value. */
readonly class MinKey implements Type, JsonSerializable
{
    /** @return array<string, int> */
    public function jsonSerialize(): array
    {
        return ['$minKey' => 1];
    }
}
