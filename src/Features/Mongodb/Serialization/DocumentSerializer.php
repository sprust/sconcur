<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Serialization;

use SConcur\Features\Mongodb\Exceptions\UnexpectedMongodbResponseFormatException;

/**
 * Documents cross to the extension as the PHP arrays they are and come back as
 * the arrays the core builds, so nothing here depends on ext-mongodb and nothing
 * is serialized: the core reads a document off the array inside the push call and
 * creates the SConcur\Bson\* instances itself inside the wait call. What is left
 * here is saying what a result is when it is not a document.
 */
class DocumentSerializer
{
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
