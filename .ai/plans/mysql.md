# MySQL (универсальная SQL-фича) — план

Асинхронная фича работы с реляционной БД поверх Go `database/sql`. Запросы и
команды уходят в Go-расширение и исполняются в горутинах, пока корутина
приостановлена, — десятки запросов летят «веером». Вне `WaitGroup` тот же API
работает синхронно. Первый драйвер — **MySQL**; код спроектирован так, чтобы
**PgSQL** добавлялся минимальным набором изменений.

Эталоны для копирования: `Mongodb` (мульти-командный конверт, пулинг соединений,
стриминг-курсоры) и `HttpClient` (паттерн «сессии» upload — держащая задача с
`HasNext`, отмена по контексту).

---

## Цели и охват

**v1 (этот план):**

- **Запросы с биндингами** — `select * from users where id = ?` + позиционный
  массив `bindings`. Плейсхолдеры **нативные для драйвера** (`?` у MySQL, `$1` у
  PgSQL позже); биндинги передаются в `database/sql` без переписывания.
- **SELECT двумя способами** — стриминг-курсор `query()` (батчи строк, как
  Mongodb `find`/`aggregate`) и удобный `fetchAll()` (буфер целиком).
- **Изменяющие запросы** — `exec()` для INSERT/UPDATE/DELETE/DDL, возвращает
  `affectedRows` и `lastInsertId`.
- **Транзакции** — объект `Transaction`: `begin()` → `query()/fetchAll()/exec()`
  → `commit()`/`rollback()`. Соединение закреплено за серией задач; обрыв флоу
  откатывает транзакцию автоматически.
- **Пул соединений** на стороне Go (`*sql.DB`), переиспользуемый между задачами,
  с idle-вытеснением — как пул клиентов Mongodb.

**Фаза 2 (заметки на будущее, не в v1):**

- Переиспользуемые prepared statements, именованные параметры.
- Множественные result set'ы, batch-exec, savepoint'ы (вложенные транзакции).
- Проброс нативного номера/состояния ошибки драйвера (MySQL errno / SQLSTATE).

---

## Универсальность: отдельные домены, общий код

Принято решение (см. развилки в конце): **отдельный `Method` на драйвер**, оба
маршрутизируются в **один общий Go-пакет** `ext/internal/features/sql`. Драйвер
определяется по `Method`.

- PHP: `MethodEnum::Mysql = 6` (позже `Pgsql = 7`).
- Go: `types.MethodMysql = 6` (позже `MethodPgsql = 7`); `factory.go` роутит оба в
  общий пакет, но в разные синглтоны с заданным `driverName`:
  ```go
  case types.MethodMysql:
      return sql_feature.GetMysql(), nil // driverName: "mysql"
  // позже:
  // case types.MethodPgsql:
  //     return sql_feature.GetPgsql(), nil // driverName: "pgx"
  ```
- PHP-ядро в `src/Features/Sql/` (payloads, базовое соединение, транзакция,
  результаты, сериализация) общее. Фасад `src/Features/Mysql/` тонкий: задаёт
  `MethodEnum::Mysql` и дефолты драйвера. PgSQL = зеркальный фасад + регистрация
  Go-драйвера.

**Почему `database/sql`.** Он абстрагирует драйвер: вся логика (запрос, exec,
транзакция, скан строк, пул) пишется один раз, а драйвер подключается импортом
для side-effect-регистрации (`_ "github.com/go-sql-driver/mysql"`,
позже `_ "github.com/jackc/pgx/v5/stdlib"`). Различия драйверов — только DSN и
диалект плейсхолдеров — естественны для SQL и остаются на стороне пользователя.

---

## Протокол PHP ↔ Go (payloads)

Конверт команды (мульти-командная фича, эталон — `Mongodb`):

```
{ cm: <SqlCommand>, dsn: <string>, to: <timeoutMs>, dt: <данные команды> }
```

`dsn`+`to`(+опции пула) — на уровне конверта (как `Connection` у Mongodb).
`dt` — по структуре на команду, зеркалит PHP `*Payload` 1:1 (`msgpack`-теги).

**`SqlCommand` (PHP `Sql\SqlCommandEnum` ↔ Go `types.SqlCommand`):**

| Команда   | Значение | `dt`                                   | Результат |
|-----------|----------|----------------------------------------|-----------|
| `Query`   | 1        | `q`(sql), `b`(bindings), `tx`(txId?), `bs`(batchSize) | стриминг-батчи строк (`HasNext`) |
| `Exec`    | 2        | `q`, `b`, `tx`(txId?)                   | `{ar:affectedRows, li:lastInsertId}` |
| `Begin`   | 3        | `iso`(isolation?), `ro`(readOnly?)      | пусто, `HasNext=true`, `key`=txId |
| `Commit`  | 4        | `tx`(txId)                              | пусто |
| `Rollback`| 5        | `tx`(txId)                              | пусто |

`Query`/`Exec` с пустым `tx` → автокоммит на соединении из пула; с заданным `tx`
→ выполняются на закреплённой транзакции.

Каждый payload несёт предельное время выполнения (`to`) — требование #2 (см.
`docs/adding-a-feature.ru.md`). Connect-таймаут задаётся параметрами DSN/пула.

---

## Транзакции — ядро дизайна

Транзакция = одно соединение, живущее через серию отдельных задач. Реализуется
паттерном «сессии» из `httpclient/upload.go`, опираясь на два факта:

1. **`flow.go:107`** — задача с `HasNext=true` (не `next`) сохраняет свой
   контекст живым до финального `next()` либо до остановки флоу.
2. **`database/sql`** — `db.BeginTx(ctx)`: при отмене `ctx` транзакция
   **автоматически откатывается**.

**Поток:**

```
$tx = $connection->begin();                 // exec(Begin)
  Go: pool.Acquire(dsn) → tx = db.BeginTx(beginTaskCtx, opts)
      pendingTransactions[txId] = {tx, pool}   (txId = ключ задачи begin)
      states.Register(txId, txReleaseState)     (как upload: без авточтения)
      context.AfterFunc(beginTaskCtx, cleanup)  (release пула; rollback — авто от database/sql)
      return SuccessWithNext("", key=txId)       // HasNext=true → контекст begin жив
  PHP: Transaction хранит txId = result->key

$tx->exec('update ... where id = ?', [$id]); // exec(Exec, tx=txId)
  Go: pendingTransactions[txId].tx.ExecContext(queryTaskCtx, q, b...)

$tx->query('select ...', [$id]);             // exec(Query, tx=txId) → свой курсор-состояние

$tx->commit();                               // exec(Commit, tx=txId) → tx.Commit(); delete map
  затем PHP: FeatureExecutor::next(txId)     // освобождает txReleaseState → Close → pool.Release
                                             //   → отмена beginTaskCtx (commit уже прошёл → no-op)
```

- `rollback()` симметричен: `exec(Rollback)` → `tx.Rollback()`; затем `next(txId)`.
- **Обрыв** (исключение/early break/`WaitGroup::stop()`/`destroy`): флоу
  останавливается → `beginTaskCtx` отменяется → `database/sql` авто-rollback,
  `AfterFunc`/`Close` освобождает счётчик пула. Висящих задач не остаётся (тест
  `tearDown` в `BaseTestCase` это проверяет). На синхронном пути держащий флоу
  begin освобождает `Transaction::__destruct` → `State::releaseSyncTaskFlow`.
- **Сериализация доступа.** `*sql.Tx` нельзя использовать конкурентно, но PHP в
  пределах одной корутины ждёт каждую команду перед следующей — доступ
  естественно последователен. `txReleaseState` всё равно защищаем мьютексом
  (`Next`/`Close` могут гонять с отменой контекста — как в Mongodb-состояниях).
- **Конкурентные транзакции.** N корутин в одном `WaitGroup` держат каждая свою
  транзакцию (свой txId, своё соединение из пула) — фан-аут сохраняется.

---

## Стриминг SELECT и скан строк

- `query()` возвращает `Sql\Results\RowsResult` (Iterator) — аналог
  `Mongodb\Results\IteratorResult`, но декодирует батчи через
  `MessagePackTransport` (без BSON-сериализатора). Тянет следующий батч через
  `FeatureExecutor::next` по `taskKey`.
- Go-состояние `sql/states/rows_state.go` держит `*sql.Rows`, в `Next()` читает
  `batchSize` строк, сканирует каждую в `map[string]any` (имя колонки → значение),
  отдаёт батч; на последнем батче `HasNext=false` → `Close()` (rows.Close +
  pool.Release на свежем контексте). Автокоммит-курсор удерживает счётчик пула
  до `Close`, как Mongodb-курсор.
- **Скан значений.** `database/sql` отдаёт многие типы как `[]byte`. Нужен
  слой `sql/serializer`: по `rows.ColumnTypes()` приводить `[]byte` к
  string/int/float/bool/null. Это «DocumentSerializer для SQL»; покрыть
  Go-тестами (числа, NULL, даты как строки, бинарные данные).
- `fetchAll()` — обёртка: прогоняет `RowsResult` в массив (`iterator_to_array`).
  `exec()` возвращает `Sql\Results\ExecResult { affectedRows, lastInsertId }`.

---

## Пул соединений (Go)

Зеркало `ext/internal/features/mongodb/connection/clients.go`:

- `sql/connection/pools.go` — реестр пулов, ключ `driver + dsn + poolOpts`,
  значение — `Pool{ *sql.DB, inUse, lastUsedAt }`. `Acquire`/`Release`
  (refcount), idle-sweeper, `DisconnectAll()` на shutdown.
- `*sql.DB` сам по себе пул и потокобезопасен — горутины делят его; автокоммит
  query/exec берут соединение автоматически. Настройки: `SetMaxOpenConns`,
  `SetMaxIdleConns`, `SetConnMaxLifetime` из опций соединения.
- `factory.go` `Shutdown()` дополнить вызовом `sql_feature ... .CloseAllPools()`.

---

## Структура файлов

**PHP (`src/`):**

```
Features/MethodEnum.php                         + Mysql = 6
Features/Sql/
  SqlCommandEnum.php                            Query/Exec/Begin/Commit/Rollback
  Connection.php                                базовое соединение: query/fetchAll/exec/begin
  Transaction.php                               query/fetchAll/exec/commit/rollback (+ __destruct)
  Dto/Connection.php                            dsn, timeoutMs, pool-опции
  Results/RowsResult.php                        Iterator по батчам строк
  Results/ExecResult.php                        affectedRows, lastInsertId
  Serialization/RowDecoder.php                  (при необходимости нормализации значений)
  Payloads/Base/BaseSqlPayload.php              конверт {cm, dsn, to, dt}; getMethod абстрактен
  Payloads/QueryPayload.php  (+ Parameters)
  Payloads/ExecPayload.php   (+ Parameters)
  Payloads/BeginPayload.php
  Payloads/CommitPayload.php
  Payloads/RollbackPayload.php
Features/Mysql/
  Connection.php                                extends Sql\Connection; getMethod()=Mysql; дефолты MySQL
Exceptions/Sql/
  SqlException.php (RuntimeException), QueryException, TransactionException, ConnectionException
```

Payload несёт `MethodEnum` (передаётся из соединения) — так общие классы
отдают нужный домен для Mysql/Pgsql.

**Go (`ext/`):**

```
internal/types/method.go                        + MethodMysql = 6
internal/types/sql.go                           SqlCommand (Query/Exec/Begin/Commit/Rollback)
internal/features/sql/
  feature.go                                    SqlFeature{driverName}; GetMysql(); Handle → диспетч по cm
  drivers_mysql.go                              import _ go-sql-driver/mysql; GetMysql()
  transactions.go                               pendingTransactions sync.Map; begin/commit/rollback
  connection/pools.go                           реестр пулов (Acquire/Release/sweep/DisconnectAll)
  connection/execute.go                         query/exec хелперы, скан значений
  states/rows_state.go                          стриминг *sql.Rows (Next/Close)
  serializer/values.go (+ _test.go)             приведение []byte по типам колонок
  payloads/payloads.go                          Envelope + параметры команд (зеркало PHP 1:1)
internal/features/factory.go                    case MethodMysql → sql_feature.GetMysql(); Shutdown += CloseAllPools
```

`go.mod`: `+ github.com/go-sql-driver/mysql` (позже `github.com/jackc/pgx/v5/stdlib`).

---

## Два обязательных требования (Go)

1. **Отмена контекста.** Все `QueryContext`/`ExecContext`/`BeginTx` — на контексте
   задачи. `rows_state.Close()` и release пула — на **свежем** контексте
   (`context.Background()` + таймаут), т.к. контекст задачи к моменту очистки
   отменён. Транзакция откатывается автоматически при отмене `beginTaskCtx`.
2. **Предельное время выполнения.** Каждый payload несёт `to`; Go ограничивает
   операцию `ctx, cancel := context.WithTimeout(task.GetContext(), to)`.
   Connect-таймаут — через параметры DSN/пула.

---

## Публичный API (эскиз)

```php
$connection = new \SConcur\Features\Mysql\Connection(
    dsn: 'user:pass@tcp(127.0.0.1:3306)/app',
    timeoutMs: 5000,
);

// стриминг
foreach ($connection->query('select * from users where age > ?', [18]) as $row) {
    echo $row['name'] . PHP_EOL;
}

// буфер целиком
$rows = $connection->fetchAll('select * from users where id = ?', [$id]);

// изменение
$result = $connection->exec('insert into users(name) values(?)', ['Ann']);
echo $result->lastInsertId;

// транзакция
$tx = $connection->begin();

try {
    $tx->exec('update accounts set balance = balance - ? where id = ?', [100, $from]);
    $tx->exec('update accounts set balance = balance + ? where id = ?', [100, $to]);
    $tx->commit();
} catch (\Throwable $exception) {
    $tx->rollback();

    throw $exception;
}
```

Внутри `WaitGroup::add(...)` те же вызовы исполняются конкурентно.

---

## Тесты

- **`tests/feature/Features/Mysql/MysqlTest.php`** от `BaseAsyncTestCase` — два
  конкурентных запроса, порядок событий, конкурентность (общее время ≈ самого
  медленного запроса), путь с исключением (sync + async).
- **Краевые от `BaseTestCase`:** биндинги; `exec` (affectedRows/lastInsertId);
  стриминг большой выборки (несколько батчей + early break); транзакция commit;
  транзакция rollback; **rollback при обрыве** (исключение/`break` в середине
  транзакции → откат, нет висящих задач); конкурентные транзакции; ошибка SQL →
  `QueryException`.
- **Go-тесты (`make ext-test`):** реестр пулов (Acquire/Release/sweep),
  `rows_state` (батчи, Close), `transactions` (map, авто-rollback по отмене
  контекста), `serializer/values` (типы колонок).
- **Инфраструктура:** добавить сервис `mysql` в `docker-compose.yml` (по образцу
  `sc-mongodb`) + резолвер/бутстрап в `tests/impl/` (по образцу Mongodb-резолвера).

---

## Производительность (фазы оптимизации)

Цель — приблизить **синхронный** путь к нативному PDO в бенчмарках (на localhost
БД отвечает за доли миллисекунды, поэтому виден оверхед обвязки, а не I/O).

### Фаза 1 — сделано (коммит `perf(mysql): default interpolateParams …`)

- `interpolateParams=true` по умолчанию в `Mysql\Connection` (один COM_QUERY вместо
  PREPARE+EXECUTE+CLOSE; отключается `interpolateParams=false`).
- Микро-опты Go: comparable struct-ключ пула (без `fmt.Sprintf` на acquire);
  освобождение дедлайна курсора в `Close()` без watcher-горутины.
- Замер (лучшее из 3, 1000 вызовов): **sync exec −53%**, **sync select −77%**.

### Фаза 2 — кеш prepared-выражений (в рамках фичи)

> **Статус: под вопросом, маловероятно к реализации.** Зафиксировано как идея;
> по умолчанию ставка на Фазу 1 (`interpolateParams`). Браться только при явной
> необходимости бинарного prepared-пути.

Идея: держать кеш `*sql.Stmt` (bounded LRU, ключ `пул + SQL`) для autocommit
query/exec. Повторный одинаковый SQL переиспользует подготовленный хендл →
бинарный EXECUTE без повторного парсинга на сервере. Ориентир — нативный PDO
«prepare-once» (в замере ~0.019s/300 против ~0.068s у emulated): бинарный execute
заметно дешевле полного COM_QUERY.

Состав:
- LRU-кеш `*sql.Stmt` на каждый `*sql.DB` (в структуре `pool`), ключ — текст SQL;
  ограничение размера + закрытие вытесненных и при `closeAll`.
- `query`/`exec` без транзакции: `stmt := cache.getOrPrepare(ctx, sql)` →
  `stmt.QueryContext/ExecContext(args)`.
- Транзакции пока готовят как есть (`tx.Stmt`/prepare на tx — отдельно, реже горячо).
- Потокобезопасность: `*sql.Stmt` потокобезопасен; кеш под `sync.Mutex`/`RWMutex`.

Нюансы и риски:
- **Несовместимо с `interpolateParams=true`** — там нет серверного prepare. То есть
  Фаза 2 — это *альтернатива* Фазе 1 для тех, кому нужен бинарный протокол/типизация
  без клиентской интерполяции; делать как опциональный режим, не вместе.
- Рост числа prepared-хендлов на соединение (лимит `max_prepared_stmt_count`) —
  обязательны bound + вытеснение.
- Выигрыш всё равно упрётся в «налог» обвязки из Фазы 3 (≈0.08–0.10s/300).

Решение: делать только если после Фаз 1/3 синхронная типизированная скорость всё
ещё критична. По умолчанию ставка на Фазу 1 (`interpolateParams`).

### Фаза 3 — налог обвязки и протокол обмена (ядро)

Это уже не про MySQL, а про общий мост PHP↔Go (касается всех фич). Вынесено в
отдельный план: [`php-go-bridge-performance.md`](php-go-bridge-performance.md).
Там идея A (framing результата) **реализована**, остальное — под вопросом.

---

## Версия расширения

Протокол меняется (новый `Method` + команды) → бамп **0.2.1 → 0.2.2** (patch) в
`ext/main.go` (`version()`) и `src/Connection/Extension.php`
(`REQUIRED_EXTENSION_VERSION`), один раз на ветку.

---

## Проверка

`make ext-build && make ext-test && make php-stan && make cs-fixer-check && make test`

---

## Зафиксированные развилки

- **Универсальность:** отдельные `Method` на драйвер (`Mysql`, позже `Pgsql`),
  общий Go-пакет `sql`, драйвер по `Method`.
- **API транзакций:** объект `Transaction` (`begin`/`commit`/`rollback`), без
  closure-хелпера.
- **Результаты SELECT:** стриминг-курсор `query()` + буферный `fetchAll()`.
