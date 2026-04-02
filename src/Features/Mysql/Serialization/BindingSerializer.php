<?php

declare(strict_types=1);

namespace SConcur\Features\Mysql\Serialization;

readonly class BindingSerializer
{
    protected const string TYPE_PREFIX = '$bin-ldkf:';

    public static function bin(string $value): string
    {
        return static::TYPE_PREFIX . base64_encode($value);
    }
}
