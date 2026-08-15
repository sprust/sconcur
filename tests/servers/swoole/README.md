# Swoole reference server

A reference server on [Swoole](https://swoole.com) 6.2.2 carrying copies of the
SConcur server's benchmark routes (`tests/servers/http/http-server.php`) on
native drivers. It is the second reference next to
[RoadRunner](../roadrunner/README.md): the same backends and the same work, but a
different execution model — a coroutine worker holds many requests at once, and
blocking drivers become non-blocking through the runtime hooks.

- `GET /` — 200 `ok`;
- `GET /db?n={q}` — `{q}` sequential point SELECTs against MySQL through `PDO`
  (1 by default) — the worker-count ladder;
- `GET /db-rw` — an `INSERT`, a `COUNT(*)` and a point SELECT of a random id
  within that count, JSON `{count, record}`;
- `GET /all` — MongoDB `insertOne`+`findOne` (`mongodb/mongodb`), MySQL
  `INSERT`+`SELECT 1` (`PDO`), PostgreSQL `INSERT`+`SELECT 1` (`PDO`),
  sequentially within the request; the same JSON status map with per-feature
  error isolation;
- `GET /all-coro` — the same three features fanned out in a
  `Swoole\Coroutine\WaitGroup` — Swoole's own answer to the SConcur fan-out.

The backends, the `.env`, and the table/collection names (`load_all`,
`bench_seed`, `bench_rw`) are the same as for the SConcur routes; only the driver
stack and the execution model differ. This is what the comparison in
[docs/benchmarks.md](../../../docs/benchmarks.md) ("Comparison with RoadRunner
and Swoole") measures.

## What matters about the model

- The hooks (`hook_flags => SWOOLE_HOOK_ALL`) put MySQL/PostgreSQL `PDO`, curl,
  streams and `sleep` into coroutine mode: a request waiting on the database
  hands the worker to other requests.
- `ext-mongodb` is not covered by the hooks — libmongoc goes to the network from
  C, past the PHP streams. Any MongoDB call blocks the whole worker for the
  duration of the operation. That is a property of the model, not of the handler,
  and it shows in the `/all` rows.
- A `PDO` connection cannot be shared between concurrent coroutines, so both SQL
  backends go through a per-worker pool (`Swoole\Database\PDOPool`) — the direct
  counterpart of `maxOpenConns` in the SConcur features. The sizes mirror
  SConcur: 9 for `/db*`, 5 for `/all`.
- There is no `/all-sconcur` route here, unlike RoadRunner: SConcur is built on
  PHP Fibers and its own scheduler, while Swoole's coroutines manage the stack
  themselves — two schedulers do not coexist in one process.

## Running

The `swoole` extension is built with the `php` container (`make build`) but
deliberately not enabled globally: the tests and the other benchmarks must run on
stock PHP. It is therefore loaded per run (`-d extension=swoole.so`).

```shell
make swoole-serve                                      # 0.0.0.0:18082, 16 workers
make swoole-serve SWOOLE_HTTP_PORT=18083 SWOOLE_NUM_WORKERS=8
```

Check with `curl http://<container-ip>:18082/all` (and `/all-coro`).

The default port is 18082: 18080 is taken by the SConcur pool in
`http-load-stats.sh` and 18081 by RoadRunner, so all three stacks can stay up at
once.

## Measurements

```shell
make bench-swoole-load-stats          # /all, the resource ladder, as for RR and SConcur
make bench-swoole-coro-load-stats     # /all-coro — the in-request fan-out
make bench-swoole-load-stats-empty    # / — the empty route
make bench-swoole-load-soak           # a long run with the RSS trend
```

Tuning through env — `WORKERS`, `CONNECTIONS`, `DURATION`, `DB_POOL_SIZE`,
`ALL_POOL_SIZE` (see the header of `tests/benchmarks/swoole-load-stats.sh`).
