<?php

declare(strict_types=1);

namespace SConcur\Bson;

use JsonSerializable;
use Stringable;

/** BSON regular expression, mirroring MongoDB\BSON\Regex. */
readonly class Regex implements Type, Stringable, JsonSerializable
{
    // Public on purpose: MessagePack mangles the name of a protected property the
    // way serialize() does ("\0*\0data"), and the Go side writes plain names. The
    // class is readonly, so the value object stays immutable regardless.
    public string $pattern;
    public string $flags;

    public function __construct(string $pattern, string $flags = '')
    {
        $this->pattern = $pattern;

        // The driver sorts the flags, so two equal expressions compare equal
        // whatever order they were written in.
        $sorted = str_split($flags);
        sort($sorted);

        $this->flags = implode('', $sorted);
    }

    public function getPattern(): string
    {
        return $this->pattern;
    }

    public function getFlags(): string
    {
        return $this->flags;
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return [
            '$regex'   => $this->pattern,
            '$options' => $this->flags,
        ];
    }

    public function __toString(): string
    {
        return sprintf('/%s/%s', $this->pattern, $this->flags);
    }
}
