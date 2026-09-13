# PHP API

Файлы: `src/Features/Redis/`, исключения — `src/Exceptions/Redis/`.

```
src/Features/Redis/
    Connection.php              — точка входа, пул адресуется её DSN
    RedisOptions.php            — настройки соединения (DSN + размеры + дедлайн)
    RedisCommandEnum.php        — суб-операции конверта
    Pipeline.php                — накопитель команд для pipeline()/transaction()
    Subscription.php            — живая подписка, итератор сообщений
    Dto/Message.php             — одно сообщение подписки
    Dto/ErrorReply.php          — ошибка одной команды внутри пайплайна
    Results/ScanResult.php      — итератор по курсорной команде
    Payloads/RedisPayload.php   — конверт
    Support/*CommandsTrait.php  — типизированный фасад по группам команд
```

Трейты несут постфикс `*Trait` (правило стиля), свойства `protected`, `final`
нет, DTO — `readonly`.

## Connection

```php
$redis = new SConcur\Features\Redis\Connection(
    dsn: 'redis://sc-redis:6379/0',
    timeoutMs: 5000,
    poolSize: 4,
);
```

DSN: `redis://[user:password@]host:port/db`, `rediss://…` для TLS,
`unix:///var/run/redis.sock`. Из параметров запроса драйвер читает `protocol=2|3`
(и `db`/`user`/`pass` — только на unix-сокете); всё остальное отклоняется
исключением, потому что молча проигнорированный параметр в DSN означает, что
подключение работает не так, как написано в конфиге. Размер пула и дедлайн — не
параметры DSN, а аргументы конструктора.

`timeoutMs` — дедлайн одной операции, обязателен; по умолчанию 30000, как в
`Sql\Connection`.

## Сырой путь

```php
$reply = $redis->command('SET', ['user:1', $json, 'EX', 3600]);
$reply = $redis->command('GET', ['user:1']);         // ?string
```

`command()` — не «низкоуровневый запасной выход», а основной способ вызвать
команду, у которой нет типизированного метода. Ответ отдаётся в форме, в которой
его прислал сервер.

## Типизированный фасад

Метод заводится там, где он делает что-то сверх передачи аргументов: формирует
ответ, скрывает опасный порядок аргументов или собирает опции. Группы v1:

- строки и счётчики: `get`, `set` (с `ttlSeconds`, `keepTtl`, `ifNotExists`,
  `ifExists`), `mGet`, `mSet`, `incrBy`, `incrByFloat`, `append`, `getSet`,
  `getDel`, `setNx`
- ключи и время жизни: `del`, `unlink`, `exists`, `expire`, `expireAt`, `ttl`,
  `persist`, `rename`, `type`, `randomKey`
- хеши: `hGet`, `hSet`, `hMGet`, `hGetAll` (ассоциативный массив), `hDel`,
  `hExists`, `hIncrBy`, `hLen`, `hKeys`, `hVals`
- списки: `lPush`, `rPush`, `lPop`, `rPop`, `lRange`, `lLen`, `lRem`, `lTrim`
- множества: `sAdd`, `sRem`, `sMembers`, `sIsMember`, `sCard`, `sPop`
- упорядоченные множества: `zAdd`, `zRange` (с `withScores` → ассоциативный
  массив), `zRangeByScore`, `zRem`, `zScore`, `zCard`, `zIncrBy`
- скрипты: `eval`, `evalSha`, `scriptLoad`
- служебное: `ping`, `flushDb` (только с явным аргументом, чтобы её нельзя было
  вызвать «случайно»), `dbSize`, `info`

Каждая группа — трейт в `Support/`, подмешанный в `Connection`. Это держит
`Connection` читаемым: иначе один файл на полсотни методов.

Обёртка над `command()` не прячет её: `hGetAll` собирает ассоциативный массив из
плоского RESP2-ответа, `zRange(withScores: true)` — тоже, и оба в доке помечены
как места, где форма ответа сформирована PHP-стороной, а не сервером.

## Пайплайн и транзакция

```php
$pipeline = $redis->pipeline();
$pipeline->command('SET', ['a', '1']);
$pipeline->incrBy('a', 5);

$replies = $pipeline->execute();     // список ответов по числу команд
```

`execute()` внутри замыкания `transaction()` отклоняется
(`NestedPipelineExecutionException`): те команды ушли бы сами по себе, вне
`MULTI`/`EXEC`, и их ответы были бы выброшены. Пайплайн опустошается только
после того, как батч отправлен, — иначе повтор упавшей отправки жалуется на
пустой пайплайн вместо того, чтобы повторить те же команды.

В пайплайне команды пишутся сырым `command()`, и типизированного фасада у него
нет намеренно: методы фасада формируют ответ той команды, которую называют
(`hGetAll` собирает ассоциативный массив), а пайплайн отдаёт плоский список
ответов в порядке добавления. Прицепить формирование к элементу списка можно
только по позиции, и это ровно то место, где ошибка не видна. Один `execute()` —
одно пересечение границы и один round trip к серверу.

```php
$replies = $redis->transaction(static function (Pipeline $transaction): void {
    $transaction->incrBy('counter', 1);
    $transaction->command('LPUSH', ['events', 'inc']);
});
```

`transaction()` — тот же пайплайн с `at=true`: `MULTI`/`EXEC` на сервере.
Замыкание не может ничего прочитать по ходу — ответы приходят все сразу, после
`EXEC`; оптимистическая блокировка `WATCH` требует закреплённого соединения и
переносится в v2 (см. [README.md](README.md#отложено)).

Ошибку одной команды пайплайна `execute()` отдаёт объектом `Dto\ErrorReply` на
её месте, а не исключением: сервер выполнил остальные, и терять их результаты
из-за одной опечатки неправильно. Одиночная `command()` бросает исключение, как
и все прочие фичи.

## Блокирующие команды

```php
$item = $redis->blPop(['queue:jobs'], timeoutSeconds: 5);      // ?array{0:string,1:string}
```

Такая команда уходит на отдельное соединение и не задерживает соседей. Дедлайн
задачи фасад поднимает сам — `timeoutSeconds * 1000` плюс запас, — а расширение
отклоняет payload, у которого дедлайн меньше блокирующего аргумента: иначе
операция обрывается по дедлайну раньше, чем сервер успел ответить, и выглядит
это как необъяснимый таймаут.

`timeoutSeconds: 0` (ждать вечно) допустим только при `timeoutMs: 0` — то есть
когда вызывающий явно отказался от дедлайна. Отменяется такая команда обычным
образом: остановкой флоу.

## Курсоры

```php
foreach ($redis->scan(match: 'user:*', count: 500) as $key) {
    // ...
}

foreach ($redis->hScan(key: 'user:1', match: 'attr:*') as $field => $value) {
    // ...
}
```

`Results\ScanResult` повторяет `Sql\Results\RowsResult`: батчи тянутся по
требованию, брошенный на полпути итератор освобождает state (флоу закрывается,
хук реестра вызывает `close()`).

Оговорка из доки: `SCAN` не даёт снимка. Ключ, живший всё время обхода, будет
отдан хотя бы раз; появившийся или удалённый по ходу — как повезёт; дубликаты
возможны. Это свойство Redis, а не фичи.

## Подписка

Шардированных каналов в v1 нет — см. [protocol.md](protocol.md#sub--sup--suc--подписка).

```php
$subscription = $redis->subscribe(channels: ['news'], patterns: ['user:*']);

foreach ($subscription as $message) {
    echo $message->channel, ' ', $message->payload, PHP_EOL;

    if ($needMore) {
        $subscription->add(channels: ['sport']);
    }
}

$subscription->close();
```

`Subscription` — итератор, тянущий батчи через `next()`; `add()`/`remove()` идут
отдельными задачами и попадают на то же соединение по `sid`; `close()`
освобождает его. Брошенная подписка закрывается по остановке флоу, как курсор.

Подписка занимает соединение целиком (таково свойство протокола), поэтому она не
берётся из общего пула: каждая `subscribe()` — это свой сокет. В доке это надо
сказать прямо: тысяча подписок — тысяча соединений, и группировать каналы в одну
подписку дешевле, чем заводить подписку на канал.

## Исключения

`src/Exceptions/Redis/`, по правилам проекта: имя по случаю, `RuntimeException`
для сбоев исполнения, `LogicException` для ошибок использования, никаких
`@throws`, исходное исключение — в `previous`.

| Класс | База | Когда |
| --- | --- | --- |
| `RedisException` | `RuntimeException` | общий предок фичи |
| `RedisCommandException` | `RedisException` | ответ-ошибка сервера; поле `code` (`WRONGTYPE`, `NOSCRIPT`, …) |
| `RedisConnectionException` | `RedisException` | дозвон, аутентификация, обрыв в полёте |
| `RedisTimeoutException` | `RedisException` | сработал дедлайн задачи |
| `SubscriptionClosedException` | `RedisException` | работа с закрытой подпиской |
| `UnsupportedRedisCommandException` | `LogicException` | команда, меняющая состояние соединения |
| `InvalidRedisArgumentException` | `LogicException` | аргумент не `string\|int\|float` |
| `InvalidRedisDsnException` | `LogicException` | неразбираемый DSN или неизвестный параметр |

Текст `UnsupportedRedisCommandException` называет замену: `SUBSCRIBE` →
`subscribe()`, `MULTI` → `transaction()`, `SELECT` → база в DSN, `AUTH` →
логин и пароль в DSN.
