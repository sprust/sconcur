<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Types;

readonly class ObjectId
{
    protected const TYPE_PREFIX = '$oid-ofls:';

    public function __construct(
        public string $id
    ) {
    }

    public function format(): string
    {
        return static::TYPE_PREFIX . $this->id;
    }
}
