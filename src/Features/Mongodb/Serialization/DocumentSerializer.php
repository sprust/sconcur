<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Serialization;

use DateTimeInterface;
use MongoDB\BSON\Document;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTimeInterface;

readonly class DocumentSerializer
{
    private const TYPE_KEY  = '|t_';
    private const VALUE_KEY = '|v_';

    private const DATE_FORMAT = DATE_RFC3339;

    private const DATETIME_TYPE = 'datetime';
    private const ID_TYPE       = 'id';

    /**
     * TODO: try to use MongoDB\BSON\Document
     *
     * @param array<int|string, mixed> $document
     */
    public static function  serialize(array $document): string
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

    public static function unserialize(string $document): Document
    {
        return Document::fromJSON($document);
    }

    /**
     * @param array<int|string, mixed> $result
     */
    protected static function serializeRecursive(array &$result, int|string $key, mixed $value): void
    {
        if (is_object($value)) {
            if ($value instanceof ObjectId) {
                $result[$key] = [
                    self::TYPE_KEY  => self::ID_TYPE,
                    self::VALUE_KEY => (string) $value,
                ];

                return;
            }
            if ($value instanceof DateTimeInterface) {
                $result[$key] = [
                    self::TYPE_KEY  => self::DATETIME_TYPE,
                    self::VALUE_KEY => $value->format(self::DATE_FORMAT),
                ];

                return;
            }

            if ($value instanceof UTCDateTimeInterface) {
                $result[$key] = [
                    self::TYPE_KEY  => self::DATETIME_TYPE,
                    self::VALUE_KEY => $value->toDateTime()->format(self::DATE_FORMAT),
                ];

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
}
