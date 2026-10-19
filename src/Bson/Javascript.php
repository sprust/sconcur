<?php

declare(strict_types=1);

namespace SConcur\Bson;

use JsonSerializable;
use Stringable;

/** BSON JavaScript code, mirroring MongoDB\BSON\Javascript. */
readonly class Javascript implements Type, Stringable, JsonSerializable
{
    // Public on purpose: the extension reads an object's public properties when a
    // document crosses to it and writes them in place when one comes back, so a
    // protected property would not cross. The class is readonly, so the value object
    // stays immutable regardless.
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
