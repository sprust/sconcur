<?php

declare(strict_types=1);

namespace SConcur\Helpers;

readonly class UuidGenerator
{
    public static function make(): string
    {
        return uniqid(
            prefix: (string) microtime(true),
            more_entropy: true
        );
    }
}
