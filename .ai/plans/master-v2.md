# Мастер v2: приложение до мастера, fork из самого мастера

Записано 2026-10-26. Заменяет схему fork-сервера на поколение из
[fork-master/fork-master.md](fork-master/fork-master.md). Работа идёт в ветке
`feature/fork-master-v2`, которая создаётся от `feature/fork-master`. Исходная ветка
остаётся на origin как справочник: код, замеры, ревью и soak там.

## Содержание

- [Почему переделываем](#почему-переделываем)
- [Схема](#схема)
- [Библиотека](#библиотека)
- [Пакет Laravel](#пакет-laravel)
- [Без reload](#без-reload)
- [Защита мастера](#защита-мастера)
- [Что воркер получает от мастера](#что-воркер-получает-от-мастера)
- [Правила для приложения](#правила-для-приложения)
- [Что теряем](#что-теряем)
- [Опись исходной ветки](#опись-исходной-ветки)
- [Порядок работ](#порядок-работ)
- [Открытые вопросы](#открытые-вопросы)

## Почему переделываем

Ветка `feature/fork-master` к 2026-10-26: против `master` 253 файла, +23,9 / −5,8 тыс.
строк, вместе с аудитом доков и фиксом PostgreSQL. На мастер и fork приходится:
fork-сервер, поколения и `ForkedWorker` около 2,1 тыс. строк PHP, +1,2 тыс. правок в
`WorkerGroup` и `WorkerMaster`, фасад `Process` 570 строк, экспорт `process` на Rust около
1,8 тыс. строк, поток приёма с `EPOLLEXCLUSIVE` 390 строк, тесты `tests/feature/Worker/`
около 4,9 тыс. строк, `docs/worker-master.md` 1051 строка на язык.

Дорого обходятся три вещи:

- три уровня процессов (мастер → fork-сервер на поколение → воркеры) и автомат reload в
  `ForkServerGroup` (1065 строк, на ревью там нашлось три бага);
- договор `bootstrap` с пользователем: отдельный файл, worker script внутри функции,
  `artisan` в роли worker script не годится, две разновидности fork с разными оговорками;
- хвосты, которые продолжают расти: stdin программ из bootstrap, reload пула задач в
  пакете Laravel, мягкая остановка keep-alive соединений.

Ценность при этом в одном: память. Проверка 2026-10-26 на демо пакета `sconcur-laravel`
против этой ветки (копия в `.bench-runs/laravel-fork/`, 2 http-воркера после одинаковой
нагрузки): Pss 92,9 MiB в exec, 80,4 в fork без bootstrap, 62,7 с bootstrap; Private_Dirty
86,0 / 70,9 / 44,5. Пропускная одинакова (около 7 тыс. rps на `/api/health` во всех трёх).
На стенде из 12 воркеров (`make bench-worker-memory`): 279,1 / 191,0 / 59,8 MiB.

Новая схема сохраняет выигрыш fork с bootstrap и убирает fork-сервер, поколения, reload и
договор bootstrap. Так же устроены gunicorn с `--preload` и Swoole: процесс загружает
приложение, становится мастером и форкает воркеров.

## Схема

```
php vendor/bin/sconcur-laravel start   (или свой entry-скрипт без фреймворка)
  ├─ автозагрузчик, приложение загружено, $app — обычная переменная
  ├─ колбэки подготовки перед fork (прогрев, закрыть соединения)
  ├─ конфиг групп собран объектами: http, tasks, ws, amqp — у каждой роль-замыкание
  ├─ процесс помечается как мастер (см. «Защита мастера»)
  └─ Master::run($config, $roles): цикл супервизии в этом же процессе
       └─ fork → ребёнок готовится (ForkedWorker::prepare) и вызывает роль группы:
                 http → (new HttpServerRunner(...))->run($app) и т. п.
```

- Мастер форкает воркеров сам, fork-сервера нет. Перезапуск упавшего воркера — fork из
  мастера, около миллисекунды, приложение заново не грузится.
- Воркер не выполняет скрипт. Он продолжает на стеке мастера и вызывает роль своей группы,
  замыкание с уже загруженными объектами. Worker script, его область видимости, argv с
  блоком `server` и обратный разбор через `fromArgs` не нужны.
- Из роли воркер не возвращается: выход через `ProcessControl::exit()`, как сейчас в
  `ForkedWorker::run()`, иначе исключение раскрутит кадры мастера в ребёнке.

## Библиотека

Сделано в шаге 3 (2026-10-26):

```php
$master = new WorkerMaster(
    runtimeDir: '/app/storage/sconcur',
    groups: [
        new WorkerGroupConfig(
            name: 'http',
            role: static function (WorkerContext $context) use ($app): void {
                (new HttpServer(address: '0.0.0.0:8080', reusePort: true, ...))->serve($handler);
            },
            workerCount: 4,
        ),
        new WorkerGroupConfig(name: 'tasks', role: ..., workerCount: 1, restartPolicy: RestartPolicy::OnFailure),
    ],
    // name, logDir, logTo, panelPort, adminToken, onWatchdogEvent
);

exit($master->run());
```

- Конфиг — объекты, проверки в конструкторах (`InvalidConfigException`). JSON, argv и
  worker script нет; `bin/sconcur-server`, `MasterCli`, `MasterConfig`, `MasterDefaults`,
  `LaunchMode`, `ExecWorkerProcess` удалены (решение 2026-10-26: только PHP-API).
- `WorkerContext` (группа, слот, pid мастера) роль получает аргументом, он же
  `WorkerContext::current()`. Серверы и консьюмер без своего `masterPid` берут его оттуда
  (`StopRequest::watch`); телеметрию (`SCONCUR_TELEMETRY_SOCKET`, `SCONCUR_SERVER_NAME`) —
  из окружения, которое мастер выставляет воркеру, и в конструкторе, и в `fromArgs()`.
- `MasterControl` (`isRunning()`, `state()`, `stop()`) — управление из другого процесса по
  `runtimeDir` и `name`, через те же lock- и state-файлы.
- `fromArgs()` у серверов остался — для отдельных скриптов без мастера (стенды, тестовые
  серверы).
- Из расширения ушёл путь exec: захват heartbeat из переменной окружения на MINIT
  (`claim.rs`) и пометка дескрипторов на RINIT. Heartbeat бывает только от fork.
- Тестовый мастер — `tests/servers/worker/master.php` (JSON теста → `WorkerMaster`, роль
  выставляет argv и подключает тестовый worker script), управление — `control.php`. Им же
  поднимается стенд `servers` (supervisord).

## Пакет Laravel

Пакет — отдельный репозиторий (`sconcur-laravel`), делается после библиотеки.

- Один бинарник с подкомандами: `vendor/bin/sconcur-laravel start|stop|status|run <role>`.
  `run http` запускает одну роль на переднем плане без мастера, для разработки; он заменяет
  `sconcur:servers:http:start` и подобные.
- Приложение бинарник находит через `$_composer_autoload_path` (Composer 2.2+): корень
  проекта на два уровня выше, дальше `bootstrap/app.php`. Нестандартная раскладка — флаг
  `--app=` или переменная окружения.
- Загружается console kernel (`bootstrap()`): он грузит и отложенные провайдеры, так что до
  fork в памяти всё приложение.
- `stop` и `status` тоже загружают приложение: им нужны `runtimeDir` и `name` из
  `config('sconcur')`. Около 50 мс, зато источник конфига у всех подкоманд один.
  Мастером они себя не помечают.
- Подготовка перед fork: `Sconcur::beforeFork(Closure $callback)` кладёт колбэк в
  static-массив (регистрация из провайдера приложения, во время загрузки), `start()` вызывает
  их через `$app->call($callback)`, то есть с внедрением зависимостей по типам. После
  колбэков пакет сам делает `DB::disconnect()` для всех соединений и сбрасывает соединения
  Redis.
- Уходят artisan-команды `sconcur:servers:master:start|stop|status|reload` и
  `sconcur:servers:{http,rabbitmq,ws}:start`, `sconcur:tasks:start`. Остаются команды
  приложения: `sconcur:tasks:stop|restart`, `sconcur:rabbitmq:declare`,
  `sconcur:extension:*`.
- Найденное при проверке 2026-10-26 и снятое новой схемой: `artisan` как worker script с
  bootstrap (повторный `define('LARAVEL_START')`, `require_once bootstrap/app.php` вернул бы
  `true`) и срыв reload группы `tasks` (замена упиралась в flock, пока старый пул жив) —
  reload больше нет.

## Без reload

Нового кода без перезапуска мастер не берёт. Деплой — это перезапуск: SIGTERM, дренаж,
выход, новый старт с загрузкой приложения. Пока новый мастер грузится, порт никто не
слушает; с nginx перед пулом это повтор запроса при ошибке соединения.

Позже, если понадобится:

- смена числа воркеров группы без нового кода — дофорковать или остановить лишних;
- reload через `execve` самого мастера: он заменяет себя новым образом, остаётся родителем
  старых воркеров (pid тот же) и по одному меняет их на форки нового. Был шагом 6
  исходного плана.

## Защита мастера

Каждый перезапуск воркера — fork из мастера, поэтому мастер должен оставаться пригодным для
fork всю жизнь, а не только во время загрузки. Если в нём хоть раз запустится рантайм
расширения, все следующие fork невозможны и пул перестаёт восстанавливаться. Реальный путь
к этому — код приложения в мастере, например слушатель события `WorkerWatchdogTriggered`
пакета, который зовёт `Cache::` или `DB::` на драйверах sconcur.

Поэтому:

- `WorkerMaster::run()` первым делом помечает процесс в расширении как мастер. С этого
  момента любой `push` и всё, что запускает рантайм, падает сразу с причиной («в процессе
  мастера асинхронные вызовы запрещены»), а не ломает fork через час.
- Проверка `assertForkable()` (команда `cfk`) остаётся перед каждым fork.
- Ребёнок пометку снимает в `ForkedWorker::prepare()`.

## Что воркер получает от мастера

Копию всей памяти мастера: приложение, конфиг, замыкание роли. Это копия: изменения после
fork ни в одну сторону не видны, общение между воркерами — по-прежнему Redis, AMQP или
общая память из [shared-memory.md](shared-memory.md).

Что готовит `ForkedWorker::prepare()`, как сейчас для ребёнка fork-сервера:

- дескрипторы мастера (lock, журнал, сокеты телеметрии, пайпы соседей, слушающие сокеты
  других групп) — на `/dev/null`; дескрипторы, открытые приложением до старта мастера, —
  оставить (снимок `fds` на входе в `run()`);
- stdout и stderr — на пайпы, которые читает мастер, stdin — `/dev/null`;
- обработчики сигналов — PHP-шные, генераторы случайных чисел — пересеять, объекты мастера
  помечены уничтоженными, у расширения свой хендл (`Extension::forgetInherited()`);
- обработчики ошибок и исключений приложения — вернуть (мастер держит свой на время цикла);
- heartbeat — свой, от расширения.

Известная мелочь: Laravel при загрузке открывает копию stdout (`CliDumper` из
`FoundationServiceProvider`). Она попадает в снимок как дескриптор приложения; безвредна,
но её стоит закрыть в ребёнке.

## Правила для приложения

То же, что сейчас для bootstrap, но для всего приложения, загруженного до мастера:

- ничего асинхронного при загрузке: рантайм в мастере делает fork невозможным;
- никаких соединений при загрузке: fork делит сокет, а не копирует соединение. Проверено на
  PDO: один `connection_id` у двух воркеров, `Wrong COM_STMT_PREPARE response size`;
  `DB::disconnect()` в конце загрузки это снимает;
- асинхронность в мастере запрещена навсегда, не только на загрузке (см. «Защита
  мастера»);
- значения, вычисленные при загрузке, одинаковы во всех воркерах (id, метки времени);
  случайные генераторы PHP пересеваются;
- деплой на месте с перезапуском воркеров без перезапуска мастера смешивает версии: старый
  код из памяти мастера и новые файлы для классов, которые мастер ещё не загрузил. Правило:
  деплой = перезапуск мастера.

## Что теряем

- reload нового кода (см. «Без reload»);
- свои `phpBinary`, `phpArgs` и `php.ini` у группы: все воркеры наследуют настройки
  мастера; группе с другим приложением нужен свой мастер;
- launch `exec` (worker script как отдельный `php`). Расширения, которые запускают потоки
  при старте `php` (часть APM-агентов), fork делают невозможным, и такие расширения не
  поддерживаются (решение 2026-10-26): мастер с ними отказывается стартовать с причиной.

## Опись исходной ветки

По коммитам `master..feature/fork-master` (67 коммитов, последний `d15433a`).

Берём:

- экспорт `process` и фасад `ProcessControl` (`ee1244e` и правки после ревью: `c14c185`,
  `32c09d5`), кроме канала событий fork-сервера (`evq`/`evw`, `events.rs`, часть `claim.rs`);
- отказ от pcntl и posix: сигналы остановки и проверка сироты из расширения, `StopRequest`
  (`f540a4a`), сигналы мастера (`e2aac66`), `MissingPcntlException` удалён;
- ядро после fork: состояние фич в `Core`, атомарный `CORE`, журнал мимо stdout-лока,
  teardown без сборки ядра (`dd762cb`), пулы sql/redis/amqp в `Core`;
- heartbeat у расширения (`d029a2f`): захват пайпа на MINIT, `hbq`/`hbw`,
  `HeartbeatMarkEnum`; класс `Heartbeat` удалён;
- гигиена дескрипторов: close-on-exec для копий `php://fd` (`32c09d5`, `f564955`), stdio и
  ничего больше для программ, запущенных воркером (`c940d21`);
- `ForkedWorker`, `ForkedWorkerProcess`, свой хендл расширения у ребёнка (`fe2f242`),
  снимок дескрипторов приложения и возврат его обработчиков (`0e972b9`), `ExecWorkerProcess`
  как класс запуска процесса (нужен и для запасного пути exec, если он будет);
- в `WorkerGroup`: запуск fork, бэкофф, общий для групп, типизированный учёт слотов,
  `DrainingWorker` для перезапуска по `maxRequests` с заменой заранее, разброс лимитов
  (`63c21b1`, часть про `maxRequests`), отметка `Recycling` в `Scheduler`;
- `markReady` и отметки готовности серверов в `Scheduler` (`SchedulerServeMarksTest`);
- `listenFd` у трёх серверов и `bnd`/`cls` в `process` (`48f2775`) — как возможность
  сервера принять готовый сокет (активация сокетом systemd); без потока приёма с
  `EPOLLEXCLUSIVE`, см. решения ниже;
- журнал доступа в файл дня (`b832ecd`, `accessLog: stdout|file|off`);
- фикс PostgreSQL (`586c940`), аудит доков и устаревших комментариев (`6d79efc`, `7c12ffd`,
  `da32e20`) — кроме `worker-master(.ru).md`, который переписывается;
- `private` → `protected` в тестах (`073c57c`), комментарий release.yml (`095b61b`),
  `tests/impl/FreePort.php`, `tests/impl/PhpScript.php`, удаление `scripts/version.php`
  (`d15433a`);
- тесты: `ProcessControlTest`, `ExtensionForkTest`, `ForkedWorkerTest`, `HeartbeatTest`,
  `WorkerDescriptorsTest`, `HttpServerListenFdTest`, `SchedulerServeMarksTest`, тесты
  PostgreSQL и MySQL из `586c940`.

Выбрасываем:

- fork-сервер: `ForkServer`, `ForkServerGroup`, `ForkServerGeneration`, `ForkServerProcess`,
  `SupervisedGroup` (интерфейс для двух видов групп), `bin/sconcur-fork-server`, канал
  событий в расширении, `ForkServerGroupTest`;
- `bootstrap` у группы и всё вокруг: `BootstrapWorkerMasterTest`,
  `tests/servers/worker/bootstrap-*.php`, `tests/benchmarks/worker/bootstrap.php`;
- reload: `MasterReloadFile`, `MasterReloadRequest`, `SlotMove`, `SlotMovePhaseEnum`,
  откаты и `retireDrainedPools` в мастере, `reload` в `MasterCli`, `MasterReloadFileTest`,
  проверка неизменности `listen` при reload;
- `listen` у группы и поток приёма с `EPOLLEXCLUSIVE` (`bbcfc62`, `53951fd`,
  `exclusive.rs`), стенд `SHARED_SOCKET` (`1157224`) и раздел `shared-socket` в bench-docs;
- `launch` и `LaunchMode` — в шаге 3, вместе с worker script; до этого exec остаётся, а
  fork форкает из самого мастера;
- стенды и soak исходной ветки (`tests/benchmarks/worker/`, `tests/mem-leak/worker-*.php`,
  `make bench-worker-memory*`, `make mem-leak-worker*`, `LAUNCH=fork` в load-stats,
  разделы `worker-memory*` в bench-docs) — переносятся в шаге 6 под новую схему;
- планы `fork-master/fork-master.md`, `fork-master-review.md`, `fork-master-release.md` —
  остаются в исходной ветке; в этот файл перенесено нужное.


## Порядок работ

1. Этот план — коммит в `feature/fork-master`.
2. Ветка `feature/fork-master-v2` от `feature/fork-master`, `git reset --soft` на
   merge-base с `master`: всё изменённое оказывается в индексе. Из него остаётся то, что в
   описи помечено «берём», остальное возвращается к `master` или удаляется. Мастер в
   конце шага — нынешний `WorkerMaster` без fork-сервера и reload: группы форкают воркеров
   из самого мастера, как в `86ceaea`, со всеми позднейшими исправлениями; exec и worker
   script пока остаются. В конце шага `make check` зелёный. Доки (`worker-master` и
   ссылки на reload, bootstrap и `listen` в остальных) в этом шаге не переписываются —
   это шаг 5, чтобы не писать их дважды.
3. Новая схема в библиотеке: роли вместо worker script, конфиг объектами,
   `WorkerMaster::run($config, $roles)`, защита мастера в расширении, снимок дескрипторов
   приложения на входе в `run()`. Тесты на каждое: роль вызвана в ребёнке, ребёнок не
   возвращается в кадры мастера, асинхронный вызов в мастере отказывает, перезапуск
   воркера — fork без повторной загрузки.
4. ~~`bin/sconcur-server` и JSON-конфиг~~ — удалены в шаге 3.
5. Доки EN и RU: `worker-master` заново, правки в `http-server`, `socket-server`,
   `websocket-server`, `amqp`, `adding-a-server`, README.
6. Замеры памяти и soak на новой схеме: стенды из исходной ветки, переделанные под роли.
7. Пакет Laravel: бинарник, `beforeFork`, удаление artisan-команд мастера и серверов,
   переход на 0.17.0 (убрать msgpack из Dockerfile и `config.platform`, прогнать тесты
   пакета).
8. Релиз 0.17.0.

## Решения

2026-10-26:

- Расширения с потоками (APM) не поддерживаются: мастер с ними отказывается стартовать
  (`assertForkable` на входе в `run()`), запасного пути через exec нет.
- Только PHP-API: `bin/sconcur-server` и JSON-конфиг удалены.
- `listen` у группы и поток приёма с `EPOLLEXCLUSIVE` выброшены: против `reusePort` они
  давали ±2% rps и +10% CPU при соединении на запрос. `listenFd` у серверов остаётся.
- Журнал доступа в файл дня остаётся.
- Стенды и soak переносятся в шаге 6, под новую схему.

## Открытые вопросы

1. Мягкая остановка keep-alive соединений при остановке воркера (`Connection: close`,
   `graceful_shutdown` hyper, `tcp_migrate_req`) — отложена 2026-10-26, к схеме не
   относится.
