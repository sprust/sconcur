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
- Нет `interpolateParams` — у pgx такого флага нет, запросы по умолчанию идут
  через расширенный (prepared) протокол.
- Транзакция аварийна после ошибки: PostgreSQL переводит её в aborted-состояние, и
  дальнейшие команды падают с `current transaction is aborted` до `rollback()`.

## Ограничения

Бинарные данные с NUL-байтами в `BYTEA` через биндинг не работают: строковое
значение передаётся текстом, а PostgreSQL отвергает невалидный UTF-8 (`0x00`).
Кодируйте произвольные бинарные данные (hex, base64) и декодируйте на стороне БД
или приложения; ASCII-байты в `BYTEA` работают. Прочие ограничения и внутреннее
устройство (пул, стриминг, отмена, типы значений) общие с
[MySQL](mysql.ru.md).
