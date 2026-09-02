# Уборка кода AMQP: дублирование, мёртвые параметры, асимметрия API

Адресат: разработчик `sconcur/sconcur`. Ветка `feature/amqp-rabbitmq`, HEAD `22116dc`.

Статус: **сделано целиком (2026-08-28).** Что вышло и чем отличается от плана — в §6 в
конце.

Предыдущий заход — [amqp-simplification.md](amqp-simplification.md) — снял архитектурную
сложность: консьюмер стал сервером на `Scheduler::serve()`, каналы доставок уехали в Go,
самодельный мьютекс в `connect()` убран. Этот план про то, что осталось после него:
не архитектура, а **повторяющийся код, мёртвые поля на проводе и несимметричный API**.

Находки получены чтением кода фичи целиком (PHP `src/Features/Amqp/**` — 3 480 строк,
Go `ext-go-legacy/internal/features/amqp/**` — 4 060 строк без тестов). Ничего из списка не меняет
поведения, кроме §2.4 (расширение API) и §2.8 (устранение медленного роста памяти).

## 1. Go: один шаблон, тринадцать копий

### 1.1 Что дублируется

Каждый хендлер команды, выполняемой на открытом канале, написан по одному скелету:

```go
func (f *AmqpFeature) handleExchangeDelete(task *tasks.Task, raw msgpack.RawMessage) {
	startTime := time.Now()                                  // 1

	var params payloads.ExchangeDeleteParams                 // 2
	if !decodeParams(task, raw, &params, "exchange delete params") {
		return
	}

	entry, ok := channelOf(task, params.ChannelId)           // 3
	if !ok {
		return
	}

	ctx, cancel := commandContext(task, params.TimeoutMs, defaultRpcTimeout)  // 4
	defer cancel()

	err := entry.do(ctx, func(channel *amqp091.Channel) error {               // 5
		return channel.ExchangeDelete(params.Name, params.IfUnused, params.NoWait)
	})

	if err != nil {                                          // 6
		fail(task, entry, "exchange delete", err)
		return
	}

	respondDone(task, startTime)                             // 7
}
```

Уникальны здесь две строки: вызов драйвера и то, чем отвечаем. Остальные ~23 — скелет.

Тринадцать хендлеров повторяют его дословно:

| Файл | Хендлеры |
| --- | --- |
| `topology.go` | ExchangeDeclare, ExchangeDelete, ExchangeBinding, QueueDeclare, QueueDelete, QueueBinding, QueuePurge |
| `acks.go` | Ack, Nack, Reject, Cancel |
| `publish.go` | Publish |
| `connect.go` | Qos |

Ещё два (`confirms.go`: ConfirmSelect, ConfirmWait) повторяют шаги 1–4 и расходятся дальше.
`get.go` и `consume_state.go` идут через `doAbandoning` и в этот шаблон не укладываются —
их не трогаем.

### 1.2 Что делать

**а) Встроенная структура вместо двух полей, повторённых шестнадцать раз.**

Шестнадцать `*Params`-структур в `payloads/payloads.go` объявляют одни и те же
`ChannelId` (`chid`) и `TimeoutMs` (`to`). Вынести их в одну встраиваемую:

```go
// ChannelCommand is what every command that runs on an already open channel carries: the
// handle it runs on, and the deadline PHP put on it.
type ChannelCommand struct {
	ChannelId string `json:"chid" msgpack:"chid"`
	TimeoutMs int    `json:"to" msgpack:"to"`
}

func (c ChannelCommand) Channel() string { return c.ChannelId }
func (c ChannelCommand) Timeout() int    { return c.TimeoutMs }
```

`msgpack/v5` инлайнит анонимные встроенные структуры сам (`types.go`, `shouldInline`), так
что провод не меняется. Формат остаётся байт в байт — проверяется существующими
`registries_test.go` и `values_test.go` плюс полным `make test`.

**б) Один прогон вместо тринадцати скелетов.**

```go
// onChannel runs one broker method on the channel a command names: decode the parameters,
// resolve the channel, bound the call, answer the task. What differs between the commands
// is the driver call and what it reports, and that is all a handler writes.
func onChannel[P payloads.ChannelParams](
	task *tasks.Task,
	raw msgpack.RawMessage,
	what string,
	fallback time.Duration,
	call func(channel *amqp091.Channel, params P) (any, error),
)
```

`nil` в первом возврате — «отвечать нечем», то есть `respondDone`; иначе `respond`.
Хендлер сжимается до:

```go
func (f *AmqpFeature) handleExchangeDelete(task *tasks.Task, raw msgpack.RawMessage) {
	onChannel(task, raw, "exchange delete", defaultRpcTimeout,
		func(channel *amqp091.Channel, params payloads.ExchangeDeleteParams) (any, error) {
			return nil, channel.ExchangeDelete(params.Name, params.IfUnused, params.NoWait)
		})
}
```

**в) Три хендлера с пост-шагом.** `Ack`/`Nack`/`Reject` после успеха зовут
`consumerStatsInstance.deliverySettled(...)`. Запись переезжает внутрь замыкания, сразу
после успешного вызова драйвера. Инверсии блокировок не возникает: замыкание выполняется
под `channelEntry.channelMutex`, а `consumerStats` — лист, он никогда не ходит обратно в
реестры каналов и соединений (проверено по `consumerstats.go`).

**г) ConfirmSelect/ConfirmWait** получают только префикс — отдельный
`resolveChannel(task, raw, what, &params)`, возвращающий `entry` и распакованные
параметры. Скелет у них расходится после шага 4, поэтому под `onChannel` их не загонять.

**Цена и выигрыш.** `+~50` строк (`ChannelCommand`, `onChannel`), `−~250` в
`topology.go` (338 → ~150), `acks.go` (159 → ~85), `publish.go`, `connect.go`. Итог
`≈ −200`. Что важнее строк: новая команда на канале перестаёт быть копипастой из соседней,
а обработка ошибок и дедлайнов гарантированно одинакова у всех.

### 1.3 Мёртвый параметр: канальный QoS при открытии

`Connection::channel()` всегда шлёт `'gsz' => 0, 'gct' => 0` — публичного API,
который сделал бы их ненулевыми, нет. На стороне Go это `ChannelOpenParams.GlobalPrefetchSizeBytes`
/ `GlobalPrefetchCount` и вторая половина `applyQos()` (`channels.go`), которая никогда
не выполняется. Канальный QoS уже доступен в рантайме через `Channel::prefetch(global: true)`
→ команду `Qos`, так что функциональность не теряется.

Убрать: два ключа в PHP, два поля в структуре, ветку в `applyQos`. `−15` строк и
минус один способ настроить одно и то же двумя путями.

Версия расширения не бампается: `0.11.0` уже поднята на этой ветке (правило «один бамп
на ветку», `.ai/README.md`).

## 2. PHP

### 2.1 Два докблока подряд в `Queue.php`

`src/Features/Amqp/Queue.php:195` и `:214` — над `publish()` и `publishConfirmed()` стоят
по два докблока подряд. PHP, PHPStan, PhpStorm и cs-fixer читают только второй, поэтому
описание метода («Publishes straight into this queue, through the default exchange…»)
не видит никто. Слить каждую пару в один блок.

Проверено скриптом по всей `src/Features/Amqp/**` — других таких мест нет.

### 2.2 Четыре списка одних и тех же тринадцати свойств

Одно и то же перечисление свойств сообщения выписано четырежды:

| Место | Форма |
| --- | --- |
| `Support/PropertiesCodec::encode()` | свойство → ключ провода (константа `STRING_PROPERTIES` + четыре `if`) |
| `Support/PropertiesCodec::decode()` | ключ провода → аргумент `MessageProperties` |
| `Support/PropertiesCodec::messageFrom()` | `MessageProperties` → `Message` |
| `Message::fromDelivery()` | `MessageProperties` → `Message`, **тот же список** |

Последние два идентичны построчно. Свести к одной фабрике:

```php
Message::fromProperties(string $body, MessageProperties $properties): self
```

`Message::fromDelivery()` становится `self::fromProperties($delivery->body, $delivery->properties)`,
`PropertiesCodec::messageFrom()` — `Message::fromProperties($body, self::decode($properties))`.
`−26` строк, и добавление свойства правится в трёх местах вместо четырёх.

Оставшиеся два списка (`encode`/`decode`) не сводятся: один читает объект, другой строит
его через именованные аргументы, и общая таблица заменила бы явный код рефлексией.

### 2.3 Идиома «detached push», написанная трижды

`Connection::__destruct()`, `Channel::closeDetached()` и `Channel::cancelConsumer()`
одинаково собирают `Extension::get()->push(flowKey: '', payload: new AmqpPayload(...))` и
глушат `Throwable`. Вынести в `AmqpResource`:

```php
protected function pushDetached(AmqpCommandEnum $command, array $data): void
```

`−20` строк, и «когда мы шлём команду, которую некому дождаться» описано в одном месте.

### 2.4 `Exchange::publishConfirmed()` без повторов — асимметрия API

`Queue::publishConfirmed()` принимает `retries` и `retryDelaysSeconds` и прокидывает их в
`Channel::publishConfirmed()`. `Exchange::publishConfirmed()` — нет, хотя зовёт тот же
метод. Приложение, публикующее через exchange, повторов лишено без всякой причины.

Одновременно сам цикл повторов (43 строки `while (true)` со счётчиком попыток внутри
`Channel::publishConfirmed()`) переезжает в `RetrySchedule`, который уже владеет
расписанием задержек:

```php
$schedule->attempt(
    retries: $retries,
    call: fn() => $this->publishAndWait(...),
);
```

`Channel::publishConfirmed()` остаётся телом одной попытки, политика повторов живёт целиком
в одном классе, и добавить `retries:` в `Exchange` становится тремя строками.

### 2.5 `QueueConsumer::serve()` — транзит на восемь параметров

`src/Features/Amqp/Consumer/QueueConsumer.php:273` объявляет `protected serve()` с восемью
параметрами и передаёт их в `Scheduler::serve()` один в один; вся его работа —
`catch (TaskErrorException)` → `AmqpFailure::translate()`. Свернуть: обернуть сам вызов
`Scheduler::get()->serve(...)` в `consume()` в `try`/`catch`. `−30` строк, минус одна
сигнатура, которую надо держать в синхроне с ядром.

### 2.6 Ёмкость соединения по каналам посчитана дважды

«На единицу меньше потолка, потому что нумерация каналов начинается с единицы» выражено в
двух местах:

- `Consumer/QueueSpecParser::MAX_CHANNELS_PER_CONNECTION` = `ConnectionOptions::MAX_CHANNELS - 1`;
- `Consumer/PublishChannelPool::capacityOf()` — то же, но от согласованного с брокером
  значения.

Свести в `ConnectionOptions::usableChannels(?int $negotiated = null): int` и звать оттуда
обоим. Правило «минус один» перестаёт быть комментарием в двух файлах.

### 2.7 `PublishChannelPool::discard()` — линейный поиск

`discard()` ищет соединение канала перебором `$this->connections`. Пул растёт по
соединению на 255 одновременно публикующих обработчиков, так что перебор короткий, но
он не нужен: индекс соединения известен в `open()`. Хранить `array<int, int>` «object id
канала → индекс соединения» рядом с `idleSince`, как уже сделано для времени простоя.

### 2.8 `QueueConsumer::$channels` растёт при переоткрытии консьюмера

`channelFor()` кэширует `Channel`-ручку по Go-шному id канала и не удаляет её никогда.
Консьюмер, которого забрал брокер, переоткрывается на стороне Go через
`getChannels().openBounded()` (`consume_serve.go:310`), а тот выдаёт **новый** id
(`nextChannelId()`). Значит после каждого переоткрытия в `$channels` остаётся ручка на
мёртвый канал — и такая же запись в `Connection::$internalChannels`.

Для воркера, живущего неделями рядом с брокером, который перезапускается по ночам, это
медленный рост памяти, а в `finally` в конце прогона — пачка деструкторов, шлющих
`ChannelClose` для идентификаторов, которых в Go уже нет.

Починка: в `channelFor()` выбрасывать из кэша записи, у которых `isOpen() === false`,
перед тем как завести новую. Дешёвая проверка на сообщение — только для промаха кэша.

Требует теста: переоткрытие консьюмера воспроизводится в `QueueConsumerTest` тем же
способом, каким это уже делает проверка «a consumer the broker takes away».

### 2.9 Мелочь: `self::` вместо `static::`

`Support/PropertiesCodec` — единственный класс фичи, зовущий собственные статические
хелперы через `self::` (14 мест); везде в `src/Features/Amqp/**` принято `static::`.
Привести к общему виду.

## 3. Что сознательно не трогаем

Чтобы план не читался как «перепишем всё»:

- **`Channel` (626 строк) не дробим.** Его поверхность — это поверхность AMQP-канала:
  жизненный цикл, QoS, публикация, подтверждения, ack/nack/reject, get, consume. Разрезание
  по «слишком длинный класс» ухудшило бы публичный API ради метрики. Из него уезжает
  только цикл повторов (§2.4).
- **`Channel::consume()` как генератор.** Публичный API, используется в доках, тестах,
  бенчмарках и `mem-leak/amqp-soak.php`; `ConsumeServe` живёт рядом, не вместо.
  Решение зафиксировано в [amqp-simplification.md](amqp-simplification.md) §7.
- **`Queue` и `Exchange` не сводим в общую базу.** Общего у них — конструктор, `name()` и
  `channel()`, около 25 строк; база ради них добавила бы уровень наследования, а различаются
  они в том, что как раз и составляет их тело.
- **`FeatureExecutor::canAwait()` в `Channel` и `PublishChannelPool`.** Убирается только
  §8 [coroutine-lifetime.md](coroutine-lifetime.md), см. «Отличия» в
  [amqp-simplification.md](amqp-simplification.md).
- **Строковый скоуп ошибки** `"<scope>:<code>: <text>"` и разбор в `AmqpFailure`.
  Структурное поле в кадре результата трогает все фичи и весь бинарный протокол.
- **Классы исключений** (`InvalidDelayException`, `InvalidPrefetchException`, …) —
  ровно то, чего требует конвенция проекта: своё исключение на случай.

## 4. Порядок работ

Шаги независимы; порядок — по убыванию выигрыша на единицу риска.

1. **§2.1, §2.9, §1.3** — механические правки без изменения поведения. Проверка:
   `make cs-fixer-check`, `make php-stan`, `make ext-test`.
2. **§2.2, §2.3, §2.5, §2.6, §2.7** — снятие дублирования в PHP. Проверка: `make test`
   целиком (`AmqpMessageTest`, `AmqpTest`, `QueueConsumerTest`, `PublishChannelPoolTest`).
3. **§2.8** — утечка ручек каналов, вместе с тестом на переоткрытие.
4. **§2.4** — `RetrySchedule::attempt()` и `retries` у `Exchange`. Проверка:
   `AmqpRetryScheduleTest`, `AmqpConfirmTest`, `AmqpDelayedPublishTest`. Правка доков:
   `docs/amqp.md` + `docs/amqp.ru.md` — таблица параметров `publishConfirmed`.
5. **§1.1, §1.2** — Go: `ChannelCommand` и `onChannel`. Самый крупный и самый рискованный
   шаг: трогает раскладку payload-ов. Делать последним и отдельным коммитом, чтобы откат
   был дешёвым. Проверка: `make ext-test` (включая `go test -race`), затем `make test`,
   затем `make bench-amqp-publish` и `bench-amqp-consume` — убедиться, что дженерик не
   стоит ничего заметного на горячем пути.

Доки правятся только в шаге 4 (§2.4 — единственное изменение публичного API). `.ai/README.md`
не трогается: описания `Features/Amqp/**` остаются верными.

## 5. Риски

- **Инлайн встроенной структуры в msgpack (§1.2а).** Авто-инлайн отключается, если имя поля
  затеняется. Ни одна `*Params` своих `ChannelId`/`TimeoutMs` после правки не объявляет, но
  на каждой встраиваемой поставить явный `msgpack:",inline"` и прогнать `registries_test.go`
  до всего остального.
- **Порядок блокировок в §1.2в.** Запись телеметрии переезжает под `channelMutex`.
  Проверить `go test -race` и убедиться по коду, что `consumerStats` остаётся листом.
- **§2.4 меняет публичный API** (новые необязательные параметры у `Exchange::publishConfirmed()`).
  Расширение, не ломающее изменение, но доки обязаны это отразить в обеих языковых версиях.
- **§2.8 меняет поведение** — ручка на закрытый канал перестаёт переиспользоваться.
  Доставка, пришедшая на канал, который умер между `channelFor()` и `settle()`, вела себя
  и раньше одинаково (`settle()` логирует и не бросает), но тест на это должен быть явным.

## 6. Что сделано и чем отличается от плана

### Сделано

**§1.1–1.2 — шаблон в Go снят.** `payloads.ChannelCommand` объявляет `chid` и `to` один
раз и встраивается в семнадцать `*Params`; msgpack инлайнит анонимные структуры, так что
провод не изменился. `onChannel[P]` в `feature.go` держит все пять шагов команды на канале,
`resolveChannel[P]` — первые два, для команд со своим хвостом. На прогон переведены
двенадцать хендлеров: семь в `topology.go` (338 → 190 строк), три в `acks.go`, `Qos` в
`connect.go`. Ещё пять — `Cancel`, `Publish`, `ConfirmSelect`, `ConfirmWait`, `Get`,
`Consume` — используют `resolveChannel` и пишут хвост сами.

**§1.3 — мёртвый канальный QoS убран.** `gsz`/`gct` не шлются, `ChannelOpenParams` их не
объявляет, `applyQos` стал одной строкой. Версия расширения не менялась: `0.11.0` уже
поднята на этой ветке.

**§2.1** — два докблока подряд в `Queue.php` слиты в один каждый.
**§2.2** — `Message::fromProperties()` стала единственным местом, где `MessageProperties`
превращается в `Message`; `Message::fromDelivery()` и `PropertiesCodec::messageFrom()`
зовут её.
**§2.3** — `AmqpResource::pushDetached()` собрал идиому «команда, которую некому дождаться»
из трёх мест в одно.
**§2.4** — цикл повторов уехал в `RetrySchedule::retrying()`; `Channel::publishConfirmed()`
стал телом одной попытки, `Exchange::publishConfirmed()` получил `retries` и
`retryDelaysSeconds`. Доки поправлены в обеих языковых версиях.
**§2.5** — транзитный `QueueConsumer::serve()` на восемь параметров убран, `try`/`catch`
стоит вокруг самого `Scheduler::get()->serve(...)`.
**§2.6** — `ConnectionOptions::usableChannels()` стала единственным местом, где живёт
правило «на единицу меньше потолка».
**§2.7** — `PublishChannelPool` помнит соединение канала по object id вместо перебора.
**§2.8** — рост ручек каналов закрыт, см. «Отличия».
**§2.9** — `PropertiesCodec` зовёт свои статики через `static::`, как остальная фича.

### Отличия от плана

- **§2.8 потребовал большего, чем планировалось.** «Выбрасывать записи, у которых
  `isOpen() === false`» не работает: PHP узнаёт о смерти канала только по упавшей на нём
  команде, а канал потерянного консьюмера закрывает Go, ничего не сообщая. Хуже того,
  выбросить ручку было небезопасно само по себе — `Channel::__destruct` закрывает канал,
  а эти каналы принадлежат Go, и `ChannelClose` отсюда забрал бы канал у соседних
  обработчиков.

  Поэтому добавлено `Channel::borrowed()` — ручка над каналом, который эта сторона не
  держит: отпустить её значит отпустить только ручку. Это и сделало кэш расходуемым.
  Выбрасываются ручки, на которых прямо сейчас никто не рассчитывается (`$inFlight`
  считает доставки в работе по каналу), и только при промахе кэша — то есть один раз на
  переоткрытый консьюмер, а не на сообщение. Счётчик нужен: `Delivery` держит канал слабой
  ссылкой, и ручка, выброшенная из-под работающего обработчика, оставила бы его без
  канала для `ack()`.

- **`Publish` не пошёл под `onChannel`.** Замыкание публикации нуждается и в самом
  дедлайне (его получает `PublishWithContext`), и в `channelEntry` (счёт сообщений,
  ждущих подтверждения). Расширять сигнатуру прогона ради одной команды дороже, чем
  оставить ей свой хвост на `resolveChannel` — так же, как у `Cancel` и `Get`.

- **`Cancel` тоже остался со своим хвостом.** Он снимает консьюмера из реестра **до**
  `do()`, а не внутри: отмена, не дождавшаяся своей очереди на канале, всё равно обязана
  отдать стрим.

### Проверки

`make check` — `cs-fixer` чисто, PHPStan level 6 чисто, `make test` 710 тестов
(1 incomplete, он был и до правок), `make ext-test` зелёный; `go test -race` по
`internal/features/amqp/...` локально тоже.

Новые тесты: `payloads/channel_command_test.go` — плоскость встроенной структуры на
проводе и то, что все семнадцать команд отвечают на `Channel()`/`Timeout()`;
`QueueConsumerTest::testTheHandleOverALostConsumersChannelIsNotKept` — ручка потерянного
консьюмера не копится (проверено, что тест падает `[1, 2]` вместо `[1, 1]`, если убрать
подметание).

### Замеры

`bench-amqp-consume`, четыре чередующиеся пары прогонов с пересборкой расширения между
ними (без пересборки сравнение бессмысленно — `.so` не отслеживается git):

| пара | до | после |
| ---: | ---: | ---: |
| 1 | 74 497 msg/s | 84 359 msg/s |
| 2 | 71 475 msg/s | 88 141 msg/s |
| 3 | 48 182 msg/s | 81 308 msg/s |
| 4 | 93 880 msg/s | 86 390 msg/s |

Разброс базовой линии (48…94 тыс.) шире любой разницы между вариантами, так что вывод
только отрицательный: замедления дженерик не дал. `bench-amqp-publish` без изменений.
