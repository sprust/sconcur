English | [Русский](mysql.ru.md)

# MySQL (universal SQL feature)

Asynchronous work with a relational database on top of Go `database/sql`. Every
query goes into the Go extension and runs in a goroutine while the coroutine is
suspended — dozens of queries proceed in parallel. Outside a `WaitGroup` the same
API works synchronously.

The feature is driver-agnostic: the `SConcur\Features\Sql` core knows nothing
about a specific database, and `SConcur\Features\Mysql\Connection` is a thin facade
that sets the MySQL driver (`MethodEnum::Mysql`). PgSQL is added with a mirror
facade without changing the core.

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

- DSN — the go-sql-driver/mysql format:
  `user:pass@tcp(host:port)/dbname?param=value`. Useful parameters:
  `parseTime=true` (dates as `time.Time`), `charset`, `loc`.
- `interpolateParams=true` is added to the DSN by default by the `Mysql\Connection`
  facade, unless the flag is already set there. A query with bindings goes in one
  round-trip (COM_QUERY with client-side value interpolation) instead of
  PREPARE + EXECUTE + CLOSE. This is faster on the synchronous path and matches
  PDO's default behaviour; escaping (injection protection) is done by the driver.
  To get server-side prepared statements back, pass `interpolateParams=false` in
  the DSN explicitly.
- Placeholders are the driver's native ones: for MySQL that is `?`. Bindings are a
  positional list; the SQL is not rewritten, values go to the driver as parameters
  (integer types are normalized to int64, float to float64), which protects against
  SQL injection.

## Transactions

A transaction is pinned to a single connection across a series of commands. It is
opened with `begin()`; finished with `commit()` or `rollback()`.

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
(Go `sql.IsolationLevel` values; `0` — the driver's default level) and a read-only
flag. The `Transaction` object has the same `query`/`fetchAll`/`exec` as the
connection.

Rollback on abort. If a transaction is abandoned without `commit()/rollback()` (an
exception, an early exit, a `WaitGroup` stop), the Go side rolls it back
automatically: the transaction is bound to the begin task's context, which is
cancelled when the flow stops, and `database/sql` does the rollback. On the
synchronous path the `Transaction` destructor additionally releases the held flow.

Concurrent transactions. Within one `WaitGroup` each coroutine holds its own
transaction (its own connection from the pool) — the transactions run in parallel.

## Connection parameters

The `Connection` constructor:

| Parameter           | Default | Purpose |
|---------------------|--------------|------------|
| `dsn`               | —            | driver connection string |
| `timeoutMs`         | 30000        | deadline for a single query/exec operation |
| `maxOpenConns`      | 0 (no limit)   | pool `SetMaxOpenConns` |
| `maxIdleConns`      | = `maxOpenConns` | pool `SetMaxIdleConns` |
| `connMaxLifetimeMs` | 0 (no limit)   | pool `SetConnMaxLifetime` |

`timeoutMs` bounds each individual query/exec (a mandatory execution deadline). It
does not apply to the lifetime of the transaction itself — a transaction lives
until commit/rollback or a flow stop.

## Connection pool and concurrency

Every operation runs on a connection from a `*sql.DB` pool that lives in the Go
extension and is reused across tasks and coroutines. The pool is shared by all
`Connection`s with the same DSN and pool sizes (the key is `driver+dsn+sizes`);
`*sql.DB` itself is thread-safe. An unused pool untouched for longer than 5 minutes
is closed; all pools are closed when the extension stops.

The pool size is set in the `Connection` constructor:

- `maxOpenConns` — the maximum of simultaneous connections (`0` — no limit);
- `maxIdleConns` — how many idle connections to keep. If not set, the `maxOpenConns`
  value is used: otherwise Go keeps only 2 idle connections, and after each
  concurrent fan-out the pool collapses, so the next fan pays for the handshakes
  again. An explicit value takes priority;
- `connMaxLifetimeMs` — the maximum lifetime of a connection.

On parallelism. In a `WaitGroup` each autocommit operation and each transaction
takes a separate connection for the duration of its execution. Launch `N` coroutines
with an unlimited pool and you will open up to `N` connections at once — and **hit
the server limit** (`max_connections`, MySQL error `1040 Too many connections`).

Limit the pool via `maxOpenConns`:

```php
$connection = new \SConcur\Features\Mysql\Connection(
    dsn: 'user:pass@tcp(127.0.0.1:3306)/app',
    maxOpenConns: 50,
);
```

- Autocommit queries. When the pool is saturated, `database/sql` queues the calls
  and waits for a connection to free up (backpressure) rather than failing with an
  error.
- Transactions. Each transaction holds a connection for its whole life
  (begin → … → commit/rollback). With `maxOpenConns` less than the number of
  coroutines, the extra `begin()` calls block until others free up — the
  transactions proceed in waves. So keep the pool no smaller than the expected
  number of concurrent transactions.

Tune `maxOpenConns` so that it is `<= max_connections` of the server and matches
the desired degree of parallelism.

## Internals

- Connection pool (`ext/internal/features/sql/pools.go`) — a registry of `*sql.DB`
  keyed by the `driver+dsn+pool sizes` struct (`poolKey`), with a refcount (`inUse`)
  and eviction of idle pools (like the MongoDB client pool). `*sql.DB` is thread-safe
  and reused across tasks. The sweeper walks the registry once a minute; a pool with
  no owners and no access for longer than 5 minutes is closed.
- SELECT streaming (`rows_state.go`) — the `rowsState` state holds a `*sql.Rows` and
  gives out rows in batches (`batchSize`, default 50) with a one-row look-ahead to
  detect whether a next batch exists. It is closed on exhaustion, an early `break`
  or a flow stop (`Close` closes the `*sql.Rows`, clears the deadline and returns the
  connection to the pool).
- Transaction (`transactions.go`) — `begin` puts a `transactionSession` (with a
  `*sql.Tx`) into a `sync.Map` (`pendingTransactions`) by id (the key of the holding
  begin task) and keeps the task "alive" (`hasNext`) so the connection survives the
  series of commands. `commit`/`rollback` finalize the session and release the
  holding task; finalization is idempotent (`sync.Once`).

## Limits

- Value types. The Go side normalizes scanned values: `[]byte` → string,
  `time.Time` → a string in RFC3339 format with nanoseconds; integers, `float`,
  `bool` and `NULL` pass through as is. On the MySQL side this means: integers — `int`,
  `FLOAT`/`DOUBLE` — `float`, `VARCHAR`/`TEXT`/`CHAR` and binary (`BLOB`/`BINARY`) —
  string, `DECIMAL` — string (to avoid losing precision), `DATE`/`DATETIME`/`TIMESTAMP`
  with `parseTime=true` in the DSN — an RFC3339 string, `NULL` — `null`. An unsigned
  `BIGINT` larger than `PHP_INT_MAX` is outside the range of a signed 64-bit `int` —
  store/read such values as a string.
- A cursor inside a transaction must either be read to the end or replaced with
  `fetchAll` before running the next command of the same transaction — otherwise the
  connection is busy with the open cursor.
- The library's general limits apply: CLI/NTS only, no `pcntl_fork` after the
  extension is loaded, do not terminate the process while tasks are active
  (see [README](../README.md)).
