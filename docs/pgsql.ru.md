[English](pgsql.md) | Русский

# PostgreSQL (на универсальной SQL-фиче)

PgSQL — второй драйвер той же SQL-фичи поверх Go `database/sql` (драйвер
`jackc/pgx`). Ядро (`SConcur\Features\Sql`) общее с MySQL, а
`SConcur\Features\Pgsql\Connection` — тонкий фасад, задающий драйвер. Стриминг,
пул, транзакции и конкурентность ведут себя одинаково — см.
[docs/mysql.ru.md](mysql.ru.md); здесь описаны только отличия PostgreSQL.

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

## Отличия от MySQL

- Плейсхолдеры нумерованные `$1, $2, …`, а не `?`. Биндинги остаются позиционным
  списком.
- DSN — формат pgx/libpq: `postgres://user:pass@host:port/dbname?sslmode=...`
  (либо keyword/value `host=… port=… user=… dbname=…`). Полезные параметры:
  `sslmode`, `connect_timeout` (в секундах).
- Нет last-insert-id: `exec()->lastInsertId` всегда `0`. Используйте
  `INSERT … RETURNING id` и читайте его как строку результата:
  ```php
  $rows = $connection->fetchAll('INSERT INTO users (name) VALUES ($1) RETURNING id', ['Ann']);
  $id = $rows[0]['id'];
  ```
- `BOOLEAN` — настоящий тип и приходит PHP-шным `bool`, а не `0/1` (в MySQL это
  `TINYINT(1)` → `int`). `NUMERIC`/`DECIMAL` — строка, как и в MySQL.
- Нет `interpolateParams`. Запрос с параметрами уходит пачкой
  `PREPARE`/`EXECUTE`/`DEALLOCATE` за один обмен с сервером: ваш SQL попадает в
  `PREPARE` нетронутым, параметры едут литералами в долларовых кавычках внутри
  `EXECUTE`, а тип каждого параметра сервер выводит по месту его появления. Именно
  поэтому результаты приходят в текстовом формате — см.
  [Типы значений](#типы-значений).
- Транзакция аварийна после ошибки: PostgreSQL переводит её в aborted-состояние, и
  дальнейшие команды падают с `current transaction is aborted` до `rollback()`.

## Ограничения

Биндинг типа `TEXT` не может нести NUL-байт — PostgreSQL не хранит его в текстовом
значении вовсе, — и такой биндинг отклоняется по имени ещё до выполнения запроса.
Передавайте такое значение в параметр `BYTEA`, который принимает произвольные
байты.

## Типы значений

Значение приходит в PHP тем же текстом, который печатает сам PostgreSQL, — той же
строкой, что вернул бы `SELECT column::text`, потому что оттуда оно и берётся:
запросы идут по простому протоколу, и печатает сервер. Целые, дробные и `BOOL`
приходят как `int`, `float` и `bool`; `OID`, `XID` и `CID` — как `int`; `BYTEA` —
сырыми байтами; `NULL` — как `null`. Всё остальное — строка.

`DATE`, `TIMESTAMP` и `TIMESTAMPTZ` — единственное исключение из «как печатает
Postgres»: они приходят timestamp'ом RFC3339 (`2026-12-06T14:30:00Z`, `DATE` — на
полночь UTC), с обрезанными хвостовыми нулями в долях секунды. `TIMESTAMPTZ`
отрисовывается в UTC. `infinity` и `-infinity` приходят этими же словами.

Остальные сохраняют текстовую форму Postgres, включая те её места, где легко
ошибиться:

| Тип | Пример значения |
|---|---|
| `NUMERIC` | `1.500` — с масштабом, объявленным колонкой, а не кратчайший |
| Спецзначения `NUMERIC` | `NaN`, `Infinity`, `-Infinity` |
| `MONEY` | `$1,234,567.89` (зависит от `lc_monetary` сервера) |
| `TIME`, `TIMETZ` | `14:30:00.25`, `14:30:00+02` |
| `INTERVAL` | `1 year 2 mons 3 days 04:05:06.789`, `-1 days -02:03:04` |
| массивы | `{1,NULL,3}`, `{{1,2},{3,4}}`, `{"a b","c,d"}`, `[2:4]={7,8,9}` |
| диапазоны, мультидиапазоны | `[1,5)`, `empty`, `(,5)`, `{[1,5),[7,9)}` |
| составные типы | `(1,a)`, `(1,"a,b","c""d",,"")` |
| `INET`, `CIDR` | `192.168.0.1`, `192.168.0.1/24`, `10.0.0.0/8` |
| `MACADDR`, `MACADDR8` | `08:00:2b:01:02:03` |
| `BIT`, `VARBIT` | `1010` |
| `TSVECTOR`, `TSQUERY` | `'fox':3 'quick':2`, `( 'a' \| 'b' ) & 'c'` |
| геометрические типы | `(1,2)`, `[(0,0),(1,1)]`, `<(0,0),1>`, `{1,2,3}` |
| `JSON`, `JSONB`, `XML`, `UUID` | документ, UUID через дефисы |
| `OIDVECTOR`, `INT2VECTOR`, `PG_LSN` | `1 2 3`, `0/16B374D` |

Списка неподдерживаемых типов нет, и регистрировать ничего не нужно: созданные
вами перечислимые, составные типы и домены читаются так же, как и типы `reg*` и
`ACLITEM`, у которых пригодной бинарной формы нет вовсе.

Прочие ограничения и внутреннее устройство (пул, стриминг, отмена) общие с
[MySQL](mysql.ru.md).
