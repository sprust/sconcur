English | [Русский](mysql.ru.md)

# MySQL (universal SQL feature)

Asynchronous work with a relational database on top of sqlx. Every
query goes into the extension and runs in a runtime task while the coroutine is
suspended, so dozens of queries proceed in parallel. Outside a `WaitGroup` the same
API works synchronously.

The feature is driver-agnostic: the `SConcur\Features\Sql` core knows nothing about
a specific database, and `SConcur\Features\Mysql\Connection` is a thin facade that
sets the MySQL driver (`MethodEnum::Mysql`). [PostgreSQL](pgsql.md) is a mirror
facade over the same core.

## Quick start

```php
$connection = new \SConcur\Features\Mysql\Connection(
    dsn: 'user:pass@tcp(127.0.0.1:3306)/app',
    timeoutMs: 5000,
);

// streaming rows (large result sets are not buffered whole)
foreach ($connection->query('SELECT id, name FROM users WHERE age > ?', [18]) as $row) {
    echo $row['name'] . PHP_EOL;
}

// the whole result set at once
$rows = $connection->fetchAll('SELECT * FROM users WHERE id = ?', [$id]);

// mutation: affectedRows + lastInsertId
$result = $connection->exec('INSERT INTO users (name) VALUES (?)', ['Ann']);
echo $result->lastInsertId;
```

Inside `WaitGroup::add(...)` the same calls run concurrently.

## DSN and bindings

- The DSN shape is `user:pass@tcp(host:port)/dbname`; a `mysql://` URL is
  accepted too. Only the host, port, user, password and database are read — a
  query string after them is parsed off and dropped, so no DSN parameter changes
  anything.
- Placeholders are `?`. Bindings are a positional list; the SQL is not rewritten
  and the values reach the server as parameters of a prepared statement (integers
  as 64-bit, floats as doubles), which protects against SQL injection.

## Transactions

A transaction is pinned to a single connection across a series of commands:

```php
$transaction = $connection->begin();

try {
    $transaction->exec('UPDATE accounts SET balance = balance - ? WHERE id = ?', [100, $from]);
    $transaction->exec('UPDATE accounts SET balance = balance + ? WHERE id = ?', [100, $to]);
    $transaction->commit();
} catch (\Throwable $exception) {
    $transaction->rollback();

    throw $exception;
}
```

`begin(int $isolationLevel = 0, bool $readOnly = false)` takes an isolation level
(the numeric values the API was first written against; `0` — the
server's default) and a read-only flag.
On MySQL the isolation level is refused — see [Limits](#limits). `Transaction` has
the same `query`/`fetchAll`/`exec` as the connection.

If a transaction is abandoned without `commit()`/`rollback()` (an exception, an
early exit, a `WaitGroup` stop), the extension rolls it back automatically: the
transaction is bound to the begin task's context, which is cancelled when the flow
stops. On the synchronous path the `Transaction` destructor additionally releases
the held flow. Within one `WaitGroup` each coroutine holds its own transaction on
its own connection, so transactions run in parallel.

## Connection parameters

| Parameter | Default | Purpose |
|---|---|---|
| `dsn` | — | driver connection string |
| `timeoutMs` | 30000 | deadline for one exec; for a query — for the whole cursor lifetime |
| `maxOpenConns` | 0 → 32 | connection cap of the pool; `0` means the built-in 32, not "unlimited" |
| `maxIdleConns` | = `maxOpenConns` | accepted and not applied: the pool keeps every idle connection up to the cap. It is still part of the pool key, so two values mean two pools |
| `connMaxLifetimeMs` | 0 (no limit) | how long a connection may be reused before the pool retires it |

`timeoutMs` bounds each individual exec; for a query it is carried into the
streaming cursor and bounds its whole lifetime — every `next()` batch and the
PHP-side consumption included, so a slow `foreach` over a large result can hit it
mid-iteration. It does not apply to the lifetime of a transaction, which lives
until commit/rollback or a flow stop.

## Connection pool and concurrency

Every operation runs on a connection from a pool that lives in the
extension and is reused across tasks and coroutines. The pool is shared by all
`Connection`s with the same DSN and pool sizes (the key is `driver+dsn+sizes`);
an unused pool untouched for longer than 5 minutes is closed, and all pools are
closed when the extension stops. The pool starts empty, grows on demand and keeps
what it has opened up to the cap, so a batch of concurrent queries does not pay
the handshakes again on the next batch.

In a `WaitGroup` each autocommit operation and each transaction takes a separate
connection for its duration, so `N` coroutines open up to `maxOpenConns`
connections at once. The cap is per process: with a pool of servers, multiply it
by their number before comparing against `max_connections`, or the server answers
`1040 Too many connections`. Set `maxOpenConns` to the parallelism you expect:

```php
$connection = new \SConcur\Features\Mysql\Connection(
    dsn: 'user:pass@tcp(127.0.0.1:3306)/app',
    maxOpenConns: 50,
);
```

When the pool is saturated, autocommit queries wait for a connection to free up
rather than failing. Transactions hold a connection
for their whole life, so with `maxOpenConns` less than the number of coroutines
the extra `begin()` calls block and the transactions proceed in waves — keep the
pool no smaller than the expected number of concurrent transactions.

## Internals

- Pool registry (`ext/src/features/sql/pools.rs`) — `sqlx::MySqlPool`/`PgPool` keyed by the
  `driver+dsn+pool sizes` struct, with a refcount so a pool in use is never swept
  and eviction of idle ones after 5 minutes. The sweeper walks the registry once a
  minute.
- SELECT streaming (`rows_state.rs`) — `RowsState` holds the live sqlx stream and gives
  out rows in batches (`batchSize` comes from the PHP side, default 50; `<= 0` —
  one unbounded batch) with a one-row look-ahead to detect whether a next batch
  exists. It is closed on exhaustion, an early `break` or a flow stop.
- Transactions (`transactions.rs`) — `begin` puts a `TransactionSession` (holding the
  sqlx transaction) into the registry keyed by the holding begin task and keeps
  that task alive (`hasNext`) so the connection survives the series of commands;
  `commit`/`rollback` finalize the session idempotently — the handle is taken out
  of its slot, so the second finalizer finds nothing to do.

## Limits

- Value types. Integers → `int`, `FLOAT`/`DOUBLE` → `float`, `VARCHAR`/`TEXT`/`CHAR`
  and every binary type (`BLOB`/`BINARY`/`JSON`/`BIT`/`GEOMETRY`) → string, byte for
  byte, `DECIMAL` → string (to avoid losing precision), `DATE`/`DATETIME`/`TIMESTAMP`
  → an RFC3339 string with the fractional second trimmed of trailing zeroes
  (`2026-12-06T14:30:00.25Z`), `TIME` → `HH:MM:SS` padded to the column's declared
  precision (`14:30:00.500000`), with a leading `-` for a negative span, `NULL` →
  `null`.
  `TINYINT(1)` is an `int`, not a `bool`.
- An unsigned `BIGINT` larger than `PHP_INT_MAX` arrives as its decimal **string**,
  because it does not fit a signed 64-bit int. Compare and store such values as
  strings.
- `begin(isolationLevel:)` is **refused** on MySQL. The level has to be set by a
  separate statement before the transaction starts, on the same connection, and
  the driver hands out a transaction that already owns its pooled connection —
  there is no moment in between. Set it on the session or on the server instead.
  `begin(readOnly:)` works: it rides in `START TRANSACTION`.
- A cursor inside a transaction must either be read to the end or replaced with
  `fetchAll` before running the next command of the same transaction — otherwise
  the connection is busy with the open cursor.
- The library's general limits apply — see the [README](../README.md).
