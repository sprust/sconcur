# Перенос фичи AMQP на Rust-ядро

> **Статус: фича перенесена (2026-09-02).** Фазы 0–7 сделаны, весь набор
> `tests/feature/Features/Amqp` зелёный. Подпорки фазы 8 — `TEST_PATHS`, пути в
> конфиге консьюмер-мастера и в релизном workflow — снимаются следующим коммитом.
>
> Два расхождения с Go, оба — из-за того, что драйвер не умеет отправить то, что
> умел Go, и оба **отклоняются, а не теряются молча**: размер префетча
> (поля prefetch-size вообще нет в кадре `basic.qos` у `amq-protocol`) и
> `verify: false` на TLS-соединении. Оба записаны в `docs/amqp.md`.
>
> Из-за первого пришлось поменять способ в двух тестах `AmqpFailureTest`: они
> вызывали ошибку уровня соединения размером префетча, а теперь просят брокер
> закрыть соединение через management API. Предмет тестов — как такая ошибка
> отчитывается — не изменился.

## Что переносится

`ext-go-legacy/internal/features/amqp/` — 17 файлов, ~4.3 тыс. строк без тестов.
PHP-сторона (`src/Features/Amqp/`, 5155 строк) **не меняется**: она и есть
приёмочный контракт, вместе с 17 наборами в `tests/feature/Features/Amqp/`.

Протокол не меняется: `Method::Amqp` уже есть в `ext/src/types/method.rs`,
`amqpStopConsuming` уже экспортирован из `ext/src/lib.rs` заглушкой. Значит
набор C-экспортов тот же и **версия расширения остаётся 0.11.0**.

Ядро уже даёт всё, на чём фича стоит: реестр стримовых состояний с хуком на
конец флоу (`ext/src/states/`), самонасосный стрим (образец — `httpserver`,
`wsserver`), detached-путь (`Feature::handle_detached`), `src/stats`,
`src/logger`.

## Фаза 0: гейт по драйверу — пройден

Кандидаты: lapin 4.10.0 (12 млн загрузок, обновлён 2026-05-24) и amqprs 2.1.5.
Спайк предупреждал, что «lapin слабее amqp091-go ровно там, где у нас тонко»,
поэтому проверялись пять мест, на которых держится фича. Проверка — чтением
исходников крейта, не документации.

| Что нужно | lapin 4.10 | |
| --- | --- | :---: |
| Подтверждение публикации с возвратом | `PublisherConfirm` → `Confirmation::Ack/Nack(Option<BasicReturnMessage>)`, **на каждую публикацию отдельно** | ✅ |
| Пакетное ожидание подтверждений | `Channel::wait_for_confirms() -> Vec<BasicReturnMessage>` | ✅ |
| Отмена консьюмера брокером | `Consumer: Stream<Item = Result<Delivery>>`, поток кончается; кто отменил — знаем сами | ✅ |
| `basic.get` | `Channel::basic_get -> Option<BasicGetMessage>` | ✅ |
| Поля field table D и T | `AMQPValue::DecimalValue { scale: u8, value: u32 }`, `Timestamp(u64)` | ✅ |
| Код ответа брокера | `ErrorKind::ProtocolError(AMQPError)` → `get_id()`, `get_message()`, `kind()` Soft/Hard | ✅ |

Одно место оказалось **лучше**, чем в Go: подтверждение приходит на каждую
публикацию вместе со своим возвратом, тогда как `amqp091-go` отдаёт их двумя
каналами на весь канал и `confirms.go` сводит их сам. Одно — с оговоркой: конец
потока не говорит, кто отменил консьюмера, поэтому «брокер забрал» отличается от
«мы отменили» по нашему собственному флагу, а не по уведомлению драйвера.

`AMQPErrorKind::Soft/Hard` проводит границу «канальная ошибка / соединенческая»
сам, но таблица `connectionCloseCodes` из `feature.go` остаётся источником
правды: PHP ветвится по коду, и совпадение с Go важнее, чем удобство.

## Фазы 1–7: перенос

Каждая фаза принимается своими наборами, а не «на глаз».

| Ф | Что | Go-файлы | Приёмка |
| --- | --- | --- | --- |
| 1 | Контракт провода: конверт, параметры команд, field tables, Decimal/Timestamp, классификация ошибок по scope и коду | `payloads.go`, `table.go`, `values.go`, шапка `feature.go` | `AmqpMessageTest`, `AmqpFailureTest` |
| 2 | Соединения и каналы: пул по ключу опций (connect-таймаут в ключ не входит), рефкаунт, подметание 5 мин / 30 мин, TLS и SASL EXTERNAL, detached close/disconnect | `connections.go`, `dial.go`, `channels.go`, `channel_entry.go` | `AmqpConnectionOptionsTest`, `AmqpTest` |
| 3 | Топология, публикация, `basic.get`, ack/nack/reject | `topology.go`, `publish.go`, `get.go`, `acks.go` | `AmqpTopologyTest`, `AmqpTest`, `AmqpDelayedPublishTest` |
| 4 | Подтверждения публикации и возвраты, очереди на 1024 и 128 | `confirms.go` | `AmqpConfirmTest`, `AmqpRetryScheduleTest`, `PublishChannelPoolTest` |
| 5 | Консьюмер-генератор поверх реестра состояний | `consume_state.go` | `AmqpConsumeTest` |
| 6 | Супервизируемый консьюмер: самонасосный стрим, переоткрытие через секунду, живой `amqpStopConsuming`, остановка с дренажом | `consume_serve.go` | `QueueConsumerTest`, `QueueConsumerChannelTest`, `AmqpConsumerPoolTest` |
| 7 | Телеметрия консьюмеров: открытые, доставленные, подтверждённые, отказанные, среднее время, корзины по возрасту | `consumerstats.go` | `AmqpConsumerTelemetryTest` |

## Фаза 8: сдача

`AmqpBehaviourParityTest` целиком (он сверяет байты с `ext-amqp` на живом
брокере), `make mem-leak-amqp scenario=consumer` и `scenario=consumer-lost`.
Затем снять подпорки:

- убрать `TEST_PATHS` из makefile — `make test` снова гоняет `tests`;
- вернуть `config/sconcur.rabbitmq.config.json` и `mem-leak-amqp` на `ext/`;
- переключить релизный workflow на Rust-сборку;
- обновить раздел «Two cores» в [.ai/README.md](../README.md) и упоминания
  `ext-go-legacy/` в `docs/amqp.md` и `docs/amqp.ru.md`.

## Риски

Главный урок фазы MongoDB из [rust-core-spike.md](rust-core-spike.md): контракт
`ext-msgpack` нигде не выписан целиком, пять ошибок молча портили данные, и ни
одну из них не предотвратила бы аккуратность — их поймали тесты. Здесь приёмка
слабее: половину поведения (потеря соединения, удалённая под консьюмером
очередь) проверяет только `mem-leak-amqp`, где брокер надо ронять. Поэтому фазы
2 и 6 принимаются им, а не только PHPUnit'ом.
