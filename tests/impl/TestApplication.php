<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

use Dotenv\Dotenv;

class TestApplication
{
    public static function init(): void
    {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../../', '.env');
        $dotenv->load();
    }
}
