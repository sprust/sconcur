<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\Mysql;

use PDO;
use SConcur\Features\Mysql\Connection\Client;
use SConcur\Tests\Impl\Mysql\Repositories\DriverMysqlRepository;
use SConcur\Tests\Impl\Mysql\Repositories\SconcurMysqlRepository;

class TestMysqlResolver
{
    protected static string $testTableName = 'test_table';

    public static function getDriverRepository(): DriverMysqlRepository
    {
        $host     = $_ENV['MYSQL_HOST'];
        $user     = $_ENV['MYSQL_USER'];
        $pass     = $_ENV['MYSQL_PASSWORD'];
        $database = $_ENV['MYSQL_DATABASE'];
        $port     = $_ENV['MYSQL_PORT'];

        $dns = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4;";

        return new DriverMysqlRepository(
            pdo: new PDO(
                dsn: $dns,
                username: $user,
                password: $pass,
            ),
            tableName: static::$testTableName,
        );
    }

    public static function getSconcurRepository(): SconcurMysqlRepository
    {
        $host     = $_ENV['MYSQL_HOST'];
        $user     = $_ENV['MYSQL_USER'];
        $pass     = $_ENV['MYSQL_PASSWORD'];
        $database = $_ENV['MYSQL_DATABASE'];
        $port     = $_ENV['MYSQL_PORT'];

        $dns = "$user:$pass@tcp($host:$port)/$database?parseTime=true";

        return new SconcurMysqlRepository(
            client: new Client(
                dsn: $dns,
                timeoutMs: 0
            ),
            tableName: static::$testTableName,
        );
    }
}
