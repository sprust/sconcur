<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Pgsql;

use SConcur\Features\Pgsql\Connection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestPgsqlResolver;
use Throwable;

class PgsqlErrorTest extends BaseTestCase
{
    protected Connection $connection;

    protected string $table = 'sconcur_error_test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = TestPgsqlResolver::getConnection();

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
            sql: "INSERT INTO {$this->table} (id, name) VALUES ($1, $2)",
            bindings: [1, 'first'],
        );

        $exception = $this->catch(function (): void {
            $this->connection->exec(
                sql: "INSERT INTO {$this->table} (id, name) VALUES ($1, $2)",
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
        $connection = TestPgsqlResolver::getConnection(timeoutMs: 500);

        $startedAt = microtime(true);

        $exception = $this->catch(function () use ($connection): void {
            $connection->fetchAll(sql: 'SELECT pg_sleep(5)');
        });

        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        $this->assertSqlException($exception);

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
            dsn: 'postgres://sc_user:_sc_password_567@192.0.2.1:5432/u_test?sslmode=disable&connect_timeout=1',
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
            sql: "INSERT INTO {$this->table} (id, name) VALUES ($1, $2)",
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

    public function testTransactionAbortedAfterErrorUntilRollback(): void
    {
        $transaction = $this->connection->begin();

        $transaction->exec(
            sql: "INSERT INTO {$this->table} (id, name) VALUES ($1, $2)",
            bindings: [1, 'ok'],
        );

        // Trigger an error inside the transaction.
        $this->catch(function () use ($transaction): void {
            $transaction->exec(sql: 'SELECT * FROM totally_missing_table');
        });

        // PostgreSQL-specific: the transaction is now aborted, so any further
        // statement fails ("current transaction is aborted") until rollback.
        $aborted = $this->catch(function () use ($transaction): void {
            $transaction->fetchAll(sql: 'SELECT 1');
        });

        $this->assertSqlException($aborted);

        $transaction->rollback();

        // The connection is usable again after rollback, and nothing was persisted.
        $rows = $this->connection->fetchAll(sql: "SELECT id FROM {$this->table}");

        self::assertCount(0, $rows);
    }

    public function testBinaryWithNulByteFailsOnUtf8(): void
    {
        // Documented limitation: a binding is sent as a text parameter, so PostgreSQL
        // rejects invalid UTF-8 — binary data containing NUL bytes cannot be bound
        // directly (encode it, e.g. hex/base64). This locks that behaviour.
        $exception = $this->catch(function (): void {
            $this->connection->fetchAll(
                sql: 'SELECT $1::bytea AS value',
                bindings: ["\x00\x01binary"],
            );
        });

        $this->assertSqlException($exception);
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
            'pgsql:',
            $exception->getMessage(),
            "Unexpected exception message: {$exception->getMessage()}",
        );
    }
}
