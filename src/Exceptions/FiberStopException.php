<?php

declare(strict_types=1);

namespace SConcur\Exceptions;

use RuntimeException;

class FiberStopException extends RuntimeException
{
    protected static ?FiberStopException $instance = null;

    public static function create(): FiberStopException
    {
        return static::$instance ??= new FiberStopException();
    }
}
