# Фича RabbitMQ (AMQP 0-9-1): полная калька `ext-amqp`

Статус: **реализовано** (ветка `feature/amqp-rabbitmq`, версия расширения
`0.11.0`). Документация — [docs/amqp.ru.md](../../docs/amqp.ru.md); дальше по
файлу — исходный план, он оставлен как есть.

Чем реализация отличается от плана:

- **Стрим доставок — на консьюмера, а не на соединение.** План (§10) исходил из
  того, что `csm` отдаёт доставки своего консьюмера, и так и сделано; но у
  `ext-amqp` цикл потребления забирает доставки всего соединения. Повторять это
  нельзя: в SConcur циклов может крутиться несколько сразу, и доставки чужой
  очереди уходили бы в чужой колбэк. Отклонение внесено в список §7 доки.
- **`AMQP_JUST_CONSUME` целиком на стороне PHP** (поля `jc` в payload нет): это
  «читать уже открытого консьюмера», а ключ его задачи хранит `AMQPQueue`. Если
  открытого консьюмера нет — `AMQPQueueException`.
- **Добавлена команда `usc`** (§8 её не предусматривал): `getUsedChannels()`
  считается по реестру каналов на стороне Go, как того требует §7.
- **`cho` несёт prefetch-настройки**: `ext-amqp` после открытия канала шлёт
  отдельный `basic.qos`, здесь они едут вместе с открытием — одно пересечение
  границы вместо двух.
- **`getChannelId()`** возвращает номер канала внутри соединения, присвоенный
  фичей: `amqp091-go` настоящий номер AMQP-канала наружу не отдаёт.
- **`getClusterId()`** всегда `null`: поля нет ни в `Publishing`, ни в
  `Delivery` драйвера (§7 ожидал, что у доставки оно есть).
- **Порт RabbitMQ в `.env.example` — 25673**, а не 25672 (§14): 25672 на машине
  разработчика занят другим проектом.
- **`AMQPConnection::__destruct`** добавлен рядом с `AMQPChannel::__destruct`
  (§7 упоминал только канал): иначе потерянный объект соединения навсегда
  удерживал бы соединение в пуле.

Решения владельца, зафиксированные:

- делаем **полную кальку `ext-amqp`** (PECL `amqp`) — те же классы, те же методы,
  те же флаги, те же константы. Переход приложения с `ext-amqp` на SConcur должен
  сводиться к правке `use`-строк, как это уже сделано для MongoDB
  (`SConcur\Bson\*` вместо `MongoDB\BSON\*`);
- домен называется **`Amqp`** (`MethodEnum::Amqp`, `ext/internal/features/amqp/`,
  `docs/amqp.md`) — по протоколу, а не по брокеру, как и само `ext-amqp`;
- пространство имён — **`SConcur\Features\Amqp`**: и калька, и внутренности
  фичи живут под ним, отдельного короткого пространства имён под калькой не
  заводим;
- исключения `AMQP*Exception` живут в том же пространстве имён фичи, а не в
  `SConcur\Exceptions\` (§12);
- `ext-amqp` идёт в `require-dev` точной версией, `librabbitmq-dev` — в образ,
  ради теста соответствия (§14, §15);
- `pconnect()` — синоним `connect()` (§7);
- пункт roadmap `Queue` в README не трогаем (§1).

## 1. Что это и зачем

Асинхронный AMQP-клиент: соединение, каналы, топология, публикация и потребление
живут в Go-расширении, PHP остаётся тонким оркестратором. Внутри `WaitGroup`
десятки публикаций и потребителей идут одновременно; вне Fiber тот же API работает
синхронно — как у всех фич SConcur.

Главный выигрыш — консьюмер. У `ext-amqp` `AMQPQueue::consume()` держит
PHP-поток: воркер занят одной очередью и ничего больше делать не может. В SConcur
тот же вызов кооперативно приостанавливает **только свою корутину**: один процесс
тянет несколько очередей и одновременно обслуживает HTTP-запросы.

### Отношение к пункту roadmap `Queue`

В README есть пункт «Фича `Queue` — отложенные фоновые задачи». Это **не** то же
самое. Данная фича — транспорт (клиент AMQP); `Queue` — прикладная абстракция над
задачами, которая позже может лечь сверху и использовать этот клиент как один из
бэкендов.

Пункт roadmap при этом **не трогаем**: он описывает не эту работу и остаётся в
силе как есть. В README появится только ссылка на доку новой фичи, среди
остальных фич.

## 2. Что означает «полная калька»

`ext-amqp` объявляет классы в глобальном пространстве имён (`AMQPConnection`,
`AMQPQueue`, …) и константы `AMQP_*`. Занять эти имена SConcur не может — они
столкнутся с самим расширением, если оно установлено. Поэтому калька живёт в
`SConcur\Features\Amqp\` и повторяет имена один в один:

```php
// было
use AMQPConnection;
use AMQPChannel;
use AMQPQueue;

// стало
use SConcur\Features\Amqp\AMQPConnection;
use SConcur\Features\Amqp\AMQPChannel;
use SConcur\Features\Amqp\AMQPQueue;
```

Тот же приём, что у `SConcur\Bson\*` (см.
[docs/mongodb.ru.md](../../docs/mongodb.ru.md)): имена классов не тронуты, весь
остальной код приложения остаётся как был.

Отличие от `Bson` — в длине пространства имён. `SConcur\Bson\` короткое
намеренно: имя класса едет по проводу с каждым значением BSON (см.
[docs/msgpack-objects.ru.md](../../docs/msgpack-objects.ru.md)). У AMQP этого
ограничения нет — `AMQPEnvelope` собирается в PHP из обычной карты msgpack, имя
класса границу не пересекает, — поэтому калька живёт прямо в пространстве имён
фичи и отдельной сущности верхнего уровня не заводится.

Константы объявляются в том же пространстве имён
(`src/Features/Amqp/constants.php`, подключается через `autoload.files` в
`composer.json`) и импортируются `use const`:

```php
use const SConcur\Features\Amqp\AMQP_DURABLE;
use const SConcur\Features\Amqp\AMQP_AUTOACK;
```

### Цена решения и её границы

Флаговый int-API (`setFlags(AMQP_DURABLE | AMQP_AUTODELETE)`) противоречит стилю
проекта: типизированные параметры, именованные аргументы, никаких битовых масок.
Это принято сознательно и **ограничено публичными классами кальки** — там
приоритет у совместимости. Всё, что ниже (payload-классы фичи, Go-код), пишется по
правилам проекта: типизировано, именованные аргументы, без магии. Граница между
двумя стилями проходит по каталогу: калька лежит в корне `src/Features/Amqp/`,
внутренности — в подкаталогах (§13).

Приятная деталь: флаги `ext-amqp` — это буквально булевы параметры методов
протокола AMQP 0-9-1, а `amqp091-go` принимает их теми же булевыми параметрами.
Калька ложится на драйвер один в один, без «переводчика» посередине (см. §5).

## 3. Разбор `ext-amqp`: где живёт состояние

Существенно для дизайна: не каждый вызов `ext-amqp` идёт на брокер.

| Класс | Что держит | Что летит на брокер |
|---|---|---|
| `AMQPConnection` | учётные данные, TLS-пути, таймауты, сокет | `connect()`/`pconnect()`, `disconnect()`, `reconnect()` |
| `AMQPChannel` | номер канала, prefetch, режим транзакций/подтверждений | конструктор (открывает канал), `qos()`, `startTransaction()`, `confirmSelect()`, `close()`, `basicRecover()` |
| `AMQPExchange` | имя, тип, флаги, аргументы — **локально** | `declareExchange()`, `delete()`, `bind()`, `unbind()`, `publish()` |
| `AMQPQueue` | имя, флаги, аргументы, тег консьюмера — **локально** | `declareQueue()`, `delete()`, `bind()`, `unbind()`, `purge()`, `get()`, `consume()`, `ack()`/`nack()`/`reject()`, `cancel()` |
| `AMQPEnvelope` | тело и свойства доставленного сообщения | ничего, это объект-значение |

Отсюда следствие для кальки: `AMQPExchange` и `AMQPQueue` — **чисто PHP-объекты**,
границу не пересекают до момента действия. Сеттеры (`setName`, `setType`,
`setFlags`, `setArgument`) не стоят ничего. Пересечений границы ровно столько,
сколько вызовов, реально идущих на брокер.

`AMQPConnection` и `AMQPChannel` держат идентификатор ресурса, живущего на стороне
Go (`connectionId`, `channelId`), — как `Connection::$id` у сокет- и
WS-клиентов.

### Как ведёт себя `consume()`

`AMQPQueue::consume(callable $callback, int $flags, ?string $consumerTag)`
вызывает колбэк на каждое сообщение и **не возвращает управление, пока колбэк не
вернёт `false`**. Колбэк принимает `AMQPEnvelope` и, опционально, сам
`AMQPQueue`. Флаги: `AMQP_AUTOACK`, `AMQP_JUST_CONSUME`.

Это и есть точка, ради которой всё затевается: в кальке цикл ожидания сообщения
становится кооперативной приостановкой корутины, семантика для вызывающего кода не
меняется ни в чём.

`AMQPQueue::get(int $flags)` — противоположность: одно сообщение или `null`, если
очередь пуста, немедленно.

## 4. Разбор `amqp091-go`

Драйвер — `github.com/rabbitmq/amqp091-go` (официальный форк `streadway/amqp`,
сопровождается командой RabbitMQ).

- `Dial(url)` / `DialConfig(url, Config)` / `DialTLS(url, *tls.Config)` →
  `*Connection`;
- `Connection.Channel()` → `*Channel`, `Connection.Close()`,
  `Connection.NotifyClose(chan *Error)`;
- топология: `QueueDeclare`, `QueueDeclarePassive`, `QueueBind`, `QueueUnbind`,
  `QueueDelete`, `QueuePurge`, `ExchangeDeclare`, `ExchangeDeclarePassive`,
  `ExchangeDelete`, `ExchangeBind`, `ExchangeUnbind`;
- публикация: `PublishWithContext(ctx, exchange, key, mandatory, immediate, Publishing)`;
- потребление: `ConsumeWithContext(ctx, queue, consumer, autoAck, exclusive, noLocal, noWait, args)`
  → `<-chan Delivery`; `Get(queue, autoAck)` → `(Delivery, bool, error)`;
- подтверждения доставки: `Ack(tag, multiple)`, `Nack(tag, multiple, requeue)`,
  `Reject(tag, requeue)`, `Cancel(consumer, noWait)`;
- `Qos(prefetchCount, prefetchSize, global)`;
- транзакции: `Tx()`, `TxCommit()`, `TxRollback()`;
- подтверждения публикации: `Confirm(noWait)` + `NotifyPublish(chan Confirmation)`;
  возвраты: `NotifyReturn(chan Return)`.

`Delivery` несёт всё, что нужно `AMQPEnvelope`: `Body`, `RoutingKey`, `Exchange`,
`DeliveryTag`, `Redelivered`, `ConsumerTag`, `Headers`, `ContentType`,
`ContentEncoding`, `DeliveryMode`, `Priority`, `CorrelationId`, `ReplyTo`,
`Expiration`, `MessageId`, `Timestamp`, `Type`, `UserId`, `AppId`. `Publishing` —
тот же набор полей на запись. Поля `AMQPBasicProperties` совпадают с ними
один в один, кроме `clusterId`, которого в `Publishing` нет (§7).

## 5. Таблица соответствия

Ядро плана: каждый флаг `ext-amqp` — булев параметр метода `amqp091-go`.

| PHP (`SConcur\Features\Amqp`) | Go (`amqp091-go`) |
|---|---|
| `AMQPConnection::connect()` | `Dial` / `DialTLS` |
| `AMQPConnection::disconnect()` | `Connection.Close` |
| `new AMQPChannel($connection)` | `Connection.Channel` |
| `AMQPChannel::close()` | `Channel.Close` |
| `AMQPChannel::qos($size, $count, $global)` | `Channel.Qos(count, size, global)` |
| `AMQPChannel::startTransaction()` | `Channel.Tx` |
| `AMQPChannel::commitTransaction()` | `Channel.TxCommit` |
| `AMQPChannel::rollbackTransaction()` | `Channel.TxRollback` |
| `AMQPChannel::confirmSelect()` | `Channel.Confirm(false)` + `NotifyPublish` |
| `AMQPChannel::waitForConfirm($timeout)` | вычитывание канала `Confirmation` до таймаута |
| `AMQPChannel::waitForBasicReturn($timeout)` | вычитывание канала `Return` до таймаута |
| `AMQPChannel::basicRecover($requeue)` | `Channel.Recover(requeue)` |
| `AMQPExchange::declareExchange()` | `ExchangeDeclare(name, type, durable, autoDelete, internal, noWait, args)`, при `AMQP_PASSIVE` → `ExchangeDeclarePassive` |
| `AMQPExchange::delete($name, $flags)` | `ExchangeDelete(name, ifUnused, noWait)` |
| `AMQPExchange::bind` / `unbind` | `ExchangeBind` / `ExchangeUnbind` |
| `AMQPExchange::publish($message, $routingKey, $flags, $headers)` | `PublishWithContext(ctx, exchange, key, mandatory, immediate, Publishing)` |
| `AMQPQueue::declareQueue()` | `QueueDeclare(name, durable, autoDelete, exclusive, noWait, args)` → `Queue.Messages`, при `AMQP_PASSIVE` → `QueueDeclarePassive` |
| `AMQPQueue::delete($flags)` | `QueueDelete(name, ifUnused, ifEmpty, noWait)` → число сообщений |
| `AMQPQueue::purge()` | `QueuePurge(name, false)` → число сообщений |
| `AMQPQueue::bind` / `unbind` | `QueueBind` / `QueueUnbind` |
| `AMQPQueue::get($flags)` | `Get(queue, autoAck)` |
| `AMQPQueue::consume($callback, $flags, $tag)` | `ConsumeWithContext(...)` → `<-chan Delivery`, стриминг в PHP |
| `AMQPQueue::cancel($tag)` | `Channel.Cancel(tag, false)` |
| `AMQPQueue::ack($tag, $flags)` | `Channel.Ack(tag, multiple)` |
| `AMQPQueue::nack($tag, $flags)` | `Channel.Nack(tag, multiple, requeue)` |
| `AMQPQueue::reject($tag, $flags)` | `Channel.Reject(tag, requeue)` |

Разбор флагов:

| Флаг | Значение | Куда попадает |
|---|---|---|
| `AMQP_DURABLE` | 2 | `durable` у declare очереди/обменника |
| `AMQP_PASSIVE` | 4 | выбор `*DeclarePassive` |
| `AMQP_EXCLUSIVE` | 8 | `exclusive` у `QueueDeclare` и у `Consume` |
| `AMQP_AUTODELETE` | 16 | `autoDelete` у declare |
| `AMQP_INTERNAL` | 32 | `internal` у `ExchangeDeclare` |
| `AMQP_NOLOCAL` | 64 | `noLocal` у `Consume` |
| `AMQP_AUTOACK` | 128 | `autoAck` у `Consume` и `Get` |
| `AMQP_IFEMPTY` | 256 | `ifEmpty` у `QueueDelete` |
| `AMQP_IFUNUSED` | 512 | `ifUnused` у `QueueDelete` / `ExchangeDelete` |
| `AMQP_MANDATORY` | 1024 | `mandatory` у публикации |
| `AMQP_IMMEDIATE` | 2048 | `immediate` у публикации |
| `AMQP_MULTIPLE` | 4096 | `multiple` у `Ack` / `Nack` |
| `AMQP_NOWAIT` | 8192 | `noWait` у declare/delete/bind |
| `AMQP_REQUEUE` | 16384 | `requeue` у `Nack` / `Reject` |
| `AMQP_JUST_CONSUME` | 1 | не открывать нового консьюмера, читать уже открытого (§10) |

## 6. Публичный PHP API

Код ниже — рабочий `ext-amqp`-код, у которого поменяли только `use`.

Публикация:

```php
use SConcur\Features\Amqp\AMQPChannel;
use SConcur\Features\Amqp\AMQPConnection;
use SConcur\Features\Amqp\AMQPExchange;
use const SConcur\Features\Amqp\AMQP_DELIVERY_MODE_PERSISTENT;
use const SConcur\Features\Amqp\AMQP_DURABLE;
use const SConcur\Features\Amqp\AMQP_EX_TYPE_TOPIC;
use const SConcur\Features\Amqp\AMQP_NOPARAM;

$connection = new AMQPConnection([
    'host'     => 'sc-rabbitmq',
    'port'     => 5672,
    'login'    => 'sc_user',
    'password' => '_sc_password_567',
    'vhost'    => '/',
]);

$connection->connect();

$channel  = new AMQPChannel($connection);
$exchange = new AMQPExchange($channel);

$exchange->setName('events');
$exchange->setType(AMQP_EX_TYPE_TOPIC);
$exchange->setFlags(AMQP_DURABLE);
$exchange->declareExchange();

$exchange->publish('{"id":1}', 'order.created', AMQP_NOPARAM, [
    'content_type'  => 'application/json',
    'delivery_mode' => AMQP_DELIVERY_MODE_PERSISTENT,
]);
```

Потребление — в корутине, без блокировки воркера:

```php
$waitGroup = WaitGroup::create();

foreach (['orders', 'invoices', 'emails'] as $queueName) {
    $waitGroup->add(function () use ($connection, $queueName) {
        $queue = new AMQPQueue(new AMQPChannel($connection));

        $queue->setName($queueName);
        $queue->setFlags(AMQP_DURABLE);
        $queue->declareQueue();

        $queue->consume(function (AMQPEnvelope $envelope, AMQPQueue $queue): bool {
            handle($envelope->getBody());

            $queue->ack($envelope->getDeliveryTag());

            return true; // false — прекратить потребление и выйти из consume()
        });
    });
}

$waitGroup->wait();
```

Три консьюмера работают одновременно в одном процессе; пока ни у кого нет
сообщений, PHP-поток свободен для другой работы.

Разовое чтение:

```php
$envelope = $queue->get(); // ?AMQPEnvelope, null если очередь пуста

if ($envelope !== null) {
    $queue->ack($envelope->getDeliveryTag());
}
```

### Оговорка о корутине

Консьюмер надо дочитывать **в той же корутине**, где вызван `consume()`: при
завершении корутины её флоу останавливается, и Go-сторона отменяет консьюмера. Та
же оговорка, что у `HttpClient`, `SocketServer` и `WsClient`.

## 7. Осознанные отклонения от кальки

Список закрытый — всё, чего в нём нет, повторяется точно.

| Метод / поведение | Что делает SConcur | Почему |
|---|---|---|
| `pconnect()`, `pdisconnect()`, `preconnect()` | синонимы `connect()`/`disconnect()`/`reconnect()`; `isPersistent()` всегда `true` | постоянные соединения — понятие php-fpm. Воркер SConcur живёт долго, а соединения на стороне Go всё равно живут в пуле (§9), то есть постоянны по определению |
| `AMQPChannel::setConfirmCallback()` / `setReturnCallback()` | колбэки хранятся в PHP и вызываются из `waitForConfirm()` / `waitForBasicReturn()` | нативное расширение вызывает их из своего цикла ожидания; здесь цикл ожидания — это `waitFor*`, и он же вызывает колбэк |
| `getMaxChannels()`, `getMaxFrameSize()`, `getHeartbeatInterval()` | возвращают согласованные значения, полученные от Go при `connect()` | значения известны только после handshake; хранятся в PHP-объекте |
| `getUsedChannels()` | считается на стороне Go по реестру каналов соединения | локального счётчика в PHP недостаточно — каналы может закрывать sweeper |
| `AMQPEnvelope::getClusterId()` | читается у доставки, но не отправляется при публикации | в `Publishing` у `amqp091-go` этого поля нет; свойство исключено спецификацией AMQP 0-9-1 из публикации |
| `basicRecover()` | реализуется, но помечен как устаревший в доке | `Channel.Recover` помечен `deprecated` в драйвере |
| `AMQPChannel::__destruct()` | best-effort закрытие канала | PHP не сообщает Go о сборке мусора; страховка — TTL-подметание (§9) |

## 8. Протокол PHP ↔ Go

Один домен с конвертом команд — как `HttpClient`, `SocketClient`, `WsClient`:

- PHP: `src/Features/MethodEnum.php` → `case Amqp = 'amq';`
- Go: `ext/internal/types/method.go` → `MethodAmqp Method = "amq"` (плюс строка в
  `internedMethods`)
- Go: `ext/internal/features/factory.go` → `case types.MethodAmqp`

Конверт: `cm` — команда, `p` — её параметры (как
`Features\WsClient\Payloads\Base\BaseWsClientPayload`). Команды —
`src/Features/Amqp/AmqpCommandEnum.php` и `ext/internal/types/`:

| Команда | `cm` | Стриминг |
|---|---|---|
| открыть соединение | `con` | нет |
| закрыть соединение | `dis` | нет |
| открыть канал | `cho` | нет |
| закрыть канал | `chc` | нет |
| `basic.qos` | `qos` | нет |
| declare обменника | `exd` | нет |
| удалить обменник | `exx` | нет |
| bind / unbind обменника | `exb` / `exu` | нет |
| declare очереди | `qud` | нет |
| удалить очередь | `qux` | нет |
| bind / unbind очереди | `qub` / `quu` | нет |
| очистить очередь | `qup` | нет |
| публикация | `pub` | нет |
| `basic.get` | `get` | нет |
| `basic.consume` | `csm` | **да** |
| `basic.cancel` | `cnl` | нет |
| ack / nack / reject | `ack` / `nck` / `rej` | нет |
| `basic.recover` | `rcv` | нет |
| tx select / commit / rollback | `txs` / `txc` / `txr` | нет |
| confirm.select | `cfs` | нет |
| дождаться подтверждений | `cfw` | нет |
| дождаться возвратов | `rtw` | нет |

Каждый payload — `readonly`-класс в `src/Features/Amqp/Payloads/` с
Go-структурой того же имени в `ext/internal/features/amqp/payloads/payloads.go`;
перекрёстные ссылки в PHPDoc и в комментарии Go обязательны, как во всех фичах.

Всякая команда несёт `channelId` (кроме открытия соединения), дедлайн
`timeoutMs` и свои параметры. Тело сообщения и заголовки передаются как есть —
`AMQPEnvelope` собирается в PHP из обычной карты msgpack, поэтому конверт объектов
из [docs/msgpack-objects.ru.md](../../docs/msgpack-objects.ru.md) здесь не нужен:
у полей AMQP-таблицы нет типов, которых не было бы в msgpack (метки времени и
decimal `ext-amqp` тоже отдаёт скалярами).

## 9. Go: соединения и каналы

**Соединения** — пул по ключу, повторяющий `mongodb/connection/clients.go` и
`sql/pools.go`: ключ — сравнимая структура из host/port/vhost/login/TLS/таймаутов,
счётчик владельцев (`inUse`), `lastUsedAt`, фоновый sweeper закрывает соединение
без владельцев после TTL простоя. `features.Shutdown()` закрывает все.

Почему пул, а не «одно соединение на PHP-объект»: `AMQPConnection` в приложении
создают на запрос, как `Client` у MongoDB. Пул делает это дешёвым и заодно
переживает ситуацию, когда PHP-объект собран сборщиком мусора, ничего не сказав.

**Каналы** — отдельный реестр по строковому id (`sync.Map`, как
`pendingConnections` у `wsclient`): id генерируется при `cho` и возвращается в
PHP; все последующие команды находят канал по нему. Реестр глобальный намеренно —
`ack` вполне может прийти из другой корутины и, значит, из другого флоу, чем
`consume`.

Канал держит: `*amqp091.Channel`, ссылку на владеющее соединение (чтобы держать
его `inUse`), активных консьюмеров, каналы `NotifyPublish`/`NotifyReturn`, если
включён режим подтверждений.

Жизненный цикл канала:

1. явный `AMQPChannel::close()` → `chc`;
2. `__destruct` PHP-объекта → `chc` best-effort;
3. TTL-подметание: канал без активных консьюмеров и без команд дольше TTL
   закрывается (страховка от потерянных объектов);
4. смерть соединения (`Connection.NotifyClose`) → все его каналы вычищаются из
   реестра;
5. `features.Shutdown()`.

Отдельно: **тег доставки принадлежит каналу**. `ack` обязан уйти в тот же канал,
который доставил сообщение, иначе брокер закроет канал с ошибкой. Поэтому
`AMQPEnvelope` носит id своего канала, а `AMQPQueue::ack()` шлёт именно его — а не
id канала, к которому очередь привязана сейчас.

## 10. Стриминг `consume`

Команда `csm` регистрирует состояние (`contracts.StateContract`) — ровно как
`connectionState` у `wsclient`:

- первый `Next()` отдаёт метаданные консьюмера (присвоенный `consumerTag`, id
  канала);
- каждый следующий `Next()` отдаёт одну доставку, упакованную в msgpack;
- канал доставок опустошается неблокирующей проверкой перед `select` с
  `ctx.Done()` — иначе при одновременной готовности буфера и отмены `select` мог
  бы выбрать отмену и потерять уже полученное сообщение (та же ловушка, что
  разобрана в `wsclient/connect.go`);
- `Close()` отменяет консьюмера (`Channel.Cancel`) на **свежем** контексте:
  к моменту уборки контекст задачи уже отменён. Сам канал при этом не закрывается —
  он переживает консьюмера.

PHP-сторона `AMQPQueue::consume()`:

```
push csm -> ключ задачи
цикл:
    next(ключ)              // корутина кооперативно приостанавливается
    если !hasNext -> выход  // консьюмер отменён / канал умер
    собрать AMQPEnvelope
    вызвать колбэк
    если колбэк вернул false -> push cnl, выход
```

`AMQP_JUST_CONSUME` означает «не открывать нового консьюмера»: PHP берёт ключ
задачи ранее открытого консьюмера этой очереди и продолжает `next()` по нему.
Ключ хранится в самом объекте `AMQPQueue` — как `inboundKey` у
`Socket\Dto\AbstractConnection`.

Дедлайна у `csm` нет: консьюмер долгоживущий. Ожидание следующей доставки
ограничивается `read_timeout` из учётных данных соединения (0 — без ограничения),
как `readTimeoutMs` у WS-клиента.

## 11. Два обязательных требования Go

1. **Отмена по контексту.** Разовые команды (declare, publish, get, ack) работают
   на `task.GetContext()` и потому прерываются остановкой флоу. Долгоживущие
   ресурсы — соединение, канал, консьюмер — на контекст задачи не завязаны
   (иначе умирали бы вместе с первой же командой) и убираются по правилам §9–§10,
   на свежем контексте с таймаутом.
2. **Передача дедлайна.** Каждая разовая команда несёт `timeoutMs` и
   ограничивается `context.WithTimeout(task.GetContext(), …)`. Значение берётся из
   `rpc_timeout` учётных данных, публикация — из `write_timeout`.

## 12. Исключения

Калька требует, чтобы работал `catch (AMQPQueueException $exception)`. Поэтому
классы исключений лежат рядом с классами кальки, в `SConcur\Features\Amqp\`, и
повторяют иерархию расширения:

```
AMQPException extends RuntimeException
├── AMQPConnectionException
├── AMQPChannelException
├── AMQPQueueException
├── AMQPExchangeException
├── AMQPEnvelopeException
└── AMQPValueException
```

Это **отступление от правила проекта** «исключения живут в `SConcur\Exceptions\`»
— принятое владельцем и ограниченное калькой, ровно как публичные свойства в
`src/Bson/`. Без него не работает `catch (AMQPQueueException $exception)` из кода
приложения, ради которого калька и делается.
Во внутренностях фичи (подкаталоги `src/Features/Amqp/`) правило действует без
изменений: ошибка задачи приходит `TaskErrorException` и переводится в подходящий
`AMQP*Exception` на границе публичного API.

Go-сторона помечает сетевые ошибки маркером в payload (как `net:` у сокет- и
WS-клиентов), чтобы PHP отличил недоступный брокер от ошибки протокола и бросил
`AMQPConnectionException`, а не `AMQPChannelException`.

## 13. Структура файлов

PHP — калька в корне каталога фичи, внутренности в подкаталогах:

```
src/Features/Amqp/
  AMQPConnection.php
  AMQPChannel.php
  AMQPExchange.php
  AMQPQueue.php
  AMQPEnvelope.php
  AMQPBasicProperties.php
  AMQPDecimal.php              # если есть в версии расширения, которую калькируем
  AMQPTimestamp.php            # то же
  AMQPException.php  …         # иерархия из §12
  constants.php                # autoload.files
  AmqpCommandEnum.php
  Payloads/Base/BaseAmqpPayload.php
  Payloads/*.php
  Support/FlagsParser.php      # int-флаги -> типизированные булевы поля payload
```

Разделение читается по регистру и по каталогу: `AMQP*` в корне — публичная
калька, живущая по правилам `ext-amqp`; всё остальное — обычный код фичи по
правилам проекта. `Support/FlagsParser` — единственное место, где живут битовые
маски: дальше по коду идут именованные булевы поля.

Go:

```
ext/internal/features/amqp/
  feature.go            # разбор конверта, диспетчер команд
  connections.go        # пул соединений + sweeper
  channels.go           # реестр каналов
  topology.go           # declare/delete/bind/unbind/purge
  publish.go
  get.go
  consume_state.go      # стриминг доставок
  acks.go               # ack/nack/reject/recover/cancel
  confirms.go           # confirm.select, ожидание подтверждений и возвратов
  tx.go
  payloads/payloads.go
```

## 14. Инфраструктура

`docker-compose.yml` — сервис по образцу существующих (данные в `tmpfs`, как у
остальных бэкендов, с той же оговоркой про бенчмарк-сессии):

```yaml
  rabbitmq:
    container_name: sc-rabbitmq
    image: rabbitmq:4.1-management
    restart: unless-stopped
    environment:
      RABBITMQ_DEFAULT_USER: ${RABBITMQ_USER}
      RABBITMQ_DEFAULT_PASS: ${RABBITMQ_PASSWORD}
    ports:
      - ${RABBITMQ_DOCKER_PORT}:5672
      - ${RABBITMQ_MANAGEMENT_DOCKER_PORT}:15672
    healthcheck:
      test: ["CMD", "rabbitmq-diagnostics", "-q", "ping"]
      interval: 3s
      timeout: 5s
      retries: 20
      start_period: 20s
    tmpfs:
      - /var/lib/rabbitmq:rw,noexec,nosuid,size=512m
```

`.env.example` — блок `RABBITMQ_HOST`, `RABBITMQ_PORT`, `RABBITMQ_USER`,
`RABBITMQ_PASSWORD`, `RABBITMQ_VHOST`, `RABBITMQ_DOCKER_PORT=25672`,
`RABBITMQ_MANAGEMENT_DOCKER_PORT=35672`.

`docker/php/Dockerfile` — `librabbitmq-dev` в список пакетов и установка
`ext-amqp` (нужно только для теста соответствия, §15 — так же, как `ext-mongodb`
стоит ради `BsonDriverParityTest`).

`composer.json` — `ext-amqp` в `require-dev` **точной версией**, а не диапазоном,
по образцу `"ext-msgpack": "3.0.1"`: набор методов и значения констант между
релизами расширения меняются, а тест соответствия (§15) сверяется именно с
установленным. Поднятие версии — отдельное действие, после которого этот тест
перепрогоняется, и его падение означает, что калька разошлась с оригиналом.

`makefile` — цели `bench-amqp-publish`, `bench-amqp-get`, `bench-amqp-consume`;
`rabbitmq` добавляется в `bench-reset`.

## 15. Тесты

Точная калька разбирается по трём слоям.

1. **Соответствие API** — `tests/feature/Features/Amqp/AmqpDriverParityTest.php`,
   по образцу `BsonDriverParityTest`. Через рефлексию сравнивает
   `SConcur\Features\Amqp\*`
   с классами `ext-amqp`: набор публичных методов, число и имена параметров, их
   типы и значения по умолчанию, возвращаемые типы, иерархия исключений, значения
   всех констант `AMQP_*`. Сравнение идёт с живым расширением, а не с литералами:
   обещание — соответствие расширению, а не значениям, записанным однажды.
2. **Соответствие поведению** — обмен между реализациями на живом брокере:
   опубликовать через `ext-amqp` → прочитать через SConcur и наоборот; сверить
   тело, ключ маршрутизации, все свойства и заголовки. Это ловит расхождения,
   которых рефлексия не видит: порядок аргументов, трактовку флагов, кодирование
   полей таблицы.
3. **Функциональные тесты фичи** — `tests/feature/Features/Amqp/`: топология
   (declare/bind/purge/delete, включая passive и очередь с именем от сервера),
   publish → get → ack, nack с requeue и без, reject, prefetch, транзакции,
   подтверждения публикации, `mandatory` и возврат, потребление в корутине,
   несколько консьюмеров одновременно, отмена по `false` из колбэка, остановка
   флоу на середине потребления, смерть брокера посреди работы.
   Резолвер настроек брокера — в `tests/impl/`, как у MongoDB.
4. **Go-тесты** — разбор флагов, реестр каналов, пул соединений (повторное
   использование по ключу, подметание по TTL), конвертация `Delivery` ↔ msgpack.

## 16. Бенчмарки

`tests/benchmarks/amqp/`: `publish.php`, `get.php`, `consume.php` — в трёх режимах
(native `ext-amqp` / SConcur sync / SConcur async), как принято в
[docs/benchmarks.ru.md](../../docs/benchmarks.ru.md). Ожидание, которое надо
проверить цифрами, а не декларировать: на одиночной публикации нативное
расширение быстрее (плюс граница), а на вееры публикаций и на нескольких
консьюмерах async выигрывает кратно. Честный результат идёт в доку как есть.

## 17. Версия расширения

Протокол PHP↔Go меняется → бампаются **все три** источника в одном коммите:
`ext/main.go` → `version()`, `src/Connection/Extension.php` →
`REQUIRED_EXTENSION_VERSION`, `composer.json` → `version`. Текущая — `0.10.0`,
новая — `0.11.0` (минорный: добавлен домен). Бамп один на ветку.

## 18. Фазы

Калька целиком — цель; порядок мёржа предлагается такой:

1. соединение, канал, топология (declare/bind/delete/purge), `publish`, `get`,
   `ack`/`nack`/`reject`, `qos` — плюс тест соответствия API целиком (он не
   зависит от того, что уже реализовано);
2. `consume` со стримингом, `cancel`, `AMQP_JUST_CONSUME`, тесты на корутины;
3. подтверждения публикации, возвраты, транзакции, `basicRecover`;
4. TLS, оставшиеся геттеры соединения, бенчмарки, доки.

## 19. Зафиксированные развилки

Открытых вопросов к владельцу не осталось.

- **Калька, а не свой API.** Публичная поверхность повторяет `ext-amqp`
  дословно, включая флаговый int-API; стиль проекта действует ниже границы
  кальки (§2, §13).
- **Домен `Amqp`, пространство имён `SConcur\Features\Amqp`.** По протоколу, не
  по брокеру; отдельного короткого пространства имён под калькой нет — имя класса
  границу не пересекает (§2).
- **Исключения `AMQP*Exception` — в пространстве имён фичи**, не в
  `SConcur\Exceptions\`: иначе не работает `catch (AMQPQueueException)` из кода
  приложения (§12).
- **`ext-amqp` в `require-dev` точной версией + `librabbitmq-dev` в образ**, ради
  теста соответствия — цена та же, что у `ext-mongodb` (§14, §15).
- **`pconnect()` = `connect()`.** Постоянные соединения — понятие php-fpm; на
  стороне Go соединения и так живут в пуле (§7, §9).
- **Пункт roadmap `Queue` не трогаем.** Эта фича — транспорт, а не абстракция
  отложенных задач; пункт остаётся в силе как есть (§1).

## 20. Доки

После реализации: `docs/amqp.md` + `docs/amqp.ru.md` (обе языковые версии,
свитчер, ссылки из `README.md`/`README.ru.md` и из `.ai/README.md`). Обязательные
разделы: миграция с `ext-amqp` таблицей «было → стало», таблица флагов, оговорка
про корутину и потребление, список отклонений из §7, ограничения. Ссылка на этот
план из доки не ставится — планы остаются в `.ai/`.
