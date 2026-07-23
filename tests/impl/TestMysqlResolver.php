<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

use PDO;
use SConcur\Features\Mysql\Connection;

class TestMysqlResolver
{
    protected static ?string $dsn = null;

    public static string $benchmarkTable = 'sconcur_benchmark';

    public static function getConnection(?int $timeoutMs = null, ?int $maxOpenConns = null): Connection
    {
        return new Connection(
            dsn: static::getDsn(),
            timeoutMs: $timeoutMs,
            maxOpenConns: $maxOpenConns,
        );
    }

    /**
     * Native PDO MySQL connection — the baseline the SConcur paths are compared
     * against in benchmarks.
     */
    public static function getPdo(): PDO
    {
        $host     = $_ENV['MYSQL_HOST'];
        $port     = $_ENV['MYSQL_PORT'];
        $database = $_ENV['MYSQL_DATABASE'];
        $user     = $_ENV['MYSQL_USER'];
        $password = $_ENV['MYSQL_PASSWORD'];

        return new PDO(
            "mysql:host=$host;port=$port;dbname=$database",
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ],
        );
    }

    /**
     * Drops and recreates the benchmark table, seeding it with $rows rows so
     * select/update/delete/count benchmarks have data to work on.
     */
    public static function prepareBenchmarkTable(int $rows = 0): void
    {
        $pdo   = static::getPdo();
        $table = static::$benchmarkTable;

        $pdo->exec("DROP TABLE IF EXISTS $table");
        $pdo->exec(
            "CREATE TABLE $table (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                amount INT NOT NULL
            ) ENGINE=InnoDB",
        );

        if ($rows <= 0) {
            return;
        }

        static::seedBenchmarkTable(rows: $rows);
    }

    /**
     * Seeds id = 1..$rows with batched multi-row inserts inside one transaction:
     * a large dataset (100k rows) seeded row-by-row would spend minutes on
     * per-statement commits, while a batch costs a couple of seconds.
     */
    protected static function seedBenchmarkTable(int $rows): void
    {
        $pdo       = static::getPdo();
        $table     = static::$benchmarkTable;
        $chunkSize = 5000;

        $pdo->beginTransaction();

        for ($chunkStart = 1; $chunkStart <= $rows; $chunkStart += $chunkSize) {
            $chunkEnd = min($chunkStart + $chunkSize - 1, $rows);
            $values   = [];

            for ($rowId = $chunkStart; $rowId <= $chunkEnd; ++$rowId) {
                $values[] = "($rowId, 'row-$rowId', $rowId)";
            }

            $pdo->exec("INSERT INTO $table (id, name, amount) VALUES " . implode(',', $values));
        }

        $pdo->commit();
    }

    /**
     * Creates a freshly named benchmark table (used by the db-lifecycle bench,
     * which isolates every mode/run on its own uniquely named table). The id is
     * supplied explicitly by the caller (no AUTO_INCREMENT), so read/delete by id
     * are deterministic across native/sync/async.
     */
    public static function createBenchmarkTable(string $tableName): void
    {
        $pdo = static::getPdo();

        $pdo->exec("DROP TABLE IF EXISTS $tableName");
        $pdo->exec(
            "CREATE TABLE $tableName (
                id INT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                amount INT NOT NULL
            ) ENGINE=InnoDB",
        );
    }

    public static function dropBenchmarkTable(string $tableName): void
    {
        static::getPdo()->exec("DROP TABLE IF EXISTS $tableName");
    }

    protected static function getDsn(): string
    {
        if (static::$dsn !== null) {
            return static::$dsn;
        }

        $host     = $_ENV['MYSQL_HOST'];
        $port     = $_ENV['MYSQL_PORT'];
        $database = $_ENV['MYSQL_DATABASE'];
        $user     = $_ENV['MYSQL_USER'];
        $password = $_ENV['MYSQL_PASSWORD'];

        return static::$dsn = "$user:$password@tcp($host:$port)/$database?parseTime=true";
    }
}
