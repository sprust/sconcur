[English](bench-catalog.md) | Русский

# Каталог бенчмарков

Все бенчмарки, soak-тесты и инструменты профилирования репозитория, разложенные так же,
как лежат файлы, с командой запуска и тем, для чего каждый нужен. Числа, которые они
дают, — в [бенчмарках](benchmarks.ru.md) и [нагрузочном тестировании](load-testing.ru.md);
как читать soak — в [.ai/soak-analysis.md](../.ai/soak-analysis.md).

## Перед запуском

- `make up` поднимает контейнер `php`, бэкенды (MongoDB, MySQL, PostgreSQL, Redis,
  RabbitMQ) и контейнер `servers`. `make ext-build` собирает расширение.
- Большинство целей выполняется в контейнере `php` через `docker compose exec`.
  Нагрузочные стенды (`*.sh` в `http/`, `ext/bench/ladder.sh`, `docs/run.py`) запускаются
  на хосте, и там нужен `wrk` (`apt-get install wrk`); стенд WebSocket несёт свой
  генератор, и на хосте ему ничего не нужно.
- Клиентские и серверные бенчмарки `http/`, `socket/` и `ws/` ходят в работающий пул
  `servers` (`make servers-restart` пересобирает его, `make servers-status` показывает).
- Пулы, которые поднимают нагрузочные стенды, работают так же, как в продакшене: мастер
  воркеров загружает то, на чём строится демо-сервер (`tests/servers/preload.php`), и
  форкает воркеров (`tests/benchmarks/lib/master-pool.sh`).
- `make bench-reset` перед серией замеров удаляет тома с данными бэкендов и создаёт их
  заново: данные лежат на диске, и записи копятся от прогона к прогону.
- `SCONCUR_EXT=<путь к .so>` направляет любую цель на другую сборку расширения.

### Бенчмарки фич

Скрипты по одной операции работают на одном стенде (`lib/benchmarker.php`) и печатают
три режима рядом: native (обычный драйвер или функция PHP), sync (фича SConcur вне
`WaitGroup`) и async (те же вызовы веером в `WaitGroup`). Они принимают
`c="<вызовы> <лог>"`: число вызовов на режим (по умолчанию 5) и `1`, чтобы печатать
каждый вызов. Скрипты баз данных работают с засеянным набором из 100 000 строк
(`SCONCUR_BENCH_DATASET`, его выставляет `db/runs.sh`), у каждого режима свой диапазон id.

## Дерево

```
tests/benchmarks/
├── lib/                              общий стенд, сам по себе не запускается
│   ├── benchmarker.php               запуск native / sync / async для бенчмарков фич
│   ├── payload.php                   размер полезной нагрузки из SCONCUR_BENCH_PAYLOAD_BYTES
│   ├── http-bench.php                доступ к HTTP-пулу `servers`, конкурентные запросы
│   ├── socket-bench.php              то же для socket-пула, кадры с префиксом длины
│   ├── ws-bench.php                  то же для WebSocket-пула, рукопожатие и кадры
│   ├── master-pool.sh                поднимает пул демо-серверов под мастером воркеров
│   └── excimer-profile.php           сэмплер стека PHP за `make profile-php`
│
├── runtime/                          планировщик и граница PHP↔расширение, без бэкендов
│   ├── sleeper.php                   make bench-sleeper c="<вызовы> <лог>"
│   │                                 сны по 1 мс: Sleeper вне WaitGroup и внутри неё
│   ├── fan-out.php                   make bench-fan-out
│   │                                 цена члена широкого веера по обе стороны буфера на
│   │                                 1024 результата, путь обратного давления
│   ├── nested-fan-out.php            make bench-nested-fan-out
│   │                                 цена члена WaitGroup, созданной в корутине
│   ├── coordination-profile.php      make bench-coordination
│   │                                 на что уходит время в suspend → push → waitAny → resume
│   ├── boundary-profile.php          php -d extension=ext/build/sconcur.so <скрипт>
│   │                                 цена одного результата, взятого через границу
│   ├── push-profile.php              make bench-push
│   │                                 цена одной задачи, отправленной через границу
│   ├── request-profile.php           make bench-request
│   │                                 сборка PSR-7 на каждый запрос, по этапам
│   └── teardown-profile.php          make bench-teardown
│                                     снос корутины при растущем числе живых соседей
│
├── mongodb/                          make bench-mongodb-<op> c="<вызовы> <лог>"
│   ├── insert-one.php                insertOne       в засеянную коллекцию
│   ├── insert-many.php               insertMany      пачка в засеянную коллекцию
│   ├── find-one.php                  findOne         по _id, свой документ на вызов
│   ├── update-one.php                updateOne       по _id, свой документ на вызов
│   ├── update-many.php               updateMany      весь набор за вызов: мало вызовов
│   ├── delete-one.php                deleteOne       по _id, настоящее удаление на вызов
│   ├── bulk-write.php                bulkWrite       неиндексированные фильтры, скан: мало вызовов
│   ├── aggregate.php                 aggregate       $match + $limit 30
│   ├── count.php                     count           весь набор
│   ├── command.php                   command         команда сервера
│   ├── create-index.php              createIndex     полный скан; без прогрева (лимит 64 индекса)
│   ├── payload-write.php             make bench-mongodb-payloadWrite c=… p=<байты>
│   └── payload-read.php              make bench-mongodb-payloadRead c=… p=<байты>
│                                     один документ в p байт: путь передачи и декодирования
│
├── mysql/                            make bench-mysql-<op> c="<вызовы> <лог>"
│   ├── insert.php                    insert          в засеянную таблицу
│   ├── select-one.php                selectOne       своя строка на вызов, по id
│   ├── select-many.php               selectMany      скользящее окно в 100 строк
│   ├── update.php                    update          своя строка на вызов
│   ├── delete.php                    delete          своя строка на вызов, настоящий commit
│   ├── count.php                     count           засеянная таблица
│   ├── transaction.php               transaction     begin → insert → commit
│   ├── payload-write.php             make bench-mysql-payloadWrite c=… p=<байты>
│   └── payload-read.php              make bench-mysql-payloadRead c=… p=<байты>
│
├── pgsql/                            make bench-pgsql-<op> c="<вызовы> <лог>"
│   └── …                             те же девять скриптов, что в mysql/
│
├── db/                               целая сессия с базой, а не одна операция
│   ├── lifecycle.php                 make bench-db-lifecycle c=<строки> runs=<n> pool=<размер>
│   │                                 create → seed → read N → delete N → drop, по каждой базе
│   ├── runs.sh                       make bench-db-runs  (RUNS=5 DATASET=100000)
│   │                                 каждый бенчмарк баз RUNS раз на холодном наборе — для
│   │                                 таблиц benchmarks.ru.md
│   └── runs-aggregate.php            используется runs.sh: CSV в строки медиана / min / max
│
├── redis/                            make bench-redis-<op> c="<вызовы> <лог> [<арг>]"
│   ├── get.php                       GET
│   ├── set.php                       SET
│   ├── pipeline.php                  пачка разом против тех же команд по одной
│   ├── value-size.php                GET значения в <арг> байт: где копирование перевешивает
│   │                                 конкурентность
│   └── pool-size.php                 асинхронный GET на пуле из <арг> соединений
│
├── amqp/                             make bench-amqp-<op> c="<вызовы> <лог>"
│   ├── publish.php                   одна публикация на вызов в очередь режима
│   ├── get.php                       один basic.get на вызов из засеянной очереди
│   ├── consume.php                   c="<консьюмеры> <сообщений на каждого>": несколько
│   │                                 очередей разом
│   └── lib/amqp-bench.php            очереди и засев для всех трёх
│
├── files/                            make bench-files-<op> c="<вызовы> <лог>" bytes=<n>
│   ├── read.php                      файл целиком: file_get_contents против Files
│   ├── write.php                     файл целиком: file_put_contents против Files
│   ├── copy.php                      copy() против копирования внутри расширения
│   ├── hash-file.php                 hash_file против фичи
│   ├── read-stream.php               fread в цикле против readChunks; смотреть на память
│   ├── list-dir.php                  entries=<n>: scandir + filesize + filemtime
│   └── lib.php                       временный каталог и засеянные файлы на режим
│
├── http/
│   ├── client.php                    make bench-http-client c="<вызовы> <лог>"
│   │                                 запросы к /msleep пула `servers`
│   ├── client-download.php           make bench-http-client-download
│   │                                 загрузки по 4 MiB в файлы, путь sink
│   ├── client-external.php           make bench-http-client-external
│   │                                 настоящий хост в интернете (BENCH_URL): смоук-тест
│   ├── server-io.php                 make bench-http-server-io
│   │                                 100 конкурентных асинхронных снов на пуле `servers`
│   ├── server-cpu.php                make bench-http-server-cpu
│   │                                 100 конкурентных CPU-циклов, разложенных по пулу
│   ├── throughput.sh                 make bench-http-throughput  (хост, wrk)
│   │                                 воркер на ядро на SO_REUSEPORT, rps по keep-alive
│   ├── load-stats.sh                 make bench-http-load-stats  (хост, wrk)
│   │                                 /all под нагрузкой с замерами CPU, памяти и RSS воркеров;
│   │                                 ROUTE, SERVERS, PIN_SERVERS, DISTINCT_PORTS, WORKER_LOGS
│   │   ├── make bench-http-load-stats-empty   ROUTE=/ и PIN_SERVERS=group: потолок
│   │   ├── make bench-http-load-stats-big     ROUTE=/big/$(BODY_BYTES): цена тела ответа
│   │   └── make bench-http-load-soak          MODE=soak: 10 мин, тренд и наклон RSS
│   ├── rr-load-stats.sh              make bench-rr-load-stats[-empty|-soak]  (хост, wrk)
│   │                                 тот же стенд против RoadRunner на нативных драйверах;
│   │                                 make bench-rr-sconcur-load-stats: SConcur в его воркерах;
│   │                                 make rr-serve: только сервер, на переднем плане
│   ├── swoole-load-stats.sh          make bench-swoole-load-stats[-empty|-soak]  (хост, wrk)
│   │                                 то же против Swoole; make bench-swoole-coro-load-stats:
│   │                                 его собственный веер корутин; make swoole-serve: только
│   │                                 сервер, на переднем плане
│   ├── nginx-proxy.sh                nginx перед пулом с DISTINCT_PORTS, балансировка на
│   │                                 каждый запрос (эксперимент с диспетчеризацией,
│   │                                 bench-docs --proxy)
│   └── mixed-profile.lua             WRK_SCRIPT: в основном дешёвые запросы, немного тяжёлых
│
├── socket/
│   ├── client.php                    make bench-socket-client c="<вызовы> <лог>"
│   ├── throughput.php                make bench-socket-throughput: ping-обмены в секунду
│   ├── server-io.php                 make bench-socket-server-io
│   └── server-cpu.php                make bench-socket-server-cpu
│
├── ws/
│   ├── client.php                    make bench-ws-client c="<вызовы> <лог>"
│   ├── throughput.php                make bench-ws-throughput: ping-обмены в секунду
│   ├── server-io.php                 make bench-ws-server-io
│   ├── server-cpu.php                make bench-ws-server-cpu
│   ├── load-stats.sh                 make bench-ws-load-stats[-empty|-soak]
│   │                                 сообщение "all" под нагрузкой, воркер на ядро, с
│   │                                 замерами CPU, памяти и RSS воркеров
│   └── ws-load/                      генератор нагрузки WebSocket (Rust), его собирает load-stats.sh
│
├── worker/                           сколько памяти стоит пул воркеров
│   ├── memory.php                    make bench-worker-memory  (WORKERS, REQUESTS, ARMS)
│   │                                 exec (свой php на воркер), fork, preload (мастер загрузил
│   │                                 приложение заранее): Pss, Rss, Private_Dirty, куча
│   │   └── make bench-worker-memory-soak      15 мин на плечо, без OPcache и с ним
│   ├── master.php                    мастер плеч fork и preload
│   ├── app.php                       подставное приложение: загруженный classmap Composer
│   └── app-worker.php                воркер: /work, /memory
│
└── docs/
    └── run.py                        make bench-docs [f="--<раздел> …"]  (хост)
                                      все замеры, которые цитирует документация, за одну
                                      сессию, в .bench-runs/docs/<время>/; f=--list — разделы

tests/mem-leak/                       soak: один сценарий в цикле, что остаётся после него
├── long-flow-soak.php                make mem-leak-long-flow scenario=<имя> seconds=<n>
│                                     потоки, открытые корутиной, которая не заканчивается
├── redis-soak.php                    make mem-leak-redis scenario=<имя> seconds=<n>
├── amqp-soak.php                     make mem-leak-amqp scenario=<имя> seconds=<n>
├── files-soak.php                    make mem-leak-files scenario=<имя> seconds=<n>
├── worker-soak.php                   make mem-leak-worker scenario=<список> seconds=<n> launch=…
│                                     мастер воркеров под отказами: что держат мастер и
│                                     выжившие воркеры, сколько запросов стоит смерть
├── worker-soak-worker.php            его воркер: путь для каждого способа сломать запрос
├── worker-soak-summary.php           make mem-leak-worker-summary file=<сохранённый вывод>
├── worker-exit-timing.php            make mem-leak-worker-exit-timing launch=… seconds=<n>
│                                     смерть через exit() по шагам
└── endless-example.php               корутина, которая не заканчивается, — смотреть вручную

ext/bench/
├── ladder.sh                         make bench-ladder  (хост, wrk; ROUNDS, SERVERS, DURATION,
│                                     RUNGS="l0 l1 l2 l2f"): цена запроса до PHP (l0) и что
│                                     добавляет переход в PHP (l1; l2 с файбером на запрос, l2f
│                                     с отправкой со стека файбера), в мкс CPU на запрос;
│                                     запускает load-stats.sh с --ladder
└── loadclient.py                     второй клиент нагрузки, чтобы проверить числа wrk

профилирование (docker-compose.profiling.yml)
├── make profile-build / profile-up / profile-down   образ с perf и excimer
├── make profile-perf c=<скрипт>      perf по стеку C, файл свёрнутых стеков
├── make profile-php c=<скрипт>       excimer по стеку PHP
├── make profile-perf-check           разрешает ли хост perf
└── make profile-verify               образ не урезан и несёт оба профилировщика
```

`make bench-all` прогоняет бенчмарки фич, клиентов и серверов один за другим;
нагрузочные стенды, soak и профилировщики в него не входят.
