<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mysql;

use SConcur\Features\Sql\Connection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMysqlResolver;
use SConcur\WaitGroup;
use Throwable;

/**
 * Nested transactions, which on both drivers are savepoints inside one real
 * transaction — this is what a framework's transaction() inside another
 * transaction() emits.
 *
 * On MySQL the savepoint statements are also the reason the driver picks the
 * text protocol for a statement with no bindings: the server refuses SAVEPOINT,
 * RELEASE SAVEPOINT, ROLLBACK TO SAVEPOINT, LOCK TABLES and UNLOCK TABLES in the
 * prepared-statement protocol with error 1295, so a prepared SAVEPOINT fails
 * outright and nothing nested can run.
 */
class MysqlNestedTransactionTest extends BaseTestCase
{
    protected Connection $connection;

    protected string $table = 'sconcur_nested_transaction_test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = TestMysqlResolver::getConnection();

        $this->connection->exec(sql: "DROP TABLE IF EXISTS {$this->table}");
        $this->connection->exec(
            sql: "CREATE TABLE {$this->table} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL
            ) ENGINE=InnoDB",
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

        // Reaching here at all is the assertion: each of the three used to come
        // back as "1295 (HY000): This command is not supported in the prepared
        // statement protocol yet".
        self::assertSame(0, $this->countRows());
    }

    public function testRollbackToSavepointDiscardsOnlyTheInnerWrite(): void
    {
        $transaction = $this->connection->begin();

        $transaction->exec(
            sql: "INSERT INTO {$this->table} (name) VALUES (?)",
            bindings: ['outer'],
        );

        $transaction->exec(sql: 'SAVEPOINT sp1');

        $transaction->exec(
            sql: "INSERT INTO {$this->table} (name) VALUES (?)",
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
            sql: "INSERT INTO {$this->table} (name) VALUES (?)",
            bindings: ['outer'],
        );

        $transaction->exec(sql: 'SAVEPOINT sp1');

        $transaction->exec(
            sql: "INSERT INTO {$this->table} (name) VALUES (?)",
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
                sql: "INSERT INTO {$this->table} (name) VALUES (?)",
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
            sql: "INSERT INTO {$this->table} (name) VALUES (?)",
            bindings: ['inner'],
        );

        $transaction->exec(sql: 'RELEASE SAVEPOINT sp1');

        $transaction->rollback();

        self::assertSame(0, $this->countRows());
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
                        sql: "INSERT INTO $table (name) VALUES (?)",
                        bindings: [$name],
                    );

                    // The same savepoint name in every coroutine: each one is on
                    // its own connection, so they cannot collide.
                    $transaction->exec(sql: 'SAVEPOINT sp1');

                    $transaction->exec(
                        sql: "INSERT INTO $table (name) VALUES (?)",
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
     * The other half of the 1295 list, in the shape docs/mysql.md recommends:
     * `begin()` is used to pin a connection, not for a transaction, because
     * LOCK TABLES commits whatever is open. The pinning is what makes the
     * unlock reach the connection that locked whatever the pool size, and the
     * finally is what keeps a thrown assertion from returning a locked
     * connection to the pool, where it would block every later query on the
     * table for the pool's idle life.
     */
    public function testTableLockingIsAcceptedAndPinsItsConnection(): void
    {
        // A second ordinary table, to be left out of the lock. It has to exist
        // before the lock is taken: DDL is another implicit commit, and while a
        // lock is held it would be refused anyway.
        $unlocked = "{$this->table}_unlocked";

        $this->connection->exec(sql: "DROP TABLE IF EXISTS $unlocked");
        $this->connection->exec(sql: "CREATE TABLE $unlocked (id INT PRIMARY KEY) ENGINE=InnoDB");

        $transaction = $this->connection->begin();

        $transaction->exec(sql: "LOCK TABLES {$this->table} WRITE");

        try {
            $refused = null;

            try {
                $transaction->fetchAll(sql: "SELECT id FROM $unlocked");
            } catch (Throwable $caught) {
                $refused = $caught;
            }

            // 1100 is the proof that this is the same connection and that it is
            // in lock-tables mode: while a lock is held, nothing but the locked
            // table is reachable on it.
            self::assertNotNull($refused, 'An unlocked table should be unreachable while a lock is held');
            self::assertStringContainsString('was not locked with LOCK TABLES', $refused->getMessage());

            // The locked table itself is writable on that same connection.
            $transaction->exec(
                sql: "INSERT INTO {$this->table} (name) VALUES (?)",
                bindings: ['locked'],
            );
        } finally {
            $transaction->exec(sql: 'UNLOCK TABLES');
            $transaction->rollback();

            $this->connection->exec(sql: "DROP TABLE IF EXISTS $unlocked");
        }

        // The rollback undid nothing: LOCK TABLES had already committed the
        // transaction out from under it, so the write stands.
        self::assertSame(['locked'], $this->names());
    }

    /**
     * A cursor carries one column list for its whole stream, so a second result
     * set could only reach PHP labelled with the first one's column names. The
     * text protocol is what makes a stacked SELECT reachable at all, so it is
     * refused where it becomes reachable.
     */
    public function testAStackedSelectIsRefused(): void
    {
        $exception = null;

        try {
            $this->connection->fetchAll(sql: 'SELECT 1 AS a; SELECT 2 AS b');
        } catch (Throwable $caught) {
            $exception = $caught;
        }

        self::assertNotNull($exception, 'A statement with two result sets should be refused');
        self::assertStringContainsString('more than one result set', $exception->getMessage());
    }

    /**
     * The same statement pair through exec(), which has one number to answer
     * with and answers for the batch.
     */
    public function testStackedStatementsExecuteAsOneBatch(): void
    {
        $result = $this->connection->exec(
            sql: "INSERT INTO {$this->table} (name) VALUES ('first'); "
                . "INSERT INTO {$this->table} (name) VALUES ('second')",
        );

        self::assertSame(2, $result->affectedRows);
        self::assertSame(['first', 'second'], $this->names());
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
