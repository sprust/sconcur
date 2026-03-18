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
    public static function serialize(array $document, bool $isObject = true): string
    {
        $count = count($document);

        if ($count === 0) {
            return $isObject ? '{}' : '[]';
        }

        try {
            return json_encode($document, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                message: 'Failed to encode JSON: ' . $exception->getMessage(),
            );
        }
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

        if (!is_array($data)) {
            throw new RuntimeException(
                message: 'Failed to decode JSON: expected array payload',
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

            if (array_key_exists('$numberInt', $value)) {
                return (int) $value['$numberInt'];
            }

            if (array_key_exists('$numberLong', $value)) {
                return (int) $value['$numberLong'];
            }

            if (array_key_exists('$numberDouble', $value)) {
                return (float) $value['$numberDouble'];
            }

            if (array_key_exists('$numberDecimal', $value)) {
                return (float) $value['$numberDecimal'];
            }

            if (array_key_exists('$date', $value)) {
                $date = $value['$date']['$numberLong'] ?? $value['$date'] ?? null;

                if (is_string($date) || is_int($date)) {
                    $dateTime = DateTime::createFromFormat(
                        'U.u',
                        sprintf('%.6F', (int) $date / 1000)
                    );

                    if ($dateTime === false) {
                        throw new RuntimeException(
                            message: 'Invalid $date value in document'
                        );
                    }

                    return new UTCDateTime($dateTime);
                }
            }

            if (array_key_exists('$binary', $value)) {
                return base64_decode($value['$binary']['base64'] ?? '');
            }

            if (array_key_exists('$regularExpression', $value)) {
                return '/' . ($value['$regularExpression']['pattern'] ?? '')
                    . '/' . ($value['$regularExpression']['options'] ?? '');
            }

            if (array_key_exists('$timestamp', $value)) {
                return [
                    't' => $value['$timestamp']['t'] ?? 0,
                    'i' => $value['$timestamp']['i'] ?? 0,
                ];
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
