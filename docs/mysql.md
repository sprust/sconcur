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

- The DSN shape is `user:pass@tcp(host:port)/dbname`;
  `user:pass@unix(/var/run/mysqld/mysqld.sock)/dbname` connects over a Unix
  socket, and a `mysql://` URL is accepted too.
- Applied parameters: `charset`, `collation`, `time_zone` and `tls`. `tls` takes
  `false`, `preferred`, `skip-verify` (encrypt without checking the certificate)
  or `true` (verify the certificate and the host name), plus the `1`/`0`/`t`/`f`
  spellings of the first two; a named TLS configuration has no counterpart here
  and is refused rather than silently downgraded.
- Any other parameter is a session system variable, exactly as this DSN format
  defines it: it is issued as `SET name=value` on every connection the pool
  opens, after the driver's own session setup, so it wins over that. This is how
  to set `sql_mode`, `transaction_isolation`, `group_concat_max_len` and the
  rest. `character_set_client`, `character_set_results` and `NAMES` are refused
  instead — use `charset` and `collation`, which the driver has to know about.
- Values are percent-decoded, and a system variable is written quoted the way
  this format writes one: `time_zone=%27%2B00%3A00%27` → `+00:00`,
  `sql_mode=%27TRADITIONAL%27`. Unlike a URL query string, a literal `+` stays a
  `+` rather than becoming a space.
- Accepted and doing nothing, because they configured that Go client rather than
  the server: `parseTime`, `loc`, `timeTruncate`, `interpolateParams`,
  `columnsWithAlias`, `allowNativePasswords`, `allowOldPasswords`,
  `allowCleartextPasswords`, `allowAllFiles`, `allowFallbackToPlaintext`,
  `checkConnLiveness`, `compress`, `connectionAttributes`, `maxAllowedPacket`,
  `rejectReadOnly`, `serverPubKey`, `timeout`, `readTimeout`, `writeTimeout`.
  Query and exec deadlines come from `timeoutMs` instead, the connect included.
- `multiStatements` and `clientFoundRows` are accepted at `true` and **refused**
  at `false`: the driver negotiates both unconditionally and cannot turn either
  off, and `clientFoundRows` decides what `exec()` counts — see
  [Session settings](#session-settings).
- Placeholders are `?`. Bindings are a positional list; the SQL is not rewritten
  and the values reach the server as parameters of a prepared statement (integers
  as 64-bit, floats as doubles), which protects against SQL injection. A statement
  with no bindings is sent as text instead — see
  [Nested transactions](#nested-transactions).

## Session settings

The driver configures every new connection, so a session here is not identical to
a PDO one against the same server:

| Setting | What the driver does | How to change it |
|---|---|---|
| `sql_mode` | appends `NO_ENGINE_SUBSTITUTION` to whatever the server's default is | `sql_mode` in the DSN, which is applied after and replaces the lot |
| `IGNORE_SPACE` | asked for in the handshake capabilities, so it shows up in `@@sql_mode` too (it allows a space between a built-in function name and its `(`) | not from this side |
| matched vs changed rows | asks for `CLIENT_FOUND_ROWS`, so `exec()->affectedRows` counts the rows a statement matched, where PDO counts the rows it changed — an `UPDATE` writing the value already there answers 1 here and 0 there | not from this side |
| multiple statements | asks for `CLIENT_MULTI_STATEMENTS`, so a text statement may hold several separated by `;` — see [Nested transactions](#nested-transactions) | not from this side |
| `time_zone` | set to `+00:00`, so a `TIMESTAMP` is written and read as UTC | `time_zone` in the DSN |
| character set | `SET NAMES utf8mb4`, with the collation left to the server (`utf8mb4_0900_ai_ci` on MySQL 8) | `charset` and `collation` in the DSN |
| TLS | attempted whenever the server offers it and dropped when it does not, where this DSN format defaults to no TLS at all | `tls` in the DSN |

`PIPES_AS_CONCAT` is deliberately **not** set, although sqlx sets it by default:
it makes `||` string concatenation instead of MySQL's OR, and the same query
would then mean one thing here and another under PDO.

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

### Nested transactions

There is no nested `begin()`. A nested transaction is a savepoint inside the outer
one — the same thing a framework emits when `transaction()` is called inside
another `transaction()`:

```php
$transaction = $connection->begin();

$transaction->exec('INSERT INTO notes (body) VALUES (?)', ['outer']);

$transaction->exec('SAVEPOINT sp1');
$transaction->exec('INSERT INTO notes (body) VALUES (?)', ['inner']);
$transaction->exec('ROLLBACK TO SAVEPOINT sp1');   // only the inner row goes

$transaction->commit();
```

A savepoint belongs to the connection, and a transaction owns one for its whole
life, so concurrent coroutines can each use the same savepoint names without
colliding.

MySQL refuses a set of commands in the prepared-statement protocol with error
1295, "This command is not supported in the prepared statement protocol yet" —
`SAVEPOINT`, `RELEASE SAVEPOINT`, `ROLLBACK TO SAVEPOINT`, `LOCK TABLES` and
`UNLOCK TABLES` among them. A statement carrying no bindings is therefore sent as
text, which those commands accept; a statement with bindings stays prepared, so
values still travel as parameters.

### Table locks

`LOCK TABLES` is in that same 1295 list and works for the same reason, but it
needs care a pool makes sharper.

It commits any open transaction — MySQL does that itself, before the client
learns anything, so it is not something a driver can hold back and PDO behaves
the same way. A transaction that takes a table lock partway through has already
been committed by the time it does, and the `rollback()` that follows undoes
nothing. Where the point was atomicity, `SELECT … FOR UPDATE` inside the
transaction is the tool, not a table lock.

`LOCK TABLES` and `UNLOCK TABLES` also have to reach the same connection, and a
pool hands out whichever is free. `begin()` is what pins one: the `Transaction`
keeps its connection even after the lock has committed the transaction out from
under it, so the pair lands together whatever the pool size.

**A lock left behind poisons the pool.** Neither `commit()` nor `rollback()`
releases a table lock, so a connection returned to the pool still holding one
blocks every later query that touches the table — for up to the pool's five idle
minutes, on a connection nothing in the calling code still points at. `UNLOCK
TABLES` therefore belongs in a `finally`:

```php
$transaction = $connection->begin();   // here only to pin the connection

try {
    $transaction->exec('LOCK TABLES notes WRITE');
    // ... only `notes` is reachable now: any other table answers 1100
} finally {
    $transaction->exec('UNLOCK TABLES');
    $transaction->rollback();
}
```

The text protocol also runs several statements separated by `;` in one call,
where a prepared statement takes exactly one. Two consequences:

- Build such a string only from values you control. A statement assembled out of
  user input and no bindings can be made to carry a second statement, while the
  same input passed as a binding cannot.
- `exec()` runs them all and answers for the batch: `affectedRows` is their sum
  and `lastInsertId` comes from the last one. `query()` refuses a second result
  set — a cursor carries one column list for its whole stream, so rows of a
  second `SELECT` could only reach PHP labelled with the first one's column
  names. Run one `SELECT` per query, and call a procedure that returns several
  result sets from something else.

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
mid-iteration. It does not apply to `begin()`, nor to the lifetime of the
transaction it opens, which lives until commit/rollback or a flow stop — so a
`begin()` against a server that accepts the connection and never answers is not
bounded from here. The statements inside the transaction are bounded as usual.

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
