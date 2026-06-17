<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Pgsql;

use SConcur\Features\Sql\Connection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestPgsqlResolver;
use SConcur\WaitGroup;

class PgsqlTransactionTest extends BaseTestCase
{
    protected Connection $connection;

    protected string $table = 'sconcur_transaction_test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = TestPgsqlResolver::getConnection();

        $this->connection->exec(sql: "DROP TABLE IF EXISTS {$this->table}");
        $this->connection->exec(
            sql: "CREATE TABLE {$this->table} (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL
            )",
        );
    }

    protected function tearDown(): void
    {
        $this->connection->exec(sql: "DROP TABLE IF EXISTS {$this->table}");

        parent::tearDown();
    }

    public function testCommitPersistsRows(): void
    {
        $transaction = $this->connection->begin();

        $transaction->exec(
            sql: "INSERT INTO {$this->table} (name) VALUES ($1)",
            bindings: ['committed'],
        );

        $transaction->commit();

        self::assertSame(1, $this->countRows());
    }

    public function testRollbackDiscardsRows(): void
    {
        $transaction = $this->connection->begin();

        $transaction->exec(
            sql: "INSERT INTO {$this->table} (name) VALUES ($1)",
            bindings: ['rolled-back'],
        );

        $transaction->rollback();

        self::assertSame(0, $this->countRows());
    }

    public function testAbandonedTransactionDoesNotPersist(): void
    {
        $transaction = $this->connection->begin();

        $transaction->exec(
            sql: "INSERT INTO {$this->table} (name) VALUES ($1)",
            bindings: ['abandoned'],
        );

        // No commit/rollback: dropping the only reference releases the held flow,
        // and the Go side rolls the transaction back from the cancelled context.
        unset($transaction);

        self::assertSame(0, $this->countRows());
    }

    public function testReadsOwnUncommittedWriteInsideTransaction(): void
    {
        $transaction = $this->connection->begin();

        $transaction->exec(
            sql: "INSERT INTO {$this->table} (name) VALUES ($1)",
            bindings: ['pending'],
        );

        $rows = $transaction->fetchAll(sql: "SELECT name FROM {$this->table}");

        self::assertCount(1, $rows);
        self::assertSame('pending', $rows[0]['name']);

        $transaction->rollback();

        self::assertSame(0, $this->countRows());
    }

    public function testConcurrentTransactionsFanOut(): void
    {
        $connection = $this->connection;
        $table      = $this->table;

        $waitGroup = WaitGroup::create();

        foreach (['Ann', 'Bob', 'Cleo'] as $name) {
            $waitGroup->add(
                callback: function () use ($connection, $table, $name): string {
                    $transaction = $connection->begin();

                    $transaction->exec(
                        sql: "INSERT INTO $table (name) VALUES (\$1)",
                        bindings: [$name],
                    );

                    $transaction->commit();

                    return $name;
                },
            );
        }

        $waitGroup->waitAll();

        self::assertSame(3, $this->countRows());
    }

    protected function countRows(): int
    {
        $rows = $this->connection->fetchAll(sql: "SELECT COUNT(*) AS c FROM {$this->table}");

        return (int) $rows[0]['c'];
    }
}
