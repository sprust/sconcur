<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Serialization;

use SConcur\Features\Mongodb\Exceptions\UnexpectedMongodbResponseFormatException;
use function msgpack_pack;
use function msgpack_unpack;

/**
 * Documents are exchanged with the Go extension as MessagePack, so nothing here
 * depends on ext-mongodb.
 *
 * BSON types MessagePack has no equivalent for (an id, a date, a decimal, ...)
 * ride in the object envelope ext-msgpack uses for PHP objects, and become
 * SConcur\Bson\* instances. The extension materialises them in C while parsing,
 * so decoding a document is one pass and PHP never walks the result — which is
 * what made the earlier hand-rolled converter expensive.
 */
class DocumentSerializer
{
    /**
     * Encode a document for the wire. MessagePack preserves the PHP shape — a list
     * packs as a list, a map as a map — and the Go side turns either into a BSON
     * document, so the caller does not have to say which one it is.
     *
     * @param array<int|string, mixed> $document
     */
    public static function serialize(array $document): string
    {
        return msgpack_pack($document);
    }

    /**
     * Decode a document from the wire.
     *
     * @return array<int|string, mixed>
     */
    public static function unserialize(string $document): array
    {
        if ($document === '') {
            return [];
        }

        $decoded = msgpack_unpack($document);

        if (!is_array($decoded)) {
            throw new UnexpectedMongodbResponseFormatException(
                message: 'Decoded MongoDB document is not an array.',
            );
        }

        return $decoded;
    }

    /**
     * Decode a cursor batch. The batch is a plain list — the BSON path needed a
     * {"d": [...]} wrapper because PHP could only decode a document.
     *
     * @return array<int, mixed>
     */
    public static function unserializeBatch(string $payload): array
    {
        if ($payload === '') {
            return [];
        }

        $decoded = msgpack_unpack($payload);

        if (!is_array($decoded)) {
            throw new UnexpectedMongodbResponseFormatException(
                message: 'Decoded MongoDB batch is not a list.',
            );
        }

        return array_values($decoded);
    }
}
