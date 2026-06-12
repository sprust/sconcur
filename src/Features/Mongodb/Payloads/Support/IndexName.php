<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads\Support;

final class IndexName
{
    /**
     * Builds the default index name from its keys, matching MongoDB's convention
     * (e.g. ['age' => 1, 'name' => -1] → "age_1_name_-1").
     *
     * @param array<string, int|string> $keys
     */
    public static function fromKeys(array $keys): string
    {
        $indexNames = [];

        foreach ($keys as $field => $type) {
            $indexNames[] = "{$field}_$type";
        }

        return implode('_', $indexNames);
    }
}
