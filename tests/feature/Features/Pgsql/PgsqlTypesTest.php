<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Pgsql;

use SConcur\Features\Sql\Connection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestPgsqlResolver;

class PgsqlTypesTest extends BaseTestCase
{
    protected Connection $connection;

    protected string $table = 'sconcur_types_test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = TestPgsqlResolver::getConnection();

        $this->connection->exec(sql: "DROP TABLE IF EXISTS {$this->table}");
        $this->connection->exec(
            sql: "CREATE TABLE {$this->table} (
                id SERIAL PRIMARY KEY,
                int_col INTEGER,
                bigint_col BIGINT,
                numeric_col NUMERIC(20, 4),
                real_col REAL,
                double_col DOUBLE PRECISION,
                varchar_col VARCHAR(255),
                text_col TEXT,
                bool_col BOOLEAN,
                date_col DATE,
                ts_col TIMESTAMP,
                bytea_col BYTEA,
                null_col INTEGER
            )",
        );
    }

    protected function tearDown(): void
    {
        $this->connection->exec(sql: "DROP TABLE IF EXISTS {$this->table}");

        parent::tearDown();
    }

    public function testBindingAndColumnTypesRoundTrip(): void
    {
        $insert = $this->connection->fetchAll(
            sql: "INSERT INTO {$this->table} (
                int_col, bigint_col, numeric_col, real_col, double_col,
                varchar_col, text_col, bool_col, date_col, ts_col,
                bytea_col, null_col
            ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12) RETURNING id",
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
                'plainbytes',
                null,
            ],
        );

        self::assertCount(1, $insert);

        $rows = $this->connection->fetchAll(
            sql: "SELECT * FROM {$this->table} WHERE id = $1",
            bindings: [$insert[0]['id']],
        );

        self::assertCount(1, $rows);

        $row = $rows[0];

        self::assertSame(-42, $row['int_col']);
        self::assertSame(PHP_INT_MAX, $row['bigint_col']);
        self::assertSame('123.4500', $row['numeric_col']);
        self::assertEqualsWithDelta(3.5, $row['real_col'], 0.0001);
        self::assertEqualsWithDelta(2.718281828459045, $row['double_col'], 0.0000000001);
        self::assertSame('hello', $row['varchar_col']);
        self::assertSame('a longer piece of text', $row['text_col']);
        // PostgreSQL has a real boolean type — it round-trips as a PHP bool.
        self::assertTrue($row['bool_col']);
        self::assertStringContainsString('2026-06-16', (string) $row['date_col']);
        self::assertStringContainsString('2026-06-16T10:30:00', (string) $row['ts_col']);
        self::assertSame('plainbytes', $row['bytea_col']);
        self::assertNull($row['null_col']);
    }

    public function testFalseAndZeroBindings(): void
    {
        $insert = $this->connection->fetchAll(
            sql: "INSERT INTO {$this->table} (int_col, double_col, varchar_col, bool_col)
                VALUES ($1, $2, $3, $4) RETURNING id",
            bindings: [0, 0.0, '', false],
        );

        $rows = $this->connection->fetchAll(
            sql: "SELECT int_col, double_col, varchar_col, bool_col FROM {$this->table} WHERE id = $1",
            bindings: [$insert[0]['id']],
        );

        $row = $rows[0];

        self::assertSame(0, $row['int_col']);
        self::assertEqualsWithDelta(0.0, $row['double_col'], 0.0001);
        self::assertSame('', $row['varchar_col']);
        self::assertFalse($row['bool_col']);
    }

    public function testBindingsUsedInWhereClause(): void
    {
        $this->connection->exec(
            sql: "INSERT INTO {$this->table} (varchar_col, int_col, bool_col) VALUES ($1, $2, $3)",
            bindings: ['match', 7, true],
        );

        $this->connection->exec(
            sql: "INSERT INTO {$this->table} (varchar_col, int_col, bool_col) VALUES ($1, $2, $3)",
            bindings: ['other', 7, false],
        );

        $rows = $this->connection->fetchAll(
            sql: "SELECT varchar_col FROM {$this->table} WHERE int_col = $1 AND varchar_col = $2 AND bool_col = $3",
            bindings: [7, 'match', true],
        );

        self::assertCount(1, $rows);
        self::assertSame('match', $rows[0]['varchar_col']);
    }
}
