# Транспорт без MessagePack: значения PHP строятся прямо из ядра

Адресат: разработчик `sconcur/sconcur`. Затрагивает границу PHP ↔ расширение целиком:
`ext/sconcur.c`, `ext/include/sconcur_core.h`, `ext/src/lib.rs`, `Connection/Extension`,
`Transport/*` и по одному файлу в каждой фиче с обеих сторон.

## Откуда идея

Rapira (https://rapira.rs, авторы RoadRunner) — сервер приложений для PHP на Rust, который
встраивает NTS PHP и обходится без сериализации: Rust строит zval напрямую через Zend API.
Из их исходников (MIT, `crates/sapi/src/zend.rs`, `crates/plugins/http/src/php/request.rs`)
взяты три механики:

- объект заполняется записью в слоты объявленных свойств: смещение берётся из
  `ce->properties_info` один раз и кэшируется, дальше запись без поиска по имени и без
  вызова конструктора — так же, как `unserialize()` или `ext-msgpack` сегодня;
- результат пишется прямо в `return_value` вызова, без промежуточного буфера;
- всё, что порождают рабочие потоки, материализуется в PHP-значения только на PHP-потоке,
  в момент, когда PHP сам пришёл за результатом.

Мы в более удобном положении, чем Rapira: расширение уже в процессе PHP, `push` и
`waitAnyBatch` уже исполняются на PHP-потоке. Байты между сторонами гоняются по
привычке Go-эпохи, когда другого способа не было.

## Что стоит сегодня

Один результат проходит: msgpack-кодирование на tokio-потоке, `malloc_copy`
(`ext/src/lib.rs`), копия в `RETVAL_STRINGL` (`ext/sconcur.c`), разбор бинарного
заголовка в userland (`Extension::parseResultFrame`, `parseWaitBatchResponse`), `substr`
полезной нагрузки и только потом `msgpack_unpack` в фиче. Замеры:

- `tests/benchmarks/runtime/result-profile.php`: `waitAnyTimeoutBatch` — 17% CPU
  PHP-стороны HTTP-сервера, из них разбор кадра в PHP — 11.6% + 7.6%;
- `tests/benchmarks/runtime/boundary-profile.php` на HEAD `1a380a9`: полный путь `waitAny`
  1.50 мкс CPU на результат, `waitAnyBatch(64)` 1.42, из них разбор кадра в PHP 0.77;
- декод запроса HTTP-сервера 7.75 мкс (`HttpServer::decodeRequest`) при цене всей границы
  11.3 мкс на запрос (`docs/rust-core.md`).

## Решение: C-строитель значений, не bindgen

Rapira генерирует Rust-биндинги к Zend API через bindgen по allowlist. Здесь выбран другой
путь: Rust по-прежнему не знает типов Zend, а zval строит небольшой набор C-функций в
`ext/sconcur.c`, которые Rust вызывает через `extern "C"` — тем же способом, каким уже
вызывает `sconcur_request_vm_interrupt`. Причины:

- bindgen требует libclang в обоих Docker-образах и ломается на свежих заголовках php-src
  (`preserve_none`), Rapira держит для этого обходы;
- набор нужных операций закрыт: null/bool/int/float/строка, массив (append/assoc), объект
  известного класса. Это те же примитивы, которыми написан C-распаковщик `ext-msgpack`;
- различия заголовков 8.4/8.5 (`HASH_KEY_IS_STRING`, inline-функции) гасятся компилятором
  C сами, без шимов и `cfg`;
- `build.sh` остаётся двухшаговым, без `build.rs`.

Правила для этого C-кода, взятые у Rapira: каждый экспорт Rust остаётся в `guarded()`;
через кадр, в котором может сработать `zend_bailout` (longjmp при OOM), не должно
проходить ни одно Rust-значение с Drop; если C-строитель вернул ошибку, на PHP-стороне
уже висит исключение, и Rust ничего больше не бросает.

## Этапы

### Этап A — результаты как объекты

`wait`, `waitAny`, `waitAnyTimeout`, `waitAnyBatch`, `waitAnyTimeoutBatch` возвращают готовые
`SConcur\Dto\TaskResultDto` (один или `list<TaskResultDto>`), `payload` пока остаётся
msgpack-байтами. Удаляются кадр, `BufferResult`, `malloc_copy`, оба парсера в
`Extension`, строка-сентинел `'timeout'` (вместо неё `null`) и строка `'error: ...'`
(вместо неё исключение). Протокол `push`/`next` не меняется.

Контракт C ↔ Rust (`ext/include/sconcur_core.h`):

- экспорты ожидания принимают `zval *return_value` и возвращают код: `0` — значение
  записано, `1` — таймаут (C отдаёт `null`), `2` — ошибка, исключение уже брошено;
- `sconcur_result_t` — плоская структура полей результата (указатели на байты и длины,
  флаги, миллисекунды, владелец, имя кейса `MethodEnum`); Rust заполняет её из `dto::Result`
  и зовёт `sconcur_result_into(zval *dst, const sconcur_result_t *)` или
  `sconcur_result_append(zval *list, const sconcur_result_t *)`;
- `sconcur_list_init(zval *dst, uint32_t size)` — массив под батч;
- `sconcur_throw(const char *class_name, const char *message)` — бросает userland-класс по
  имени (`SConcur\Exceptions\TaskErrorException`, `UnexpectedResponseFormatException`), при
  отсутствии класса — `zend_throw_error`.

Class entry `TaskResultDto` и `MethodEnum` C ищет через `zend_lookup_class` (с автозагрузкой
Composer) при первом результате и кэширует вместе со смещениями слотов. Кэш сбрасывается
в `PHP_RSHUTDOWN`: под FPM userland-классы пересоздаются на каждый запрос, и указатель
прошлого запроса — висячий. Это единственное новое место в модуле.

`totalExecutionMs` считает ядро: `Instant::now()` на входе в экспорт, первый результат
батча получает прошедшее время, хвост — `0` (он уже лежал готовым, ждать ему было нечего —
та же семантика, что у `$crossingEnd` сегодня). `executionMs` и `ownerFiberId` без
изменений.

Метод едет как имя кейса enum (`Method::as_php_case`): C берёт объект кейса через
`zend_enum_get_case_cstr`. Неизвестный кейс — `UnexpectedResponseFormatException` с тем
же текстом, что сегодня (ядро новее пакета).

Что меняется вокруг:

- `tests/feature/Connection/WaitAnyBatchTest.php`: тесты на испорченный кадр уходят вместе
  с кадром; хвост батча без ожидания проверяется вживую; добавляется проверка типа и
  кейса `method`;
- `tests/benchmarks/runtime/result-profile.php` и `bench-result` удаляются — они измеряли
  разбор, которого больше нет; строки про разбор уходят из `boundary-profile.php`,
  остальные строки там и есть замер «до/после»;
- `docs/architecture.md` (+ `.ru`), `.ai/README.md`: «кадр результата» → «результат»;
- версия: 0.15.0 → 0.16.0 во всех четырёх источниках, один бамп на ветку.

Гейт: `make check`; `boundary-profile` строки `waitAny (full path)` и `waitAnyBatch(64)`
не хуже 1.50 / 1.42 мкс CPU, ожидаемо заметно ниже; лесенка L0/L1 из `docs/rust-core.md`
для HTTP-сервера.

Замер после этапа A (та же машина, тот же день, `boundary-profile`, CPU мкс на результат):
`Extension::waitAny()` 1.500 → 0.664, `waitAnyBatch(64)` 1.415 → 0.778, строка
`raw waitAny` 0.663 → 0.658 — то есть вся экономия это исчезнувший PHP-разбор кадра плюс
копии, а сборка объекта в C стоит столько же, сколько стоил бинарный кадр. Лесенка L0/L1
на момент записи не снималась.

### Этап B — payload результата как PHP-значение

На Rust-стороне появляется промежуточное представление, которое таск собирает на
tokio-потоке, а экспорт ожидания материализует на PHP-потоке: enum
`Value { Nil, Bool, Int, Float, Str(bytes), List, Map, Object { class, props } }`.
`dto::Result.payload` становится `Value` вместо `Vec<u8>`; C-строитель получает
операции для вложенных массивов и объектов (`sconcur_value_*`). Фичи переводятся по одной,
сначала sleeper и HTTP-сервер (там есть замеры `bench-request`), MongoDB — последней:
её BSON-объекты создаются как `SConcur\Bson\*` через `object_init_ex` по тому же кэшу
class entry, nil-конверт `ext-msgpack` и его нумерация контейнеров уходят целиком.
Redis-ответы «не массив» перестают быть особым случаем (`ReplyDecoder`).

Открытый вопрос этапа: `Value` против типизированного результата фичи с `trait ToPhp`.
`Value` проще и покрывает все 12 фич одним строителем; типизированный вариант экономит
одну аллокацию дерева на результат. Решается замером на SQL-батче строк.

Сделано (2026-10-18): `dto::Value` (`ext/src/dto/value.rs`), строитель значений в
`ext/sconcur.c` (`sconcur_value_*`, слоты контейнеров выдаются по одному: дочерний
элемент заполняется до запроса следующего, потому что растущая таблица двигает слоты),
кэш class entry значений-объектов на запрос. Все 12 фич строят `Value`; `rmp`/`rmpv`
остались только на входе. PHP: `TaskResultDto::$payload` — `mixed`,
`MessagePackTransport::unpack` и `ResponseIsNotMessagePackException` удалены,
`DocumentSerializer::unserialize*` принимают готовые массивы. Замер: `bench-request`
`decodeRequest (whole)` 2.05 мкс без стадии распаковки (раньше распаковка была отдельной
стадией); `boundary-profile` на уровне этапа A (0.72 / 0.73 мкс CPU). Типизированный
вариант не понадобился: дерево строится один раз на tokio-потоке, и его цена не видна
за ценой самой задачи.

### Этап C — push без MessagePack

`push` принимает `array` payload; C обходит `HashTable` на PHP-потоке и через callbacks
отдаёт Rust типизированную структуру сразу (данные PHP живут только до возврата из
вызова). После этого `ext-msgpack` уходит из `composer.json`, обоих Dockerfile,
`Extension::checkMsgpackObjectSupport`, `Transport/MessagePackTransport`,
`docs/msgpack-objects.md` удаляется, `rmp`/`rmp-serde`/`rmpv` — из `Cargo.toml`.

Оговорка: декод payload переезжает с tokio-потоков на PHP-поток. Сегодня `msgpack_pack`
тоже на PHP-потоке, обход таблицы без выходного буфера должен быть дешевле, но это
измеряется (`bench-push`), не обещается.

Сделано (2026-10-18). `push` принимает `array`; C-читатели значений (`sconcur_zval_*`,
`sconcur_array_iterate`, `sconcur_object_iterate` в `ext/sconcur.c`) отдают ядру тип,
скаляры и обход контейнеров через callback, а `read_value` в `lib.rs` строит из этого
`Value` на PHP-потоке до возврата из вызова (глубина ограничена 256). Объявленные свойства
объекта лежат в таблице свойств косвенно (`IS_INDIRECT`), это читается через; `stdClass`
приходит картой, остальные объекты — `Value::Object` с публичными свойствами. Параметры
фич читаются через `Value::decode::<T>()` — serde-десериализатор поверх дерева
(`ext/src/dto/value_de.rs`): пустой PHP-массив годится и за пустую карту, и за пустую
структуру, целочисленный ключ читается как текст там, где ждут строку, `Vec<u8>`-поля тел
и чанков идут через `deserialize_with = "value_de::bytes"` одной копией, а не по байту.
Выбор `Value` вместо `rmpv`: ещё одно дерево на входе не нужно, PHP-массив уже дерево.

Ушли целиком: `rmp`, `rmp-serde`, `rmpv` из `Cargo.toml`; `ext-msgpack` из `composer.json`
и обоих Dockerfile; `Transport/MessagePackTransport`, `checkMsgpackObjectSupport` и его
исключение с тестом; `DocumentSerializer::serialize` (документ уезжает в `dt` как есть);
nil-конверт и нумерация контейнеров в `mongodb/serializer` (теперь `from_value.rs`);
`docs/msgpack-objects.*` (его предмет переехал в раздел «Object conversion» в
`docs/mongodb.md`). Поведение, которого PHP достичь не мог (тело не строкой, ключ-float в
AMQP-таблице, целое за `i64`), больше не обрабатывается: там теперь ошибка декодера или
отсутствие ветки, что агенты конвертации перечислили в своих отчётах.

## Риски

- Смена протокола во всех трёх этапах, минорный бамп на ветку.
- Принцип README «C-глю заморожен» переформулирован: клей не растёт с каждой фичей,
  но набор экспортов не фиксирован — README перечисляет его как есть.
- Висячий class entry под FPM — закрыт сбросом в RSHUTDOWN, проверяется
  `ext/check/fpm-check.sh` (`make ext-check`).
- Zend bailout против Drop: C-строитель не вызывает userland-код, кроме автозагрузки
  класса при первом результате; дерево `Value` этапа B освобождается после возврата
  строителя, не внутри него.

## Ревью и замеры после трёх этапов

Четыре ревьюера с пустым контекстом (C-глю и граница Rust; mongodb/amqp/redis;
files/sql/http/socket; PHP и доки) нашли и было исправлено: мёртвая проверка
неизвестного кейса enum в `sconcur_result_into` (`zend_enum_get_case_cstr` в release
разыменовывает результат поиска, теперь кейс ищется руками в `constants_table`);
строка, подходящая за список в `deserialize_seq` (убрано: байты только через
`value_de::bytes`); `Value: Deserialize` терял класс объекта (маркер `"\0class"` через
`deserialize_any`); длины строк сужались до `c_int` (`byte_view_len`); запись в
виртуальное свойство и утечка значения по умолчанию в слоте; исключение из
`get_properties` ленивого объекта не останавливало push (`sconcur_exception_pending`);
три копии тела у фич с нетипизированным `dt`/`p` (`Borrowed<'a>` — ссылка в сообщение
вместо копии поддерева); составной SQL-биндинг уходил драйверу текстом (теперь ошибка);
тихие `is_array ?: []` на PHP-стороне (теперь `UnexpectedResponseFormatException`);
`composer.lock` с `ext-msgpack`; FPM-образ со старым `pie install`; устаревшие
комментарии. Добавлены юнит-тесты форм для AMQP (таблицы, свойства, доставка), SQL
(батч, exec, биндинги), HTTP-сервера и клиента, socket/ws-событий. Механизм описан в
`docs/value-conversion.md`.

A/B на одном HEAD, базовое ядро `1a380a9` собиралось во временном worktree
(CPU мкс на операцию, медианы): `Extension::waitAny()` 1.55 → 0.90, `waitAnyBatch(64)`
1.56 → 1.02, `Extension::push` 3.1–3.8 против 3.0–3.6 на тех же прогонах — в пределах
разброса между запусками (до `Borrowed` push был стабильно на ~0.4 мкс дороже:
копия поддерева `dt`/`p`),
sync `pgsql selectOne` ×100 21.8 → 19.7 мс, `mysql selectOne` 13.8 → 10.5 мс,
`mongodb findOne` 8.7 → 9.9 мс (в пределах разброса).

## Итог

Все три этапа и ревью в ветке, версия расширения 0.16.0. После них:

- push отказывает `Closure`, кейсу enum и ресурсу с ошибкой, которая их называет, —
  то, что обещала `docs/value-conversion.md`; тест в `ExtensionCallErrorTest`;
- все замеры в доках сняты заново одной сессией 2026-10-18/19 на дисковых томах:
  фичи, сравнение с RoadRunner и Swoole, лесенки, load-testing, преемпция, прокси.
  Абсолютные цифры выросли не из-за транспорта: 2 октября система обновилась с
  Ubuntu 24.04 на 26.04 (ядро 6.8 → 7.0), а 15 октября включён профиль питания
  «Производительность» (+11.5% rps на пустом эндпоинте). Сравнение master и ветки на
  пустом эндпоинте в одной сессии: ≈236 700 против ≈248 000 rps;
- ревью доков с пустым контекстом: 44 находки, исправлены все (тело запроса HTTP
  читается целиком, pong не ждётся, имена эпохи Go, повторённые общие ограничения,
  старые цифры, жаргон);
- найден и исправлен баг преемпции: таймер, сработавший внутри обработчика
  pcntl-сигнала, бросал `FiberError` из обработчика, и SIGTERM терялся — воркер
  продолжал работать. Хук теперь пропускает места с запретом переключения файберов и
  первым вызывает разбор сигналов; тест `SchedulerPreemptionSignalTest`;
- скрипты нагрузки ждут выхода воркеров после SIGTERM и добивают уцелевших — забытый
  воркер однажды незаметно испортил серию замеров;
- `make bench-docs` (`tests/benchmarks/docs/run.py`) снимает все замеры, которые
  цитируют доки, и кладёт сырые результаты сессии в `.bench-runs/docs/`.

Осталось: полный прогон `make bench-docs` целиком (проверены разделы `--servers` и
`--amqp`), merge в master и релиз 0.16.0.
