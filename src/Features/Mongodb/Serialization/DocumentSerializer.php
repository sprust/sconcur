<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Serialization;

use DateTime;
use JsonException;
use RuntimeException;
use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Features\Mongodb\Types\UTCDateTime;

readonly class DocumentSerializer
{
    /**
     * @param array<int|string, mixed> $document
     */
    public static function serialize(array $document): string
    {
        return json_encode($document);
    }

    /**
     * @return array<int|string|float|bool|null, mixed>
     */
    public static function unserialize(string $document): array
    {
        try {
            $data = json_decode($document, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException( // TODO
                message: 'Failed to decode JSON: ' . $exception->getMessage(),
            );
        }

        foreach ($data as $key => $value) {
            $data[$key] = static::unserializeRecursive(value: $value);
        }

        return $data;
    }

    protected static function unserializeRecursive(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_key_exists('$oid', $value)) {
                return new ObjectId($value['$oid']);
            }

            if (array_key_exists('$date', $value)) {
                $date = $value['$date']['$numberLong'] ?? null;

                if (ctype_digit($date) !== false) {
                    return new UTCDateTime(
                        DateTime::createFromFormat('U.u', sprintf('%.6F', (int) $date / 1000))
                    );
                }
            }

            $result = [];

            foreach ($value as $key => $subValue) {
                $result[$key] = static::unserializeRecursive($subValue);
            }

            return $result;
        }

        return $value;
    }
}
