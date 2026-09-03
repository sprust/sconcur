English | [Русский](pgsql.ru.md)

# PostgreSQL (on top of the universal SQL feature)

PgSQL is the second driver of the same SQL feature on Go `database/sql` (the
`jackc/pgx` driver). The core (`SConcur\Features\Sql`) is shared with MySQL, and
`SConcur\Features\Pgsql\Connection` is a thin facade that sets the driver.
Streaming, pool, transactions and concurrency behave identically — see
[docs/mysql.md](mysql.md); only the PostgreSQL differences are described here.

```php
$connection = new \SConcur\Features\Pgsql\Connection(
    dsn: 'postgres://user:pass@127.0.0.1:5432/app?sslmode=disable',
    timeoutMs: 5000,
);

foreach ($connection->query('SELECT id, name FROM users WHERE age > $1', [18]) as $row) {
    echo $row['name'] . PHP_EOL;
}

$rows = $connection->fetchAll('SELECT * FROM users WHERE id = $1', [$id]);

$result = $connection->exec('UPDATE users SET name = $1 WHERE id = $2', ['Ann', $id]);
echo $result->affectedRows;
```

## Differences from MySQL

- Placeholders are numbered `$1, $2, …`, not `?`. Bindings stay a positional list.
- The DSN is the pgx/libpq format:
  `postgres://user:pass@host:port/dbname?sslmode=...` (or keyword/value
  `host=… port=… user=… dbname=…`). Useful parameters: `sslmode`,
  `connect_timeout` (seconds).
- No last-insert-id: `exec()->lastInsertId` is always `0`. Use
  `INSERT … RETURNING id` and read it as a result row:
  ```php
  $rows = $connection->fetchAll('INSERT INTO users (name) VALUES ($1) RETURNING id', ['Ann']);
  $id = $rows[0]['id'];
  ```
- `BOOLEAN` is a real type and comes back as a PHP `bool`, not `0/1` (in MySQL it
  is `TINYINT(1)` → `int`). `NUMERIC`/`DECIMAL` is a string, as in MySQL.
- No `interpolateParams` — pgx has no such flag, and queries go through the
  extended (prepared) protocol by default.
- A transaction aborts on error: after a failing query PostgreSQL puts it into the
  aborted state, and further commands fail with `current transaction is aborted`
  until `rollback()`.

## Limits

Binary data with NUL bytes in `BYTEA` via a binding does not work: the string value
is passed as text, and PostgreSQL rejects invalid UTF-8 (`0x00`). Encode arbitrary
binary data (hex, base64) and decode it on the DB or application side; ASCII bytes
in `BYTEA` work.

Results arrive in PostgreSQL's binary format, so a column is readable only if this
core knows how to render its binary form. Decoded: the integer, floating-point and
`NUMERIC` types (including `NaN` and the infinities, as those words), `BOOL`,
`DATE`/`TIMESTAMP`/`TIMESTAMPTZ`, `TIME`, `UUID`, `BYTEA`, `JSON`, `JSONB`, `TEXT`
/`VARCHAR`/`CHAR`/`NAME`, `XML`, and enum types.

Anything else — arrays, `INTERVAL`, `INET`/`CIDR`, `MONEY`, `OID`, ranges, composite
types, the geometric types — is **refused by name**, with an error saying which
column and type. Cast it in the query (`SELECT tags::text`) and parse the text on
the PHP side. The refusal is deliberate: those bytes are a wire structure, and
handing them over as a string is indistinguishable from a value the application
asked for.

Other limits and internals (pool, streaming, cancellation) are shared with
[MySQL](mysql.md).
