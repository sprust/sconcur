<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mysql;

use SConcur\Features\Sql\Connection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMysqlResolver;

class MysqlTypesTest extends BaseTestCase
{
    protected Connection $connection;

    protected string $table = 'sconcur_types_test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = TestMysqlResolver::getConnection();

        $this->connection->exec(sql: "DROP TABLE IF EXISTS {$this->table}");
        $this->connection->exec(
            sql: "CREATE TABLE {$this->table} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                int_col INT NULL,
                bigint_col BIGINT NULL,
                decimal_col DECIMAL(20, 4) NULL,
                float_col FLOAT NULL,
                double_col DOUBLE NULL,
                varchar_col VARCHAR(255) NULL,
                text_col TEXT NULL,
                bool_col TINYINT(1) NULL,
                date_col DATE NULL,
                datetime_col DATETIME NULL,
                blob_col BLOB NULL,
                null_col INT NULL
            ) ENGINE=InnoDB",
        );
    }

    protected function tearDown(): void
    {
        $this->connection->exec(sql: "DROP TABLE IF EXISTS {$this->table}");

        parent::tearDown();
    }

    public function testBindingAndColumnTypesRoundTrip(): void
    {
        $binaryValue = "\x00\x01\x02\xffbinary";

        $insert = $this->connection->exec(
            sql: "INSERT INTO {$this->table} (
                int_col, bigint_col, decimal_col, float_col, double_col,
                varchar_col, text_col, bool_col, date_col, datetime_col,
                blob_col, null_col
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            bindings: [
                -42,
                PHP_INT_MAX,
                '123.4500',
                3.5,
                2.718281828459045,
                'hello',
                'a longer piece of text',
                true,
                '2026-06-16',
                '2026-06-16 10:30:00',
                $binaryValue,
                null,
            ],
        );

        self::assertSame(1, $insert->affectedRows);

        // Reading back with a binding uses the binary protocol: numeric columns
        // decode to int/float, strings/blobs to string, dates (parseTime=true) to an
        // RFC3339 string, NULL to null.
        $rows = $this->connection->fetchAll(
            sql: "SELECT * FROM {$this->table} WHERE id = ?",
            bindings: [$insert->lastInsertId],
        );

        self::assertCount(1, $rows);

        $row = $rows[0];

        self::assertSame(-42, $row['int_col']);
        self::assertSame(PHP_INT_MAX, $row['bigint_col']);
        self::assertSame('123.4500', $row['decimal_col']);
        self::assertEqualsWithDelta(3.5, $row['float_col'], 0.0001);
        self::assertEqualsWithDelta(2.718281828459045, $row['double_col'], 0.0000000001);
        self::assertSame('hello', $row['varchar_col']);
        self::assertSame('a longer piece of text', $row['text_col']);
        self::assertSame(1, $row['bool_col']);
        self::assertStringContainsString('2026-06-16', (string) $row['date_col']);
        self::assertStringContainsString('2026-06-16T10:30:00', (string) $row['datetime_col']);
        self::assertSame($binaryValue, $row['blob_col']);
        self::assertNull($row['null_col']);
    }

    public function testFalseAndZeroBindings(): void
    {
        $insert = $this->connection->exec(
            sql: "INSERT INTO {$this->table} (int_col, double_col, varchar_col, bool_col) VALUES (?, ?, ?, ?)",
            bindings: [0, 0.0, '', false],
        );

        $rows = $this->connection->fetchAll(
            sql: "SELECT int_col, double_col, varchar_col, bool_col FROM {$this->table} WHERE id = ?",
            bindings: [$insert->lastInsertId],
        );

        $row = $rows[0];

        self::assertSame(0, $row['int_col']);
        self::assertEqualsWithDelta(0.0, $row['double_col'], 0.0001);
        self::assertSame('', $row['varchar_col']);
        self::assertSame(0, $row['bool_col']);
    }

    public function testBindingsUsedInWhereClause(): void
    {
        $this->connection->exec(
            sql: "INSERT INTO {$this->table} (varchar_col, int_col, bool_col) VALUES (?, ?, ?)",
            bindings: ['match', 7, true],
        );

        $this->connection->exec(
            sql: "INSERT INTO {$this->table} (varchar_col, int_col, bool_col) VALUES (?, ?, ?)",
            bindings: ['other', 7, false],
        );

        $rows = $this->connection->fetchAll(
            sql: "SELECT varchar_col FROM {$this->table} WHERE int_col = ? AND varchar_col = ? AND bool_col = ?",
            bindings: [7, 'match', true],
        );

        self::assertCount(1, $rows);
        self::assertSame('match', $rows[0]['varchar_col']);
    }

    public function testValuesAreTypedWithoutBindings(): void
    {
        $this->connection->exec(
            sql: "INSERT INTO {$this->table} (int_col, double_col, varchar_col) VALUES (?, ?, ?)",
            bindings: [42, 1.5, 'hi'],
        );

        // A query with no bindings (text protocol) returns the same typed values as
        // the binary protocol: integers as int, floats as float, strings as string.
        $rows = $this->connection->fetchAll(
            sql: "SELECT int_col, double_col, varchar_col FROM {$this->table}",
        );

        self::assertCount(1, $rows);
        self::assertSame(42, $rows[0]['int_col']);
        self::assertEqualsWithDelta(1.5, $rows[0]['double_col'], 0.0001);
        self::assertSame('hi', $rows[0]['varchar_col']);
    }
}
