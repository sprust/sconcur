<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Pgsql;

use SConcur\Features\Sql\Connection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestPgsqlResolver;
use SConcur\WaitGroup;
use Throwable;

/**
 * Nested transactions, which on both drivers are savepoints inside one real
 * transaction — this is what a framework's transaction() inside another
 * transaction() emits. The MySQL half is in
 * tests/feature/Features/Mysql/MysqlNestedTransactionTest.php.
 */
class PgsqlNestedTransactionTest extends BaseTestCase
{
    protected Connection $connection;

    protected string $table = 'sconcur_nested_transaction_test';

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

    public function testSavepointStatementsAreAccepted(): void
    {
        $transaction = $this->connection->begin();

        $transaction->exec(sql: 'SAVEPOINT sp1');
        $transaction->exec(sql: 'ROLLBACK TO SAVEPOINT sp1');
        $transaction->exec(sql: 'RELEASE SAVEPOINT sp1');

        $transaction->rollback();

        self::assertSame(0, $this->countRows());
    }

    public function testRollbackToSavepointDiscardsOnlyTheInnerWrite(): void
    {
        $transaction = $this->connection->begin();

        $transaction->exec(
            sql: "INSERT INTO {$this->table} (name) VALUES ($1)",
            bindings: ['outer'],
        );

        $transaction->exec(sql: 'SAVEPOINT sp1');

        $transaction->exec(
            sql: "INSERT INTO {$this->table} (name) VALUES ($1)",
            bindings: ['inner'],
        );

        $transaction->exec(sql: 'ROLLBACK TO SAVEPOINT sp1');

        $transaction->commit();

        self::assertSame(['outer'], $this->names());
    }

    public function testReleasedSavepointKeepsBothWrites(): void
    {
        $transaction = $this->connection->begin();

        $transaction->exec(
            sql: "INSERT INTO {$this->table} (name) VALUES ($1)",
            bindings: ['outer'],
        );

        $transaction->exec(sql: 'SAVEPOINT sp1');

        $transaction->exec(
            sql: "INSERT INTO {$this->table} (name) VALUES ($1)",
            bindings: ['inner'],
        );

        $transaction->exec(sql: 'RELEASE SAVEPOINT sp1');

        $transaction->commit();

        self::assertSame(['outer', 'inner'], $this->names());
    }

    public function testSavepointsUnwindLevelByLevel(): void
    {
        $transaction = $this->connection->begin();

        foreach (['level1', 'level2', 'level3'] as $depth => $name) {
            $transaction->exec(sql: 'SAVEPOINT sp' . ($depth + 1));

            $transaction->exec(
                sql: "INSERT INTO {$this->table} (name) VALUES ($1)",
                bindings: [$name],
            );
        }

        // Back to the second level: the third level's write goes, the first two stay.
        $transaction->exec(sql: 'ROLLBACK TO SAVEPOINT sp3');

        $transaction->commit();

        self::assertSame(['level1', 'level2'], $this->names());
    }

    public function testTheOuterRollbackDiscardsWritesBehindASavepoint(): void
    {
        $transaction = $this->connection->begin();

        $transaction->exec(sql: 'SAVEPOINT sp1');

        $transaction->exec(
            sql: "INSERT INTO {$this->table} (name) VALUES ($1)",
            bindings: ['inner'],
        );

        $transaction->exec(sql: 'RELEASE SAVEPOINT sp1');

        $transaction->rollback();

        self::assertSame(0, $this->countRows());
    }

    /**
     * A savepoint after a failed statement is the reason Postgres needs one at
     * all here: an error puts the whole transaction into the aborted state, and
     * rolling back to the savepoint is what makes it usable again.
     */
    public function testASavepointRecoversAnAbortedTransaction(): void
    {
        $transaction = $this->connection->begin();

        $transaction->exec(
            sql: "INSERT INTO {$this->table} (name) VALUES ($1)",
            bindings: ['kept'],
        );

        $transaction->exec(sql: 'SAVEPOINT sp1');

        $failed = false;

        try {
            $transaction->exec(sql: 'INSERT INTO totally_missing_table (x) VALUES (1)');
        } catch (Throwable) {
            $failed = true;
        }

        self::assertTrue($failed, 'A statement against a missing table should fail');

        $transaction->exec(sql: 'ROLLBACK TO SAVEPOINT sp1');

        $transaction->commit();

        self::assertSame(['kept'], $this->names());
    }

    /**
     * A savepoint is per connection, and a transaction is pinned to one, so
     * concurrent coroutines each nest on their own without seeing each other's
     * savepoint names.
     */
    public function testConcurrentTransactionsNestIndependently(): void
    {
        $connection = $this->connection;
        $table      = $this->table;

        $waitGroup = WaitGroup::create();

        foreach (['Ann', 'Bob', 'Cleo'] as $name) {
            $waitGroup->add(
                callback: function () use ($connection, $table, $name): void {
                    $transaction = $connection->begin();

                    $transaction->exec(
                        sql: "INSERT INTO $table (name) VALUES (\$1)",
                        bindings: [$name],
                    );

                    // The same savepoint name in every coroutine: each one is on
                    // its own connection, so they cannot collide.
                    $transaction->exec(sql: 'SAVEPOINT sp1');

                    $transaction->exec(
                        sql: "INSERT INTO $table (name) VALUES (\$1)",
                        bindings: ["$name-dropped"],
                    );

                    $transaction->exec(sql: 'ROLLBACK TO SAVEPOINT sp1');

                    $transaction->commit();
                },
            );
        }

        $waitGroup->waitAll();

        $names = $this->names();

        sort($names);

        self::assertSame(['Ann', 'Bob', 'Cleo'], $names);
    }

    /**
     * @return list<string>
     */
    protected function names(): array
    {
        $rows = $this->connection->fetchAll(sql: "SELECT name FROM {$this->table} ORDER BY id");

        return array_map(static fn(array $row): string => (string) $row['name'], $rows);
    }

    protected function countRows(): int
    {
        $rows = $this->connection->fetchAll(sql: "SELECT COUNT(*) AS c FROM {$this->table}");

        return (int) $rows[0]['c'];
    }
}
