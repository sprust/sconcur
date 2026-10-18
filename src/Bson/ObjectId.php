<?php

declare(strict_types=1);

namespace SConcur\Bson;

use JsonSerializable;
use SConcur\Bson\Exceptions\InvalidBsonValueException;
use Stringable;

/**
 * BSON ObjectId, mirroring MongoDB\BSON\ObjectId.
 *
 * The value is held as its 24-character hexadecimal form. The constructor is not
 * called when the extension materialises the object out of a document it hands
 * back, so the validation here guards user code only — what arrives from the
 * extension is validated on its side.
 */
readonly class ObjectId implements Type, Stringable, JsonSerializable
{
    // Public on purpose: the extension reads an object's public properties when a
    // document crosses to it and writes them in place when one comes back, so a
    // protected property would not cross. The class is readonly, so the value object
    // stays immutable regardless.
    public string $oid;

    public function __construct(?string $id = null)
    {
        if ($id === null) {
            $this->oid = ObjectIdGenerator::generate();

            return;
        }

        if (preg_match('/^[0-9a-fA-F]{24}$/', $id) !== 1) {
            throw new InvalidBsonValueException(
                message: sprintf('Error parsing ObjectId string: %s', $id),
            );
        }

        $this->oid = strtolower($id);
    }

    /** Seconds since the Unix epoch, taken from the id's leading four bytes. */
    public function getTimestamp(): int
    {
        return (int) hexdec(substr($this->oid, 0, 8));
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return ['$oid' => $this->oid];
    }

    public function __toString(): string
    {
        return $this->oid;
    }
}
