<?php

declare(strict_types=1);

namespace SConcur\Bson;

use JsonSerializable;
use Stringable;

/**
 * BSON 64-bit integer, mirroring MongoDB\BSON\Int64.
 *
 * A PHP integer is already 64-bit, so this exists for the same reason it does in
 * the driver: to keep the BSON type of a value that would otherwise be written
 * as an int32, and to carry one back unchanged.
 */
readonly class Int64 implements Type, Stringable, JsonSerializable
{
    // Public on purpose: MessagePack mangles the name of a protected property the
    // way serialize() does ("\0*\0data"), and the Go side writes plain names. The
    // class is readonly, so the value object stays immutable regardless.
    public int $value;

    public function __construct(string|int $value)
    {
        $this->value = (int) $value;
    }

    public function toInt(): int
    {
        return $this->value;
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return ['$numberLong' => (string) $this->value];
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
