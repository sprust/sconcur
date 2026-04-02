<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\Mysql;

use SConcur\Features\Mysql\Connection\Client;
use SConcur\Tests\Impl\Mysql\Repositories\TestMysqlRepository;

class TestMysqlResolver
{
    protected static ?string $dns = null;

    protected static string $testTableName = 'test_table';

    public static function getTestTableName(): string
    {
        return self::$testTableName;
    }

    public static function getTestRepository(): TestMysqlRepository
    {
        return new TestMysqlRepository(
            client: static::getSconcurTestClient(),
            tableName: static::$testTableName,
        );
    }

    public static function getSconcurTestClient(int $timeoutMs = 0): Client
    {
        return new Client(dsn: static::getDns(), timeoutMs: $timeoutMs);
    }

    protected static function getDns(): string
    {
        if (static::$dns !== null) {
            return static::$dns;
        }

        $host = $_ENV['MYSQL_HOST'];
        $user = $_ENV['MYSQL_USER'];
        $pass = $_ENV['MYSQL_PASSWORD'];
        $database = $_ENV['MYSQL_DATABASE'];
        $port = $_ENV['MYSQL_PORT'];

        return static::$dns = "$user:$pass@tcp($host:$port)/$database?parseTime=true";
    }
}
