<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Serialization;

use SConcur\Features\Mongodb\Exceptions\UnexpectedMongodbResponseFormatException;
use function msgpack_pack;

/**
 * Documents go to the extension as MessagePack and come back as the PHP arrays
 * the core builds, so nothing here depends on ext-mongodb.
 *
 * On the way out, BSON types MessagePack has no equivalent for (an id, a date, a
 * decimal, ...) ride in the object envelope ext-msgpack uses for PHP objects. On
 * the way back the core creates the SConcur\Bson\* instances itself, inside the
 * wait call, so a document arrives ready and PHP never walks it.
 */
class DocumentSerializer
{
    /**
     * Encode a document for the wire. MessagePack preserves the PHP shape — a list
     * packs as a list, a map as a map — and the extension side turns either into a BSON
     * document, so the caller does not have to say which one it is.
     *
     * @param array<int|string, mixed> $document
     */
    public static function serialize(array $document): string
    {
        return msgpack_pack($document);
    }

    /**
     * A document as the core handed it back: an array, or nothing.
     *
     * @return array<int|string, mixed>
     */
    public static function unserialize(mixed $document): array
    {
        if ($document === null || $document === '') {
            return [];
        }

        if (!is_array($document)) {
            throw new UnexpectedMongodbResponseFormatException(
                message: 'The MongoDB document the core handed back is not an array.',
            );
        }

        return $document;
    }

    /**
     * A cursor batch as the core handed it back: a plain list of documents.
     *
     * @return array<int, mixed>
     */
    public static function unserializeBatch(mixed $payload): array
    {
        if ($payload === null || $payload === '') {
            return [];
        }

        if (!is_array($payload)) {
            throw new UnexpectedMongodbResponseFormatException(
                message: 'The MongoDB batch the core handed back is not a list.',
            );
        }

        return array_values($payload);
    }
}
