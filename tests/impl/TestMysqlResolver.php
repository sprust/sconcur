<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

use SConcur\Features\Mysql\Connection\Client;

class TestMysqlResolver
{
    protected static ?string $dns = null;

    protected static string $testTableName = 'test_table';

    public static function getTestTableName(): string
    {
        return self::$testTableName;
    }

    public static function initSconcurTable(): void
    {
        $tableName = static::$testTableName;

        static::getSconcurTestClient()->exec(
            sql: "CREATE TABLE IF NOT EXISTS $tableName (
                id INT AUTO_INCREMENT PRIMARY KEY,
                varchar_col VARCHAR(255),
                char_col CHAR(50),
                text_col TEXT,
                tinytext_col TINYTEXT,
                mediumtext_col MEDIUMTEXT,
                longtext_col LONGTEXT,
                tinyint_col TINYINT,
                smallint_col SMALLINT,
                mediumint_col MEDIUMINT,
                int_col INT,
                bigint_col BIGINT,
                decimal_col DECIMAL(10,2),
                numeric_col NUMERIC(10,2),
                float_col FLOAT,
                double_col DOUBLE,
                bit_col BIT(8),
                date_col DATE,
                datetime_col DATETIME,
                timestamp_col TIMESTAMP,
                time_col TIME,
                year_col YEAR,
                enum_col ENUM('value1', 'value2', 'value3'),
                set_col SET('option1', 'option2', 'option3'),
                json_col JSON,
                bool_col BOOLEAN
            )",
        );
    }

    public static function dropSconcurTable(): void
    {
        $tableName = static::$testTableName;

        static::getSconcurTestClient()->exec(
            sql: "DROP TABLE IF EXISTS $tableName",
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
