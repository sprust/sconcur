<?php

declare(strict_types=1);

namespace SConcur\Bson;

use JsonSerializable;
use Stringable;

/** BSON 128-bit decimal, mirroring MongoDB\BSON\Decimal128. */
readonly class Decimal128 implements Type, Stringable, JsonSerializable
{
    // Public on purpose: MessagePack mangles the name of a protected property the
    // way serialize() does ("\0*\0data"), and the extension side writes plain names. The
    // class is readonly, so the value object stays immutable regardless.
    public string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return ['$numberDecimal' => $this->value];
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
