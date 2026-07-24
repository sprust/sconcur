# RoadRunner-эталон для сравнения

Референсный сервер на [RoadRunner](https://roadrunner.dev) с копиями двух
бенчмарк-ручек SConcur-сервера (`tests/servers/http/http-server.php`), но на
нативных драйверах и последовательно (у RoadRunner-воркера нет внутренней
конкурентности):

- `GET /` — 200 `ok`;
- `GET /all` — MongoDB `insertOne`+`findOne` (`mongodb/mongodb`), MySQL
  `INSERT`+`SELECT 1` (`PDO`), PostgreSQL `INSERT`+`SELECT 1` (`PDO`); та же
  JSON-мапа статусов с изоляцией ошибок по фичам;
- `GET /all-sconcur` — те же три фичи, но через SConcur веером во вложенном
  `WaitGroup` — сценарий «SConcur внутри RoadRunner-воркера» (не заменить RR, а
  дополнить). Требует расширение в воркере (см. запуск ниже); без него отвечает
  500 с подсказкой.

Бэкенды, `.env`, имена таблицы/коллекции (`load_all`) — те же, что у
SConcur-ручки `/all`; отличается только стек драйверов. Используется для
честного замера из [docs/benchmarks.ru.md](../../../docs/benchmarks.ru.md)
(раздел «Сравнение с RoadRunner»).

## Запуск

Бинарь `rr` и пакеты `spiral/roadrunner-http`/`spiral/roadrunner-worker`
ставятся при сборке контейнера `php` (`make build`).

```shell
make rr-serve                                 # 0.0.0.0:18081, 16 воркеров
RR_HTTP_PORT=18082 RR_NUM_WORKERS=8 make rr-serve

# с расширением sconcur в воркерах (нужно для /all-sconcur)
make rr-serve RR_WORKER_CMD='php -d extension=/sconcur/ext/build/sconcur.so rr-worker.php'
```

Проверка: `curl http://<ip-контейнера>:18081/all` (и `/all-sconcur`, если
расширение загружено).

Порт по умолчанию 18081 — пул SConcur в `http-load-stats.sh` занимает 18080,
так что оба стека можно держать поднятыми одновременно.
