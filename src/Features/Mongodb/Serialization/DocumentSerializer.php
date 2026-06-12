<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Serialization;

use DateTime;
use RuntimeException;
use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Features\Mongodb\Types\UTCDateTime;
use SConcur\Transport\MessagePackTransport;

readonly class DocumentSerializer
{
    // Must match orderedMapMarker in ext/internal/features/mongodb/serializer/serializers.go
    protected const string ORDERED_MAP_MARKER = "\0m";

    /**
     * @param array<int|string, mixed> $document
     */
    public static function serialize(array $document, bool $isObject = true): string
    {
        if ($document === []) {
            return MessagePackTransport::pack(
                $isObject
                    ? [static::ORDERED_MAP_MARKER => []]
                    : []
            );
        }

        return MessagePackTransport::pack(
            static::serializeRecursive($document)
        );
    }

    /**
     * @return array<int|string|float|bool|null, mixed>
     */
    public static function unserialize(string $document): array
    {
        $data   = MessagePackTransport::unpack($document);
        $result = static::unserializeRecursive($data);

        if (!is_array($result)) {
            throw new RuntimeException(
                message: 'Failed to decode MessagePack: expected array payload',
            );
        }

        return $result;
    }

    protected static function serializeRecursive(mixed $value): mixed
    {
        if ($value instanceof ObjectId || $value instanceof UTCDateTime) {
            return $value->jsonSerialize();
        }

        if (is_array($value)) {
            if (!array_is_list($value)) {
                $items = [];

                foreach ($value as $key => $subValue) {
                    $items[] = [
                        $key,
                        static::serializeRecursive($subValue),
                    ];
                }

                return [
                    static::ORDERED_MAP_MARKER => $items,
                ];
            }

            $result = [];

            foreach ($value as $subValue) {
                $result[] = static::serializeRecursive($subValue);
            }

            return $result;
        }

        return $value;
    }

    protected static function unserializeRecursive(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_key_exists(static::ORDERED_MAP_MARKER, $value)) {
                $result = [];
                $items  = $value[static::ORDERED_MAP_MARKER];

                if (!is_array($items)) {
                    return $result;
                }

                foreach ($items as $item) {
                    if (!is_array($item) || count($item) !== 2) {
                        continue;
                    }

                    $result[$item[0]] = static::unserializeRecursive($item[1]);
                }

                return $result;
            }

            $result = [];

            foreach ($value as $key => $subValue) {
                $result[$key] = static::unserializeRecursive($subValue);
            }

            return $result;
        }

        if (is_string($value)) {
            if (str_starts_with($value, '$oid-ofls:')) {
                return new ObjectId(substr($value, strlen('$oid-ofls:')));
            }

            if (str_starts_with($value, '$udt-lgof:')) {
                $dateTime = DateTime::createFromFormat(DATE_RFC3339_EXTENDED, substr($value, strlen('$udt-lgof:')));

                if ($dateTime === false) {
                    throw new RuntimeException(
                        message: 'Invalid UTCDateTime value in document: ' . mb_substr($value, 0, 50)
                    );
                }

                return new UTCDateTime($dateTime);
            }
        }

        return $value;
    }
}
