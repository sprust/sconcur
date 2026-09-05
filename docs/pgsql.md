English | [Русский](pgsql.ru.md)

# PostgreSQL (on top of the universal SQL feature)

PgSQL is the second driver of the same SQL feature, sqlx's PostgreSQL one. The
core (`SConcur\Features\Sql`) is shared with MySQL, and
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
- The DSN is a URL: `postgres://user:pass@host:port/dbname?sslmode=...`. The
  keyword/value form (`host=… port=… dbname=…`) is refused. `sslmode` takes
  effect; a parameter the driver does not know is ignored silently, including
  `connect_timeout` — the deadline on an operation is `timeoutMs`.
- No last-insert-id: `exec()->lastInsertId` is always `0`. Use
  `INSERT … RETURNING id` and read it as a result row:
  ```php
  $rows = $connection->fetchAll('INSERT INTO users (name) VALUES ($1) RETURNING id', ['Ann']);
  $id = $rows[0]['id'];
  ```
- `BOOLEAN` is a real type and comes back as a PHP `bool`, not `0/1` (in MySQL it
  is `TINYINT(1)` → `int`). `NUMERIC`/`DECIMAL` is a string, as in MySQL.
- No `interpolateParams`. A parameterised statement is sent as a
  `PREPARE`/`EXECUTE`/`DEALLOCATE` batch in one round trip: your SQL goes into the
  `PREPARE` untouched, the parameters are dollar-quoted literals in the `EXECUTE`,
  and the server infers each parameter's type from where it appears. That is what
  makes results arrive in the text format — see [Value types](#value-types).
- A transaction aborts on error: after a failing query PostgreSQL puts it into the
  aborted state, and further commands fail with `current transaction is aborted`
  until `rollback()` — or until `ROLLBACK TO SAVEPOINT`, which returns the
  transaction to a savepoint taken before the failure and lets it carry on. Nested
  transactions are savepoints here as they are on MySQL, see
  [docs/mysql.md](mysql.md#nested-transactions).

## Limits

A `TEXT` binding cannot carry a NUL byte — PostgreSQL holds none in a text value —
and one is refused by name before the statement runs. Bind such a value to a
`BYTEA` parameter, which takes arbitrary bytes.

## Value types

A value reaches PHP as the text PostgreSQL itself prints for it — the same string
`SELECT column::text` would return, because that is literally where it comes from:
statements run through the simple query protocol, so the server does the printing.
Integers, floating-point columns and `BOOL` arrive as an `int`, `float` and `bool`;
`OID`, `XID` and `CID` as an `int`; `BYTEA` as its raw bytes; `NULL` as `null`.
Everything else is a string.

`DATE`, `TIMESTAMP` and `TIMESTAMPTZ` are the one exception to "what Postgres
prints": they arrive as an RFC3339 timestamp (`2026-12-06T14:30:00Z`, a `DATE` at
midnight UTC), with the fractional second trimmed of trailing zeroes. A
`TIMESTAMPTZ` is rendered at UTC. `infinity` and `-infinity` arrive as those words.

The rest keep their Postgres text form, including the parts of it that are easy to
guess wrong:

| Type | Example value |
|---|---|
| `NUMERIC` | `1.500` — the scale the column declared, not the shortest form |
| `NUMERIC` specials | `NaN`, `Infinity`, `-Infinity` |
| `MONEY` | `$1,234,567.89` (follows the server's `lc_monetary`) |
| `TIME`, `TIMETZ` | `14:30:00.25`, `14:30:00+02` |
| `INTERVAL` | `1 year 2 mons 3 days 04:05:06.789`, `-1 days -02:03:04` |
| arrays | `{1,NULL,3}`, `{{1,2},{3,4}}`, `{"a b","c,d"}`, `[2:4]={7,8,9}` |
| ranges, multiranges | `[1,5)`, `empty`, `(,5)`, `{[1,5),[7,9)}` |
| composite types | `(1,a)`, `(1,"a,b","c""d",,"")` |
| `INET`, `CIDR` | `192.168.0.1`, `192.168.0.1/24`, `10.0.0.0/8` |
| `MACADDR`, `MACADDR8` | `08:00:2b:01:02:03` |
| `BIT`, `VARBIT` | `1010` |
| `TSVECTOR`, `TSQUERY` | `'fox':3 'quick':2`, `( 'a' \| 'b' ) & 'c'` |
| geometric types | `(1,2)`, `[(0,0),(1,1)]`, `<(0,0),1>`, `{1,2,3}` |
| `JSON`, `JSONB`, `XML`, `UUID` | the document, the dashed UUID |
| `OIDVECTOR`, `INT2VECTOR`, `PG_LSN` | `1 2 3`, `0/16B374D` |

There is no list of unsupported types, and no type needs registering: enum,
composite and domain types you define yourself read the same way, and so do the
`reg*` types and `ACLITEM`, which have no usable binary form at all.

Other limits and internals (pool, streaming, cancellation) are shared with
[MySQL](mysql.md).
