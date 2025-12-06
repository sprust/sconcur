<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

class TestMongodbUriResolver
{
    public static function get(): string
    {
        $host = $_ENV['MONGO_HOST'];
        $user = $_ENV['MONGO_ADMIN_USERNAME'];
        $pass = $_ENV['MONGO_ADMIN_PASSWORD'];
        $port = $_ENV['MONGO_PORT'];

        return "mongodb://$user:$pass@$host:$port";
    }
}
