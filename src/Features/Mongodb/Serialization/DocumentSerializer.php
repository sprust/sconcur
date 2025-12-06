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
        $result = [];

        foreach ($document as $key => $value) {
            static::serializeRecursive(
                result: $result,
                key: $key,
                value: $value,
            );
        }

        return json_encode($result);
    }

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

    /**
     * @param array<int|string, mixed> $result
     */
    protected static function serializeRecursive(array &$result, int|string $key, mixed $value): void
    {
        if (is_object($value)) {
            if ($value instanceof ObjectId) {
                $result[$key] = $value->format();

                return;
            }

            if ($value instanceof UTCDateTime) {
                $result[$key] = $value->format();

                return;
            }

            if (method_exists($value, '__toString')) {
                $result[$key] = (string) $value;

                return;
            }
        } elseif (is_array($value)) {
            $result[$key] = [];

            foreach ($value as $subKey => $subValue) {
                static::serializeRecursive(
                    result: $result[$key],
                    key: $subKey,
                    value: $subValue,
                );
            }

            return;
        }

        $result[$key] = $value;
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
