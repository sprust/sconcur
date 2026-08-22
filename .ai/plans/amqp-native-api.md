# AMQP: замена кальки ext-amqp на нативный API SConcur

Адресат: разработчик `sconcur/sconcur`. Пользовательские доки, которых это
коснётся, — [docs/amqp.md](../../docs/amqp.md) и её русская пара.
Предшественник: [rabbitmq.md](rabbitmq.md) (реализация фичи),
[queue-consumer-pools.md](queue-consumer-pools.md) (рантайм консьюмера и группы
мастера).

Статус: **план**.

## 1. Цель

Заменить публичный API фичи AMQP с кальки PECL `ext-amqp` на объекты в стиле
самого проекта. Одна дверь, а не две: фасад поверх кальки отвергнут — два API в
одной фиче противоречат заявленной цели простоты.

Не-цели:

- Переписывать Go-сторону. Команды AMQP те же, меняется только форма PHP-объектов
  над ними. Из Go уходит ровно то, что уходит из API (§5).
- Менять рантайм консьюмера. `Consumer/QueueConsumer` — уже наш слой, он
  адаптируется под новые типы, но его логика (супервизор дренажа, канал на
  корутину, поведение упавшего обработчика) остаётся как есть.
- Сохранять совместимость с прежним API. Она никому не нужна (§2).

## 2. Почему сейчас

`git tag` заканчивается на `v0.9.1`. Фича AMQP не выпущена ни разу, ветка
`feature/amqp-rabbitmq` не влита. Пользователей ноль, значит ломающее изменение
стоит только нашей работы. После релиза цена станет постоянной.

Отсюда следует и порядок: делать **на этой же ветке, до влития**. Влить API,
который решено заменить, — значит либо выпустить его и связать себе руки, либо
сломать сразу после мержа.

Что подтолкнуло к пересмотру:

- Калька — идиома C-расширения 2009 года. Мутабельный мешок сеттеров
  (`setName` / `setFlags` / `declareQueue`) вместо конструктора, целочисленные
  битовые маски вместо именованных аргументов, `delivery_mode => 2` вместо
  `persistent: true`.
- Остальные фичи проекта устроены иначе. Mongodb калькирует **словарь**
  (`SConcur\Bson\*` повторяет `MongoDB\BSON\*`), но не **сантехнику**: API — это
  `Client → Database → Collection`, форма современной библиотеки, а не сырого
  `ext-mongodb`. AMQP оказался единственной фичей, выпадающей из стиля.
- Обещание «миграция = смена `use`» адресовано половине рынка: экосистема
  расколота между `ext-amqp` (на нём `symfony/amqp-messenger`) и `php-amqplib`
  (на нём `vladimir-yuldashev/laravel-queue-rabbitmq`). Второй половине наши
  `AMQPExchange::setName()` так же чужды, как и любой другой API.
- Часть формы кальки существует только потому, что расширение не умеет иначе.
  Пара «колбэк + отдельный вызов ожидания» для конфирмов и для `basic.return` —
  прямое следствие синхронного расширения; в корутинной модели это обычный await
  (§4.6).

## 3. Именование и размещение

- Классы в `SConcur\Features\Amqp\`: `Connection`, `Channel`, `Queue`,
  `Exchange`, `Message`, `Delivery`, `QueueInfo`, `ConnectionOptions`,
  `TlsOptions`, `MessageProperties`.
- Перечисления с суффиксом `Enum`, как везде в проекте (`MethodEnum`,
  `SqlCommandEnum`): `ExchangeTypeEnum`.
- Исключения переезжают в `SConcur\Exceptions\Amqp\` — правило проекта
  (`.ai/README.md`, «Exceptions»), которое калька нарушала вынужденно: parity
  требовал имён `AMQPException` и соседства с классами. Там уже лежит
  `InvalidQueueSpecException`.
- Имена без `AMQP`-префикса капсом. `Connection`, а не `AMQPConnection`:
  неймспейс уже говорит, чья это `Connection`, ровно как
  `Mongodb\Connection\Client` не называется `MongoClient`.

## 4. Целевой API

### 4.1 Соединение

```php
use SConcur\Features\Amqp\Connection;
use SConcur\Features\Amqp\ConnectionOptions;

$connection = new Connection('amqp://sc_user:pass@sc-rabbitmq:5672/%2f');

// либо полная форма
$connection = new Connection(new ConnectionOptions(
    host:           'sc-rabbitmq',
    port:           5672,
    login:          'sc_user',
    password:       '_sc_password_567',
    vhost:          '/',
    connectTimeout: 3.0,
    readTimeout:    0.0,
    writeTimeout:   0.0,
    rpcTimeout:     0.0,
    heartbeat:      60,
    channelMax:     256,
    frameMax:       131072,
    connectionName: 'api',
    tls:            new TlsOptions(caCert: '/certs/ca.pem', cert: null, key: null, verify: true),
));

$connection->connect();      // необязательно: соединение открывается лениво
$connection->isOpen();
$connection->close();
```

`ConnectionOptions` — `readonly`, как `HttpClientOptions`. Это убирает ~30
методов: все `setHost/getHost/setPort/getPort/...`. Поддержка DSN — вместо
массива из пяти ключей, который сейчас приходится писать в каждом скрипте.

Открытие ленивое, как у Mongodb `Client`: конструктор ничего не ждёт,
соединение поднимается на первой команде. `connect()` остаётся, чтобы упасть на
старте, а не под нагрузкой.

Уходят `pconnect/pdisconnect/preconnect` — persistent-соединения бессмысленны в
долгоживущем процессе; и `reconnect()` — это `close()` + `connect()`.

### 4.2 Канал

```php
$channel = $connection->channel(prefetchCount: 10);

$channel->prefetch(count: 10, size: 0, global: false);
$channel->id();
$channel->isOpen();
$channel->close();
```

Один метод `prefetch()` вместо восьми (`setPrefetchCount`, `getPrefetchCount`,
`setPrefetchSize`, `getPrefetchSize` и четыре их `Global`-двойника).

### 4.3 Очередь

Хэндл дешёвый и не ходит в сеть — как `selectCollection()` у Mongodb:

```php
$queue = $channel->queue('orders');

$info = $queue->declare(durable: true, arguments: ['x-max-priority' => 10]);
$info->messageCount;
$info->consumerCount;

$queue->declarePassive();                                  // вместо AMQP_PASSIVE
$queue->bind(exchange: 'events', routingKey: 'order.*');
$queue->unbind(exchange: 'events', routingKey: 'order.*');
$queue->purge();                                           // int
$queue->delete(ifUnused: false, ifEmpty: false);           // int
```

`declare()` возвращает `QueueInfo`, а не голый `int`: Go уже отдаёт оба поля
(`topology.go`: `MessageCount`, `ConsumerCount`), а калька теряла второе, потому
что `ext-amqp` возвращает только счётчик сообщений. Новой работы в расширении
это не требует.

Флаги-аргументы стали именованными булевыми: `durable`, `exclusive`,
`autoDelete`, `ifUnused`, `ifEmpty`. `passive` вынесен в отдельный метод —
`declarePassive()` семантически другая операция («проверь, что есть»), а не
модификатор объявления.

### 4.4 Обменник

```php
$exchange = $channel->exchange('events');

$exchange->declare(type: ExchangeTypeEnum::Topic, durable: true, autoDelete: false, internal: false);
$exchange->declarePassive();
$exchange->bind(to: 'audit', routingKey: 'order.#');
$exchange->unbind(to: 'audit', routingKey: 'order.#');
$exchange->delete(ifUnused: false);
```

`ExchangeTypeEnum::{Direct, Fanout, Topic, Headers}` вместо строковых констант.

### 4.5 Публикация

```php
use SConcur\Features\Amqp\Message;

$channel->publish('{"id":1}', exchange: 'events', routingKey: 'order.created');

$channel->publish(
    new Message(
        body:          '{"id":1}',
        contentType:   'application/json',
        persistent:    true,
        priority:      3,
        correlationId: 'order-1',
        headers:       ['x-attempt' => 1],
    ),
    exchange:   'events',
    routingKey: 'order.created',
    mandatory:  true,
);

// в конкретную очередь, без знания про default exchange
$queue->publish('sleep:100');
```

Три вещи, которых у кальки нет:

- `Message` — `readonly`-объект с именованными полями вместо массива со
  строковыми ключами. `persistent: true` вместо `delivery_mode => 2`: это самое
  частое свойство сообщения, и знать магическое число ради него не нужно.
- Голая строка как сообщение для частого случая.
- `Queue::publish()` — отправка в очередь по имени. Ни `ext-amqp`, ни
  `php-amqplib` этого не дают, и в обоих приходится знать, что default exchange
  маршрутизирует по имени очереди. В нашем демо-сервере
  (`tests/servers/http/http-server.php`) ровно поэтому заведён
  `AMQPExchange` с пустым именем.

`immediate` не переносится: RabbitMQ его не поддерживает.

### 4.6 Конфирмы и невозвратные сообщения

Здесь калька теряет больше всего формы:

```php
$channel->confirms(enabled: true);

// подтверждение — это обычное ожидание, а не колбэк
$channel->publishConfirmed($message, exchange: 'events', routingKey: 'order.created', timeout: 5.0);
```

`publishConfirmed()` возвращается, когда брокер подтвердил, и бросает
`PublishNackedException` при отказе или `PublishConfirmTimeoutException` по
таймауту. С `mandatory: true` немаршрутизируемое сообщение возвращается как
`UnroutableMessageException` с самим сообщением внутри — вместо
`setReturnCallback()` + `waitForBasicReturn()`.

Пакетная публикация в нашей модели не нуждается в отдельном API: конкурентность
даёт `WaitGroup`.

```php
$waitGroup = WaitGroup::create();

foreach ($messages as $message) {
    $waitGroup->add(fn() => $channel->publishConfirmed($message, exchange: 'events', routingKey: $key));
}

$waitGroup->waitAll();
```

Оставляем и `$channel->waitForConfirms(timeout: 5.0)` — дренаж всех
неподтверждённых публикаций одного канала, для «опубликовал пачку и хочу
убедиться перед выходом».

Уходят: `setConfirmCallback()`, `setReturnCallback()`, `waitForConfirm()`,
`waitForBasicReturn()`.

### 4.7 Потребление

Стрим на Go-стороне уже вытягивающий (`nextStream` / `hasNext`), так что
итератор ложится на него естественнее, чем колбэк:

```php
foreach ($queue->consume(prefetchCount: 10) as $delivery) {
    handle($delivery->body);

    $delivery->ack();
}
```

`consume()` отдаёт `iterable<Delivery>` (генератор). Он заканчивается, когда
консьюмера отменили, канал умер или истёк read timeout. Выход из `foreach`
через `break` отменяет консьюмера — генератор снимает его в своём `finally`.

Разовое чтение:

```php
$delivery = $queue->get(autoAck: false);   // ?Delivery
```

### 4.8 Доставка

```php
class Delivery
{
    public string $body;
    public string $routingKey;
    public string $exchange;
    public string $consumerTag;
    public int    $deliveryTag;
    public bool   $redelivered;
    public MessageProperties $properties;

    public function ack(bool $multiple = false): void;
    public function nack(bool $requeue = true, bool $multiple = false): void;
    public function reject(bool $requeue = false): void;
    public function header(string $name): mixed;
    public function hasHeader(string $name): bool;
}
```

Подтверждение живёт на доставке, а не на очереди:
`$delivery->ack()` вместо `$queue->ack($envelope->getDeliveryTag())`. Delivery
держит слабую ссылку на свой канал — сильная удерживала бы канал живым за счёт
доставки, которую приложение положило в свойство.

Это не только эргономика: оно сносит целый пласт внутренностей (§6).

### 4.9 Ошибки

`SConcur\Exceptions\Amqp\`: `AmqpException` (база, `RuntimeException`),
`ConnectionException`, `ChannelException`, `QueueException`,
`ExchangeException`, `PublishNackedException`,
`PublishConfirmTimeoutException`, `UnroutableMessageException`,
`InvalidQueueSpecException` (уже есть).

Иерархия та же, что у кальки, — она была правильной. Меняются имена и
неймспейс.

## 5. Что выбрасывается

Без parity-теста каждое сокращение перестаёт быть спором:

| Что | Почему | Экономия |
| --- | --- | --- |
| `tx.select` / `tx.commit` / `tx.rollback` | RabbitMQ сам не рекомендует транзакции, их заменили конфирмы | `tx.go` (51 LOC), 3 payload-а, 3 метода |
| `basicRecover` | устаревший метод спеки, вытеснен `nack(requeue: true)` | `RecoverPayload`, метод, команда `rcv` |
| `pconnect` / `pdisconnect` / `preconnect` | persistent-соединения бессмысленны в долгоживущем процессе | 3 метода |
| `constants.php` целиком | битовые маски → именованные аргументы и `ExchangeTypeEnum` | ~80 LOC + запись в `autoload.files` |
| `Support/FlagsParser` | единственный потребитель масок; ниже него всё уже работает на именованных булевых | 24 LOC |
| `AMQP_JUST_CONSUME` | и так не реализован, числится отклонением в доке | строка в таблице отклонений |
| `AMQP_IMMEDIATE` | не поддерживается RabbitMQ | константа |
| Глобальный prefetch, четыре метода | сводится к `prefetch(global: true)` | 4 метода |
| Все `set*`/`get*` соединения | заменены `readonly ConnectionOptions` | ~30 методов |
| Колбэки конфирмов и возвратов | §4.6 | 4 метода |

Из Go уходят команды `txs`, `txc`, `txr`, `rcv` и, если `waitForConfirms`
остаётся единственным потребителем, ничего больше: `cfw` и `rtw` нужны и новому
API.

## 6. Внутренности, которые схлопываются

Не менее важно, чем публичная поверхность:

- **Реестр консьюмеров канала.** `AmqpResource::$internalConsumers`
  (`WeakReference` по тегу), `AMQPQueue::resolveConsumer()` и
  `OrphanedEnvelopeException` существуют ровно ради одной вещи: воспроизвести
  семантику второго аргумента колбэка в `ext-amqp`, где расширение
  раскидывает доставки всего соединения по единственному работающему циклу.
  У нас стрим на консьюмера, и доставка приходит из того итератора, который её
  читает, — владелец известен без поиска по тегу. Весь механизм удаляется.
- **`AmqpResource`.** Его развёрнутая docblock-мотивация («чтобы публичная
  поверхность осталась байт-в-байт той, что даёт ext-amqp, — ни одного лишнего
  геттера») перестаёт действовать. База, вероятно, остаётся ради `runCommand`
  и общего состояния «открыт / id», но урезается.
- **`DeliveredEnvelope extends AMQPEnvelope`.** Разделение на «конверт» и
  «доставленный конверт» шло из того, что в кальке `AMQPEnvelope`
  конструируется без аргументов. `Delivery` строится из массива сразу.
- **`AMQPBasicProperties` как база `AMQPEnvelope`.** Наследование доставки от
  свойств — иерархия `ext-amqp`. У нас `Delivery` **содержит**
  `MessageProperties`, а не наследует их.

## 7. Соответствие старого и нового

| Калька | Новый API |
| --- | --- |
| `new AMQPConnection([...])` + `connect()` | `new Connection($dsn)` |
| `new AMQPChannel($connection)` | `$connection->channel()` |
| `$channel->setPrefetchCount(10)` | `$connection->channel(prefetchCount: 10)` |
| `new AMQPQueue($channel)` + `setName()` | `$channel->queue('orders')` |
| `$queue->setFlags(AMQP_DURABLE)` + `declareQueue()` | `$queue->declare(durable: true)` |
| `$queue->declareQueue(): int` | `$queue->declare(): QueueInfo` |
| `new AMQPExchange($channel)` + `setName()` + `setType()` | `$channel->exchange('events')` + `declare(type:)` |
| `$exchange->publish($body, $key, AMQP_MANDATORY, ['delivery_mode' => 2])` | `$channel->publish(new Message($body, persistent: true), exchange:, routingKey:, mandatory: true)` |
| `$exchange->publish($body, 'orders')` через пустой обменник | `$queue->publish($body)` |
| `$queue->consume($callback)` | `foreach ($queue->consume() as $delivery)` |
| `$queue->ack($envelope->getDeliveryTag())` | `$delivery->ack()` |
| `$queue->get(AMQP_AUTOACK)` | `$queue->get(autoAck: true)` |
| `setConfirmCallback()` + `waitForConfirm()` | `publishConfirmed()` |
| `setReturnCallback()` + `waitForBasicReturn()` | `UnroutableMessageException` |
| `$channel->startTransaction()` | — (§5) |

## 8. Тесты

Parity-тестов два, и они разного качества.

- **`AmqpDriverParityTest` (285 LOC) — удаляется.** Рефлексия по сигнатурам
  против установленного расширения. Именно он запирает дизайн: любое сокращение
  поверхности требовало бы записи в список исключений.
- **`AmqpBehaviourParityTest` (277 LOC) — остаётся.** Публикует через
  `ext-amqp`, читает через нас и наоборот, сверяет каждое свойство, заголовок и
  поле таблицы. Это проверка **байтов на проводе**: она не зависит от формы
  PHP-объектов, меняется только синтаксис вызовов. Она и ловит перепутанный
  аргумент и криво закодированную field table, так что оракул сохраняется.
- **`AmqpDeviationsTest` (248 LOC) — удаляется.** «Отклонения» имеют смысл
  только относительно кальки.
- `ext-amqp` остаётся в `require-dev` ради поведенческого теста; из `require`
  его там и не было.

Адаптируются (меняется синтаксис вызовов, смысл проверок сохраняется):
`AmqpTest`, `AmqpTopologyTest`, `AmqpMessageTest`, `AmqpConsumeTest`,
`AmqpConfirmTest`, `AmqpFailureTest`, `AmqpConsumerPoolTest`,
`AmqpConsumerTelemetryTest`, `QueueConsumerTest`, `QueueSpecParserTest`,
`AmqpTestCase`, `tests/impl/TestAmqpResolver.php`.

Go-тесты (`consumerstats_test.go`, `registries_test.go`, `values_test.go`) не
трогаются: телеметрия считает трафик по командам и от формы PHP-объектов не
зависит.

Новое, чего сейчас нет:

- итератор `consume()` отменяет консьюмера при `break` из `foreach`;
- `publishConfirmed()` бросает на nack и по таймауту;
- `UnroutableMessageException` несёт вернувшееся сообщение;
- `Delivery::ack()` работает после того, как очередь-хэндл уничтожена
  (слабая ссылка на канал, §4.8);
- разбор DSN, включая `%2f` как vhost `/`.

## 9. Прочие места вызова

Помимо тестов переписываются:

- `tests/servers/http/http-server.php` — `rabbitmqPublisher()` схлопывается с
  25 строк примерно до пяти;
- `tests/consumers/amqp/amqp-consumer.php` — демо-воркер, объявление топологии
  и обработчик;
- `tests/benchmarks/amqp/{publish,consume,get}.php` и `lib/amqp-bench.php`;
- `tests/mem-leak/amqp-soak.php`;
- `Consumer/QueueConsumer` — тип обработчика становится
  `Closure(Delivery): void`, `consumeQueue()` строит `$channel->queue($spec->name)`
  и итерируется вместо колбэка.

Замеры в `docs/benchmarks.md` пересниматься не должны: путь данных и число
переходов границы те же. Если расхождение будет — это сигнал, что где-то
добавился лишний переход, и его надо искать, а не переписывать цифры.

## 10. Документация

`docs/amqp.md` и `docs/amqp.ru.md` (по 578 и 575 строк) переписываются:

- «Quick start», «Consuming», «Publisher confirms and returned messages»,
  «Errors» — под новый API;
- «Flags» — заменяется на именованные аргументы и `ExchangeTypeEnum`;
- «Migrating from ext-amqp» — переписывается из «смените `use`» в таблицу
  соответствия (§7), честную для обеих половин экосистемы: и для `ext-amqp`,
  и для `php-amqplib`;
- «Where the calque differs» — удаляется вместе с калькой;
- «What lives where», «Connections and channels on the Go side», «TLS and SASL
  EXTERNAL», «Scaling a consumer», «A supervised consumer», «Limits»,
  «Benchmarks» — правятся точечно, суть не меняется.

Обе версии держатся в синхроне, как требует правило проекта.

## 11. Порядок работ

1. Типы-значения: `ConnectionOptions`, `TlsOptions`, `Message`,
   `MessageProperties`, `QueueInfo`, `ExchangeTypeEnum`, исключения в
   `SConcur\Exceptions\Amqp\`. Ничего не ломают, ложатся рядом.
2. `Connection` и `Channel` поверх существующих payload-ов и `CommandRunner`.
3. `Queue`, `Exchange`, топология, `publish`, `get`.
4. `consume()` как генератор и `Delivery`; снос реестра консьюмеров (§6).
5. Конфирмы и возвраты (§4.6).
6. Удаление кальки: `AMQP*.php`, `constants.php`, `FlagsParser`,
   `DeliveredEnvelope`, `OrphanedEnvelopeException`, запись в
   `composer.json → autoload.files`.
7. Вычистить Go: команды `txs`, `txc`, `txr`, `rcv`, `tx.go`, соответствующие
   payload-ы и записи в `AmqpCommandEnum`.
8. `QueueConsumer` под новые типы.
9. Тесты: удалить два, адаптировать остальные, добавить новые (§8).
10. Прочие места вызова (§9).
11. Документация (§10).
12. `make check`, соак, замеры для сверки.

Шаги 1–5 добавляют код, ничего не ломая: обе поверхности сосуществуют внутри
ветки, пока не дойдёт до шага 6. Это позволяет проверять новый API тестами по
ходу, а не одним куском в конце.

Версия расширения: `0.11.0` на этой ветке уже поднята, правило — один бамп на
ветку, второго не будет. Изменения в Go вычитающие, PHP и расширение едут
вместе.

## 12. Риски и открытые вопросы

Проверено заранее: `passive` в `QueueDeclarePayloadParameters` уже отдельное
булево поле (`'pa'`), а не производная от маски, — значит шаг 3 Go не задевает.

- **`consume()` генератором и `FlowStoppedException`.** Остановка группы
  разматывает корутину в точке подвеса — внутри `yield`. Нужно проверить, что
  `finally` генератора (отмена консьюмера) отрабатывает так же, как сейчас
  отрабатывает выход из колбэк-цикла, и что закрытие канала не пытается ждать
  ответа у отцепленного файбера. Это ровно то место, где уже находили две
  регрессии (см. `queue-consumer-pools.md`), — покрыть тестом первым делом.
- **Слабая ссылка `Delivery` → канал.** Приложение вправе сложить доставку в
  свойство и подтвердить её позже. Если канал к тому моменту закрыт,
  `ack()` должен бросить внятное `ChannelException`, а не упасть на `null`.
- **Разбор DSN.** Кодирование vhost (`%2f`), пустой vhost, `amqps://` как
  включение TLS. Нужен свой тест; чужой библиотеки для этого не берём.
- **Объём.** Классы 3128 LOC переписываются в ~1800–2200; тесты 3700 LOC
  адаптируются, из них 533 удаляются. Payload-ы (1886 LOC), `Support` (891 LOC
  минус `FlagsParser`) и Go (4275 LOC минус вычеркнутое) остаются.
