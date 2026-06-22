# PostgreSQL (поверх универсальной SQL-фичи)

PgSQL — второй драйвер той же SQL-фичи поверх Go `database/sql` (драйвер
`jackc/pgx`). Ядро (`SConcur\Features\Sql`) общее с MySQL; `SConcur\Features\Pgsql\Connection`
— тонкий фасад, задающий драйвер. Поведение (стриминг, пул, транзакции,
конкурентность) идентично — см. [docs/mysql.ru.md](mysql.ru.md). Здесь — только
отличия PostgreSQL.

## Быстрый старт

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

- Плейсхолдеры — `$1, $2, …` (нумерованные), а не `?`. Биндинги — позиционный
  список, передаётся в драйвер как есть.
- DSN — формат pgx/libpq: `postgres://user:pass@host:port/dbname?sslmode=...`
  (или keyword/value `host=… port=… user=… dbname=…`). Полезные параметры:
  `sslmode`, `connect_timeout` (сек).
- Нет last-insert-id: `exec()->lastInsertId` всегда `0`. Чтобы получить id
  вставленной строки, используйте `INSERT … RETURNING id` и читайте его как строку
  результата:
  ```php
  $rows = $connection->fetchAll('INSERT INTO users (name) VALUES ($1) RETURNING id', ['Ann']);
  $id = $rows[0]['id'];
  ```
- `BOOLEAN` — настоящий тип: возвращается как PHP `bool` (`true`/`false`), а не
  как `0/1` (в MySQL это `TINYINT(1)` → `int`).
- `NUMERIC`/`DECIMAL` — строка (точность сохраняется), как и в MySQL.
- Без `interpolateParams` — у pgx нет такого флага; запросы идут расширенным
  протоколом (prepared) по умолчанию.
- Транзакция прерывается при ошибке: после ошибочного запроса внутри
  транзакции PostgreSQL переводит её в aborted-состояние, и дальнейшие команды до
  `rollback()` завершатся ошибкой `current transaction is aborted`. Делайте
  `rollback()` (он разрешён) и начинайте заново.

## Ограничения

- Бинарные данные с NUL-байтами в `BYTEA` через биндинг: значение-строка
  передаётся как текст, и PostgreSQL отвергает невалидный UTF-8 (`0x00`). Для
  произвольных бинарных данных кодируйте их (например, в hex/base64) и
  декодируйте на стороне БД/приложения. ASCII-байты в `BYTEA` работают.
- Прочие ограничения и устройство (пул, стриминг, отмена, типы значений) —
  общие с MySQL, см. [docs/mysql.ru.md](mysql.ru.md).
