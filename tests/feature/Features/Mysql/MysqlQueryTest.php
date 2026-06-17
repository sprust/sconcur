<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mysql;

use SConcur\Features\Sql\Connection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMysqlResolver;

class MysqlQueryTest extends BaseTestCase
{
    protected Connection $connection;

    protected string $table = 'sconcur_query_test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = TestMysqlResolver::getConnection();

        $this->connection->exec(sql: "DROP TABLE IF EXISTS {$this->table}");
        $this->connection->exec(
            sql: "CREATE TABLE {$this->table} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                amount INT NOT NULL
            )",
        );
    }

    protected function tearDown(): void
    {
        $this->connection->exec(sql: "DROP TABLE IF EXISTS {$this->table}");

        parent::tearDown();
    }

    public function testExecReturnsAffectedRowsAndLastInsertId(): void
    {
        $result = $this->connection->exec(
            sql: "INSERT INTO {$this->table} (name, amount) VALUES (?, ?)",
            bindings: ['Ann', 10],
        );

        self::assertSame(1, $result->affectedRows);
        self::assertGreaterThan(0, $result->lastInsertId);
    }

    public function testQueryWithBindingsFiltersRows(): void
    {
        $this->seed();

        $rows = $this->connection->fetchAll(
            sql: "SELECT name, amount FROM {$this->table} WHERE amount > ? ORDER BY amount",
            bindings: [15],
        );

        self::assertCount(1, $rows);
        self::assertSame('Bob', $rows[0]['name']);
        self::assertEquals(20, $rows[0]['amount']);
    }

    public function testUpdateReportsAffectedRows(): void
    {
        $this->seed();

        $result = $this->connection->exec(
            sql: "UPDATE {$this->table} SET amount = amount + ? WHERE amount < ?",
            bindings: [5, 100],
        );

        self::assertSame(2, $result->affectedRows);
    }

    public function testNullAndTypedValuesDecode(): void
    {
        $rows = $this->connection->fetchAll(
            sql: 'SELECT ? AS int_value, ? AS string_value, NULL AS null_value',
            bindings: [42, 'hello'],
        );

        self::assertCount(1, $rows);
        self::assertArrayHasKey('null_value', $rows[0]);
        self::assertNull($rows[0]['null_value']);
        self::assertEquals(42, $rows[0]['int_value']);
        self::assertSame('hello', $rows[0]['string_value']);
    }

    public function testEmptyResultSet(): void
    {
        $rows = $this->connection->fetchAll(
            sql: "SELECT id FROM {$this->table} WHERE id = ?",
            bindings: [999999],
        );

        self::assertSame([], $rows);
    }

    public function testStreamingBatchesAndEarlyBreak(): void
    {
        for ($index = 1; $index <= 10; ++$index) {
            $this->connection->exec(
                sql: "INSERT INTO {$this->table} (name, amount) VALUES (?, ?)",
                bindings: ["name-$index", $index],
            );
        }

        $seen = 0;

        foreach ($this->connection->query(sql: "SELECT id FROM {$this->table} ORDER BY id", batchSize: 3) as $row) {
            ++$seen;

            self::assertArrayHasKey('id', $row);

            if ($seen === 4) {
                break;
            }
        }

        self::assertSame(4, $seen);
        // tearDown's assertNoTasksCount verifies the abandoned cursor was released.
    }

    protected function seed(): void
    {
        $this->connection->exec(
            sql: "INSERT INTO {$this->table} (name, amount) VALUES (?, ?)",
            bindings: ['Ann', 10],
        );

        $this->connection->exec(
            sql: "INSERT INTO {$this->table} (name, amount) VALUES (?, ?)",
            bindings: ['Bob', 20],
        );
    }
}
