<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Serialization;

use MongoDB\BSON\Document;
use MongoDB\BSON\PackedArray;
use SConcur\Features\Mongodb\Exceptions\UnexpectedMongodbResponseFormatException;

/**
 * Documents are exchanged with the Go extension as raw BSON and decoded natively via
 * ext-mongodb (the same C path the native driver uses). Values use the driver's BSON
 * types (MongoDB\BSON\ObjectId, UTCDateTime, ...).
 */
class DocumentSerializer
{
    /**
     * ext-mongodb type map: documents/arrays become PHP arrays; scalars stay native
     * MongoDB\BSON\* objects.
     *
     * @var array<string, string>
     */
    private const array TYPE_MAP = [
        'root'     => 'array',
        'document' => 'array',
        'array'    => 'array',
    ];

    /**
     * Encode a value to raw BSON bytes. An object-like value ($isObject) becomes a BSON
     * document; a list becomes a BSON array.
     *
     * @param array<int|string, mixed> $document
     */
    public static function serialize(array $document, bool $isObject = true): string
    {
        if ($isObject) {
            return (string) Document::fromPHP($document);
        }

        return (string) PackedArray::fromPHP($document);
    }

    /**
     * Decode a raw BSON document into a PHP array.
     *
     * @return array<int|string, mixed>
     */
    public static function unserialize(string $document): array
    {
        return (array) Document::fromBSON($document)->toPHP(self::TYPE_MAP);
    }

    /**
     * Decode a raw BSON batch wrapper {d: [...]} into a list of documents.
     *
     * @return array<int, mixed>
     */
    public static function unserializeBatch(string $payload): array
    {
        $decoded = (array) Document::fromBSON($payload)->toPHP(self::TYPE_MAP);

        $items = $decoded['d'] ?? [];

        if (!is_array($items)) {
            throw new UnexpectedMongodbResponseFormatException(
                message: 'BSON batch payload is not a list.',
            );
        }

        return array_values($items);
    }
}
