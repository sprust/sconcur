English | [Русский](pgsql.ru.md)

# PostgreSQL (on top of the universal SQL feature)

PgSQL is the second driver of the same SQL feature on top of Go `database/sql`
(the `jackc/pgx` driver). The core (`SConcur\Features\Sql`) is shared with MySQL;
`SConcur\Features\Pgsql\Connection` is a thin facade that sets the driver.
Behaviour (streaming, pool, transactions, concurrency) is identical — see
[docs/mysql.md](mysql.md). Only the PostgreSQL differences are described here.

## Quick start

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

- Placeholders are `$1, $2, …` (numbered), not `?`. Bindings are a positional
  list, passed to the driver as is.
- DSN is the pgx/libpq format: `postgres://user:pass@host:port/dbname?sslmode=...`
  (or keyword/value `host=… port=… user=… dbname=…`). Useful parameters:
  `sslmode`, `connect_timeout` (seconds).
- No last-insert-id: `exec()->lastInsertId` is always `0`. To get the id of an
  inserted row, use `INSERT … RETURNING id` and read it as a result row:
  ```php
  $rows = $connection->fetchAll('INSERT INTO users (name) VALUES ($1) RETURNING id', ['Ann']);
  $id = $rows[0]['id'];
  ```
- `BOOLEAN` is a real type: it comes back as a PHP `bool` (`true`/`false`), not
  as `0/1` (in MySQL it is `TINYINT(1)` → `int`).
- `NUMERIC`/`DECIMAL` is a string (precision is preserved), same as in MySQL.
- No `interpolateParams` — pgx has no such flag; queries go through the extended
  protocol (prepared) by default.
- A transaction aborts on error: after a failing query inside a transaction
  PostgreSQL puts it into the aborted state, and further commands until
  `rollback()` fail with `current transaction is aborted`. Call `rollback()`
  (it is allowed) and start over.

## Limits

- Binary data with NUL bytes in `BYTEA` via a binding: the string value is
  passed as text, and PostgreSQL rejects invalid UTF-8 (`0x00`). For arbitrary
  binary data, encode it (for example, to hex/base64) and decode it on the
  DB/application side. ASCII bytes in `BYTEA` work.
- Other limits and internals (pool, streaming, cancellation, value types) are
  shared with MySQL, see [docs/mysql.md](mysql.md).
