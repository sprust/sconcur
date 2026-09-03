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

    /**
     * The types the port decoded wrongly, every one of them silently or fatally:
     * TIME reached PHP as the binary protocol's own bytes, JSON/BIT/YEAR and
     * TINYINT(1) UNSIGNED failed the whole query on a type-compatibility check,
     * and a BIGINT UNSIGNED past PHP_INT_MAX wrapped to a negative.
     *
     * Kept in one case because they share a table and the point is the set: the
     * decoder had a hole for every column shape it did not name explicitly.
     */
    public function testTypesThePortUsedToDecodeWrongly(): void
    {
        $table = $this->table . '_wire';

        $this->connection->exec(sql: "DROP TABLE IF EXISTS $table");
        $this->connection->exec(
            sql: "CREATE TABLE $table (
                time_col TIME NULL,
                negative_time_col TIME NULL,
                year_col YEAR NULL,
                json_col JSON NULL,
                bit_col BIT(4) NULL,
                unsigned_tiny_col TINYINT(1) UNSIGNED NULL,
                big_unsigned_col BIGINT UNSIGNED NULL,
                fitting_unsigned_col BIGINT UNSIGNED NULL
            ) ENGINE=InnoDB",
        );

        try {
            $this->connection->exec(
                sql: "INSERT INTO $table VALUES ('14:30:00', '-05:00:01', 2026, ?, b'1010', 1, ?, ?)",
                bindings: [
                    '{"a": 1}',
                    '18446744073709551615',
                    '42',
                ],
            );

            $rows = $this->connection->fetchAll(sql: "SELECT * FROM $table");

            self::assertCount(1, $rows);

            $row = $rows[0];

            // A clock reading, not the nine bytes the binary protocol carries.
            self::assertSame('14:30:00', $row['time_col']);

            // MySQL's TIME is a signed span over +-838 hours, so the sign has to
            // survive — and sqlx's MySqlTime::is_negative() answers is_positive(),
            // which is why this column is here.
            self::assertSame('-05:00:01', $row['negative_time_col']);

            self::assertSame(2026, $row['year_col']);
            self::assertSame(1, $row['unsigned_tiny_col']);

            // JSON and BIT travel as raw bytes, like every other binary column.
            self::assertSame('{"a": 1}', $row['json_col']);
            self::assertSame("\x0a", $row['bit_col']);

            // Past PHP_INT_MAX the value becomes its decimal string rather than a
            // wrapped negative — docs/mysql.md already tells callers to read such
            // a column as a string, and -1 is the one answer nobody can act on.
            self::assertSame('18446744073709551615', $row['big_unsigned_col']);

            // Inside the range it stays an integer.
            self::assertSame(42, $row['fitting_unsigned_col']);
        } finally {
            $this->connection->exec(sql: "DROP TABLE IF EXISTS $table");
        }
    }

    /**
     * MySQL refuses SET TRANSACTION once a transaction is open (error 1568), so
     * applying the characteristics after BEGIN broke both options outright.
     * readOnly rides in START TRANSACTION; the isolation level cannot, and is
     * refused by name rather than silently ignored.
     */
    public function testReadOnlyTransactionAndRefusedIsolationLevel(): void
    {
        $transaction = $this->connection->begin(readOnly: true);

        try {
            self::assertSame([], $transaction->fetchAll(sql: "SELECT * FROM {$this->table}"));
        } finally {
            $transaction->rollback();
        }

        $this->expectExceptionMessageMatches('/isolationLevel is not supported on MySQL/');

        $this->connection->begin(isolationLevel: 2);
    }
}
