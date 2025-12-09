<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Types;

use JsonSerializable;

readonly class ObjectId implements JsonSerializable
{
    protected const string TYPE_PREFIX = '$oid-ofls:';

    public function __construct(
        public string $id
    ) {
    }

    public function jsonSerialize(): string
    {
        return static::TYPE_PREFIX . $this->id;
    }
}
