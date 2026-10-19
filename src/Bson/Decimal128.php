<?php

declare(strict_types=1);

namespace SConcur\Bson;

use JsonSerializable;
use Stringable;

/** BSON 128-bit decimal, mirroring MongoDB\BSON\Decimal128. */
readonly class Decimal128 implements Type, Stringable, JsonSerializable
{
    // Public on purpose: the extension reads an object's public properties when a
    // document crosses to it and writes them in place when one comes back, so a
    // protected property would not cross. The class is readonly, so the value object
    // stays immutable regardless.
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
