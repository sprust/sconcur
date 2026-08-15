# RoadRunner reference server

A reference server on [RoadRunner](https://roadrunner.dev) carrying copies of two
benchmark routes of the SConcur server (`tests/servers/http/http-server.php`),
but on native drivers and sequentially — a RoadRunner worker has no concurrency
inside a request:

- `GET /` — 200 `ok`;
- `GET /all` — MongoDB `insertOne`+`findOne` (`mongodb/mongodb`), MySQL
  `INSERT`+`SELECT 1` (`PDO`), PostgreSQL `INSERT`+`SELECT 1` (`PDO`); the same
  JSON status map with per-feature error isolation;
- `GET /all-sconcur` — the same three features, but fanned out through SConcur in
  a nested `WaitGroup`: the "SConcur inside a RoadRunner worker" scenario, which
  complements RoadRunner rather than replacing it. Needs the extension loaded in
  the worker (see below); without it the route answers 500 with a hint.

The backends, the `.env`, and the table/collection names (`load_all`) are the
same as for SConcur's own `/all` route — only the driver stack differs. This is
what the comparison in [docs/benchmarks.md](../../../docs/benchmarks.md)
("Comparison with RoadRunner and Swoole") measures.

## Running

The `rr` binary and the `spiral/roadrunner-http` / `spiral/roadrunner-worker`
packages are installed when the `php` container is built (`make build`).

```shell
make rr-serve                                 # 0.0.0.0:18081, 16 workers
RR_HTTP_PORT=18082 RR_NUM_WORKERS=8 make rr-serve

# with the sconcur extension in the workers (required by /all-sconcur)
make rr-serve RR_WORKER_CMD='php -d extension=/sconcur/ext/build/sconcur.so rr-worker.php'
```

Check with `curl http://<container-ip>:18081/all` (and `/all-sconcur` when the
extension is loaded).

The default port is 18081 because the SConcur pool in `http-load-stats.sh` takes
18080, so both stacks can stay up at once.
