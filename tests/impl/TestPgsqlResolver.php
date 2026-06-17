<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

use PDO;
use SConcur\Features\Pgsql\Connection;

class TestPgsqlResolver
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
     * Native PDO PostgreSQL connection — the baseline the SConcur paths are compared
     * against in benchmarks.
     */
    public static function getPdo(): PDO
    {
        $host     = $_ENV['POSTGRES_HOST'];
        $port     = $_ENV['POSTGRES_PORT'];
        $database = $_ENV['POSTGRES_DB'];
        $user     = $_ENV['POSTGRES_USER'];
        $password = $_ENV['POSTGRES_PASSWORD'];

        return new PDO(
            "pgsql:host=$host;port=$port;dbname=$database",
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
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                amount INT NOT NULL
            )",
        );

        if ($rows <= 0) {
            return;
        }

        // PDO uses `?` placeholders (it maps them to PG's $1, $2 internally).
        $statement = $pdo->prepare("INSERT INTO $table (name, amount) VALUES (?, ?)");

        for ($index = 1; $index <= $rows; ++$index) {
            $statement->execute(["row-$index", $index]);
        }
    }

    protected static function getDsn(): string
    {
        if (static::$dsn !== null) {
            return static::$dsn;
        }

        $host     = $_ENV['POSTGRES_HOST'];
        $port     = $_ENV['POSTGRES_PORT'];
        $database = $_ENV['POSTGRES_DB'];
        $user     = $_ENV['POSTGRES_USER'];
        $password = $_ENV['POSTGRES_PASSWORD'];

        return static::$dsn = "postgres://$user:$password@$host:$port/$database?sslmode=disable";
    }
}
