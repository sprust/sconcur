<?php

declare(strict_types=1);

namespace SConcur\Bson;

use JsonSerializable;
use SConcur\Bson\Exceptions\InvalidBsonValueException;
use Stringable;

/** BSON binary value, mirroring MongoDB\BSON\Binary: raw bytes plus a subtype. */
readonly class Binary implements Type, Stringable, JsonSerializable
{
    public const int TYPE_GENERIC      = 0x00;
    public const int TYPE_FUNCTION     = 0x01;
    public const int TYPE_OLD_BINARY   = 0x02;
    public const int TYPE_OLD_UUID     = 0x03;
    public const int TYPE_UUID         = 0x04;
    public const int TYPE_MD5          = 0x05;
    public const int TYPE_ENCRYPTED    = 0x06;
    public const int TYPE_COLUMN       = 0x07;
    public const int TYPE_SENSITIVE    = 0x08;
    public const int TYPE_USER_DEFINED = 0x80;

    // Public on purpose: MessagePack mangles the name of a protected property the
    // way serialize() does ("\0*\0data"), and the Go side writes plain names. The
    // class is readonly, so the value object stays immutable regardless.
    public string $data;
    public int $subType;

    public function __construct(string $data, int $type = self::TYPE_GENERIC)
    {
        // BSON stores the subtype in one byte, so a wider value is rejected here
        // rather than truncated on the way to the collection.
        if ($type < 0 || $type > 0xFF) {
            throw new InvalidBsonValueException(
                message: sprintf('Expected type to be an unsigned 8-bit integer, %d given', $type),
            );
        }

        $this->data    = $data;
        $this->subType = $type;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function getType(): int
    {
        return $this->subType;
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return [
            '$binary' => base64_encode($this->data),
            '$type'   => sprintf('%02x', $this->subType),
        ];
    }

    public function __toString(): string
    {
        return $this->data;
    }
}
