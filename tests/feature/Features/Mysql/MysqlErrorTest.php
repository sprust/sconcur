<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mysql;

use SConcur\Features\Mysql\Connection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMysqlResolver;
use Throwable;

class MysqlErrorTest extends BaseTestCase
{
    protected Connection $connection;

    protected string $table = 'sconcur_error_test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = TestMysqlResolver::getConnection();

        $this->connection->exec(sql: "DROP TABLE IF EXISTS {$this->table}");
        $this->connection->exec(
            sql: "CREATE TABLE {$this->table} (id INT PRIMARY KEY, name VARCHAR(255) NOT NULL)",
        );
    }

    protected function tearDown(): void
    {
        $this->connection->exec(sql: "DROP TABLE IF EXISTS {$this->table}");

        parent::tearDown();
    }

    public function testExecSyntaxErrorFails(): void
    {
        $exception = $this->catch(function (): void {
            $this->connection->exec(sql: 'INSERT INTO nope_nope_nope (x) VALUES (1)');
        });

        $this->assertSqlException($exception);
    }

    public function testQuerySyntaxErrorFails(): void
    {
        $exception = $this->catch(function (): void {
            $this->connection->fetchAll(sql: 'SELECT this is not valid sql');
        });

        $this->assertSqlException($exception);
    }

    public function testConstraintViolationFailsAndConnectionStaysUsable(): void
    {
        $this->connection->exec(
            sql: "INSERT INTO {$this->table} (id, name) VALUES (?, ?)",
            bindings: [1, 'first'],
        );

        $exception = $this->catch(function (): void {
            $this->connection->exec(
                sql: "INSERT INTO {$this->table} (id, name) VALUES (?, ?)",
                bindings: [1, 'duplicate'],
            );
        });

        $this->assertSqlException($exception);

        // The connection (pool) is still usable after a failed statement.
        $rows = $this->connection->fetchAll(sql: "SELECT name FROM {$this->table}");

        self::assertCount(1, $rows);
        self::assertSame('first', $rows[0]['name']);
    }

    public function testStatementTimeoutFails(): void
    {
        $connection = TestMysqlResolver::getConnection(timeoutMs: 500);

        $startedAt = microtime(true);

        $exception = $this->catch(function () use ($connection): void {
            $connection->fetchAll(sql: 'SELECT SLEEP(5)');
        });

        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        $this->assertSqlException($exception);

        // The timeout (mandatory execution-time limit) must abort the query well
        // before its 5s sleep completes.
        self::assertLessThan(
            4000,
            $elapsedMs,
            "Statement timeout did not abort the query, took {$elapsedMs}ms",
        );
    }

    public function testUnreachableServerFailsFast(): void
    {
        // RFC 5737 TEST-NET-1: not routed, so a connect attempt hangs rather than
        // being refused — the deadline must bound it.
        $connection = new Connection(
            dsn: 'sc_user:_sc_password_567@tcp(192.0.2.1:3306)/u_test?timeout=1s',
            timeoutMs: 3000,
        );

        $startedAt = microtime(true);

        $exception = $this->catch(function () use ($connection): void {
            $connection->fetchAll(sql: 'SELECT 1');
        });

        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        $this->assertSqlException($exception);

        self::assertLessThan(
            10000,
            $elapsedMs,
            "Unreachable server should fail fast, took {$elapsedMs}ms",
        );
    }

    public function testTransactionStatementErrorThenRollback(): void
    {
        $transaction = $this->connection->begin();

        $transaction->exec(
            sql: "INSERT INTO {$this->table} (id, name) VALUES (?, ?)",
            bindings: [1, 'ok'],
        );

        $exception = $this->catch(function () use ($transaction): void {
            $transaction->exec(sql: 'INSERT INTO totally_missing_table (x) VALUES (1)');
        });

        $this->assertSqlException($exception);

        // After a failed statement the transaction can still be rolled back, and
        // nothing it wrote is persisted.
        $transaction->rollback();

        $rows = $this->connection->fetchAll(sql: "SELECT id FROM {$this->table}");

        self::assertCount(0, $rows);
    }

    protected function catch(callable $callback): ?Throwable
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    }

    protected function assertSqlException(?Throwable $exception): void
    {
        self::assertNotNull($exception, 'Expected a SQL error to be thrown.');

        self::assertStringContainsString(
            'mysql:',
            $exception->getMessage(),
            "Unexpected exception message: {$exception->getMessage()}",
        );
    }
}
