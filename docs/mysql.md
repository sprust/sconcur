English | [Русский](mysql.ru.md)

# MySQL (universal SQL feature)

Asynchronous work with a relational database on top of Go `database/sql`. Every
query goes into the Go extension and runs in a goroutine while the coroutine is
suspended, so dozens of queries proceed in parallel. Outside a `WaitGroup` the same
API works synchronously.

The feature is driver-agnostic: the `SConcur\Features\Sql` core knows nothing about
a specific database, and `SConcur\Features\Mysql\Connection` is a thin facade that
sets the MySQL driver (`MethodEnum::Mysql`). [PostgreSQL](pgsql.md) is a mirror
facade over the same core.

## Quick start

```php
$connection = new \SConcur\Features\Mysql\Connection(
    dsn: 'user:pass@tcp(127.0.0.1:3306)/app?parseTime=true',
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

- DSN is the go-sql-driver/mysql format:
  `user:pass@tcp(host:port)/dbname?param=value`. Useful parameters:
  `parseTime=true` (dates as `time.Time`), `charset`, `loc`.
- `interpolateParams=true` is added to the DSN by default by the `Mysql\Connection`
  facade unless the flag is already there. A query with bindings then goes in one
  round-trip (COM_QUERY with client-side interpolation) instead of PREPARE +
  EXECUTE + CLOSE — faster on the synchronous path and matching PDO's default
  behaviour; escaping is done by the driver. Pass `interpolateParams=false`
  explicitly to get server-side prepared statements back.
- Placeholders are the driver's native `?`. Bindings are a positional list; the SQL
  is not rewritten and values go to the driver as parameters (integers normalized
  to int64, floats to float64), which protects against SQL injection.

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
(Go `sql.IsolationLevel` values; `0` — the driver's default) and a read-only flag.
`Transaction` has the same `query`/`fetchAll`/`exec` as the connection.

If a transaction is abandoned without `commit()`/`rollback()` (an exception, an
early exit, a `WaitGroup` stop), the Go side rolls it back automatically: the
transaction is bound to the begin task's context, which is cancelled when the flow
stops. On the synchronous path the `Transaction` destructor additionally releases
the held flow. Within one `WaitGroup` each coroutine holds its own transaction on
its own connection, so transactions run in parallel.

## Connection parameters

| Parameter | Default | Purpose |
|---|---|---|
| `dsn` | — | driver connection string |
| `timeoutMs` | 30000 | deadline for one exec; for a query — for the whole cursor lifetime |
| `maxOpenConns` | 0 (no limit) | pool `SetMaxOpenConns` |
| `maxIdleConns` | = `maxOpenConns` | pool `SetMaxIdleConns` |
| `connMaxLifetimeMs` | 0 (no limit) | pool `SetConnMaxLifetime` |

`timeoutMs` bounds each individual exec; for a query it is carried into the
streaming cursor and bounds its whole lifetime — every `next()` batch and the
PHP-side consumption included, so a slow `foreach` over a large result can hit it
mid-iteration. It does not apply to the lifetime of a transaction, which lives
until commit/rollback or a flow stop.

## Connection pool and concurrency

Every operation runs on a connection from a `*sql.DB` pool that lives in the Go
extension and is reused across tasks and coroutines. The pool is shared by all
`Connection`s with the same DSN and pool sizes (the key is `driver+dsn+sizes`); an
unused pool untouched for longer than 5 minutes is closed, and all pools are closed
when the extension stops. If `maxIdleConns` is not set, the `maxOpenConns` value is
used — otherwise Go keeps only 2 idle, the pool collapses after each fan-out, and
the next fan pays for the handshakes again.

In a `WaitGroup` each autocommit operation and each transaction takes a separate
connection for its duration. Launch `N` coroutines with an unlimited pool and you
will open up to `N` connections at once — and **hit the server limit**
(`max_connections`, MySQL error `1040 Too many connections`). So keep
`maxOpenConns` `<= max_connections` and matched to the desired parallelism:

```php
$connection = new \SConcur\Features\Mysql\Connection(
    dsn: 'user:pass@tcp(127.0.0.1:3306)/app',
    maxOpenConns: 50,
);
```

When the pool is saturated, autocommit queries are queued by `database/sql` until a
connection frees up (backpressure) rather than failing. Transactions hold a
connection for their whole life, so with `maxOpenConns` less than the number of
coroutines the extra `begin()` calls block and the transactions proceed in waves —
keep the pool no smaller than the expected number of concurrent transactions.

## Internals

- Pool registry (`ext/internal/features/sql/pools.go`) — `*sql.DB` keyed by the
  `driver+dsn+pool sizes` struct, with a refcount and eviction of idle pools (like
  the MongoDB client pool). The sweeper walks the registry once a minute.
- SELECT streaming (`rows_state.go`) — `rowsState` holds a `*sql.Rows` and gives
  out rows in batches (`batchSize` comes from the PHP side, default 50; `<= 0` —
  one unbounded batch) with a one-row look-ahead to detect whether a next batch
  exists. It is closed on exhaustion, an early `break` or a flow stop.
- Transactions (`transactions.go`) — `begin` puts a `transactionSession` (with a
  `*sql.Tx`) into `pendingTransactions` keyed by the holding begin task and keeps
  that task alive (`hasNext`) so the connection survives the series of commands;
  `commit`/`rollback` finalize the session idempotently (`sync.Once`).

## Limits

- Value types. The Go side normalizes scanned values: `[]byte` → string,
  `time.Time` → an RFC3339 string with nanoseconds; integers, floats, booleans and
  `NULL` pass through as is. On MySQL that means: integers → `int`,
  `FLOAT`/`DOUBLE` → `float`, `VARCHAR`/`TEXT`/`CHAR` and binary
  (`BLOB`/`BINARY`) → string, `DECIMAL` → string (to avoid losing precision),
  `DATE`/`DATETIME`/`TIMESTAMP` with `parseTime=true` → an RFC3339 string, `NULL` →
  `null`. An unsigned `BIGINT` larger than `PHP_INT_MAX` is outside the range of a
  signed 64-bit int — store and read such values as a string.
- A cursor inside a transaction must either be read to the end or replaced with
  `fetchAll` before running the next command of the same transaction — otherwise
  the connection is busy with the open cursor.
- The library's general limits apply — see the [README](../README.md).
