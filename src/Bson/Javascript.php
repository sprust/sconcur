<?php

declare(strict_types=1);

namespace SConcur\Bson;

use JsonSerializable;
use Stringable;

/** BSON JavaScript code, mirroring MongoDB\BSON\Javascript. */
readonly class Javascript implements Type, Stringable, JsonSerializable
{
    // Public on purpose: MessagePack mangles the name of a protected property the
    // way serialize() does ("\0*\0data"), and the extension side writes plain names. The
    // class is readonly, so the value object stays immutable regardless.
    public string $code;

    /** @var array<string, mixed>|null */
    public ?array $scope;

    /** @param object|array<string, mixed>|null $scope */
    public function __construct(string $code, object|array|null $scope = null)
    {
        $this->code  = $code;
        $this->scope = $scope === null ? null : (array) $scope;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getScope(): ?object
    {
        return $this->scope === null ? null : (object) $this->scope;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $serialized = ['$code' => $this->code];

        if ($this->scope !== null) {
            $serialized['$scope'] = $this->scope;
        }

        return $serialized;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
