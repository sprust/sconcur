<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mysql;

use SConcur\Features\Mysql\Connection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMysqlResolver;
use Throwable;

/**
 * What the driver does to a session, and what a DSN parameter can do about it.
 *
 * The parameters used to be parsed off the DSN and dropped, so `charset=…` was
 * accepted and had no effect; and sqlx configures a connection on its own, which
 * left a session here reading `||` differently from a PDO one on the same server.
 */
class MysqlSessionTest extends BaseTestCase
{
    /**
     * With `PIPES_AS_CONCAT` in the session's sql_mode this returns the string
     * "10" instead — the same statement meaning two different things depending on
     * which client ran it.
     */
    public function testPipesStayMysqlsOrOperator(): void
    {
        $rows = $this->connect()->fetchAll(sql: 'SELECT 1 || 0 AS value');

        self::assertSame('1', (string) $rows[0]['value']);

        $mode = $this->connect()->fetchAll(sql: 'SELECT @@session.sql_mode AS mode');

        self::assertStringNotContainsString('PIPES_AS_CONCAT', (string) $mode[0]['mode']);
    }

    public function testTheCollationComesFromTheDsn(): void
    {
        $connection = $this->connect(parameters: 'charset=utf8mb4&collation=utf8mb4_unicode_ci');

        $rows = $connection->fetchAll(sql: 'SELECT @@session.collation_connection AS collation');

        self::assertSame('utf8mb4_unicode_ci', (string) $rows[0]['collation']);
    }

    public function testTheTimeZoneComesFromTheDsn(): void
    {
        // Written the way that DSN dialect writes a system variable: quoted and
        // percent-encoded.
        $connection = $this->connect(parameters: 'time_zone=%27%2B03%3A00%27');

        $rows = $connection->fetchAll(sql: 'SELECT @@session.time_zone AS zone');

        self::assertSame('+03:00', (string) $rows[0]['zone']);
    }

    /**
     * The default, applied when the DSN names no zone: a TIMESTAMP is written and
     * read as UTC.
     */
    public function testTheTimeZoneDefaultsToUtc(): void
    {
        $rows = $this->connect()->fetchAll(sql: 'SELECT @@session.time_zone AS zone');

        self::assertSame('+00:00', (string) $rows[0]['zone']);
    }

    /**
     * A parameter the driver has no option for is what this DSN format says it
     * is — a session system variable — and lands on every connection the pool
     * opens rather than on the first one.
     */
    public function testAnUnnamedParameterBecomesASessionVariable(): void
    {
        $connection = $this->connect(parameters: 'group_concat_max_len=4096');

        $rows = $connection->fetchAll(sql: 'SELECT @@session.group_concat_max_len AS value');

        self::assertSame('4096', (string) $rows[0]['value']);
    }

    /**
     * The one the driver writes for itself, and the reason a DSN variable is
     * applied after that: `sql_mode` has to win, or there is no way to ask for a
     * mode from the calling side at all. Asking for the very setting the driver
     * turns off is the sharpest form of that.
     */
    public function testTheSqlModeComesFromTheDsn(): void
    {
        $connection = $this->connect(parameters: 'sql_mode=%27PIPES_AS_CONCAT%27');

        $rows = $connection->fetchAll(sql: 'SELECT @@session.sql_mode AS mode');

        self::assertSame('PIPES_AS_CONCAT', (string) $rows[0]['mode']);

        // And it is the session that changed, not just the variable: `||` is
        // concatenation again.
        $value = $connection->fetchAll(sql: "SELECT 'a' || 'b' AS value");

        self::assertSame('ab', (string) $value[0]['value']);
    }

    /**
     * The driver negotiates CLIENT_FOUND_ROWS and cannot turn it off, so a DSN
     * asking for the opposite is refused instead of quietly ignored.
     */
    public function testAFlagTheDriverCannotHonourIsRefused(): void
    {
        $exception = $this->failedConnect(parameters: 'clientFoundRows=false');

        self::assertStringContainsString('clientFoundRows', $exception->getMessage());
    }

    /**
     * The consequence of that flag, and the reason it is worth a message: an
     * UPDATE writing the value already there counts as one row here and as none
     * under PDO.
     */
    public function testAffectedRowsCountsMatchedRows(): void
    {
        $connection = $this->connect();
        $table      = 'sconcur_session_test';

        $connection->exec(sql: "DROP TABLE IF EXISTS $table");
        $connection->exec(
            sql: "CREATE TABLE $table (
                id INT PRIMARY KEY,
                name VARCHAR(255) NOT NULL
            ) ENGINE=InnoDB",
        );

        try {
            $connection->exec(sql: "INSERT INTO $table (id, name) VALUES (1, 'same')");

            $result = $connection->exec(sql: "UPDATE $table SET name = 'same' WHERE id = 1");

            self::assertSame(1, $result->affectedRows);
        } finally {
            $connection->exec(sql: "DROP TABLE IF EXISTS $table");
        }
    }

    /**
     * A name that could not be a system variable never reaches the SET the pool
     * builds. Only the value is percent-decoded, as in the Go client, so an
     * escape in the name is part of the name and disqualifies it.
     */
    public function testAParameterNameThatIsNotAnIdentifierIsRefused(): void
    {
        $exception = $this->failedConnect(parameters: 'bad%20name=1');

        self::assertStringContainsString('bad%20name', $exception->getMessage());
    }

    /**
     * Every DSN in this repository carries `parseTime=true`, which meant something
     * to the Go client only.
     */
    public function testAGoClientOnlyParameterIsStillAccepted(): void
    {
        $rows = $this->connect(parameters: 'parseTime=true&timeout=5s')->fetchAll(sql: 'SELECT 1 AS value');

        self::assertSame('1', (string) $rows[0]['value']);
    }

    protected function connect(string $parameters = ''): Connection
    {
        return new Connection(
            dsn: TestMysqlResolver::buildDsn(parameters: $parameters),
            timeoutMs: 5000,
        );
    }

    protected function failedConnect(string $parameters): Throwable
    {
        $exception = null;

        try {
            $this->connect(parameters: $parameters)->fetchAll(sql: 'SELECT 1');
        } catch (Throwable $caught) {
            $exception = $caught;
        }

        self::assertNotNull($exception, "The dsn parameters \"$parameters\" should be refused");

        return $exception;
    }
}
