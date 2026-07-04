English | [Русский](worker-master.ru.md)

# Worker master

A supervisor that starts and watches over a pool of worker processes running one
script. It is the counterpart to [`SO_REUSEPORT`](http-server.md): the workers bind
one port and the kernel balances connections across them — scaling the HTTP server
across all cores without an external load balancer. The implementation lives in
`src/Worker/` (PHP) and in the universal CLI `bin/sconcur-server`.

> Each worker is a separate process (`pcntl_fork` after loading the extension is
> forbidden — the Go runtime does not survive a fork), and the master starts them via
> `proc_open`. The master itself does not load the extension — it is a pure supervisor
> on `pcntl`/`posix`.

## Table of contents

- [Quick start](#quick-start)
- [Commands: start / status / stop / reload](#commands-start--status--stop--reload)
- [Parameters](#parameters)
- [Supported servers](#supported-servers)
- [Restart policy and `maxRequests`](#restart-policy-and-maxrequests)
- [Self-termination of orphaned workers](#self-termination-of-orphaned-workers)
- [Stuck worker](#stuck-worker)
- [Logging](#logging)
- [Single instance, state and restart from the system](#single-instance-state-and-restart-from-the-system)
- [Graceful shutdown](#graceful-shutdown)
- [Differences from systemd / supervisord](#differences-from-systemd--supervisord)
- [Testing](#testing)

---

## Quick start

The consumer writes only their worker script (the one that builds `HttpServer` and
calls `serve()`); the master is taken ready-made from `bin/`.

`worker.php` (your script):

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SConcur\Features\HttpServer\HttpServer;

require __DIR__ . '/vendor/autoload.php';

$factory = new Psr17Factory();

// fromArgs() assembles HttpServer from argv: the master passes the keys of the
// `server` block here as --key=value flags and its own pid as the --masterPid flag
// (self-termination if the master dies). PSR-17 factories are passed as arguments
// (argv carries scalars only).
$server = HttpServer::fromArgs(
    argv: $_SERVER['argv'],
    serverRequestFactory: $factory,
    responseFactory: $factory,
);

$server->serve(static fn (ServerRequestInterface $request): ResponseInterface =>
    $factory->createResponse(200)->withBody($factory->createStream('ok')));
```

> **`reusePort: true` must be set in the master config's `server` block — otherwise
> the 2nd worker gets `EADDRINUSE`.**

Master config (`/app/master.json`):

```json
{
  "workerScript": "/app/worker.php",
  "workerCount": 8,
  "phpArgs": ["-d", "extension=/app/ext/build/sconcur.so"],
  "runtimeDir": "/run/sconcur",
  "logDir": "/var/log/sconcur",
  "rotateDays": 3,
  "server": {
    "address": "0.0.0.0:8080",
    "reusePort": true,
    "maxRequests": 10000
  }
}
```

Starting the master:

```sh
vendor/bin/sconcur-server start --configPath=/app/master.json
```

`start` blocks (foreground) and supervises the pool until it receives
`SIGTERM`/`SIGINT` or the state file is removed (see
[Graceful shutdown](#graceful-shutdown)).

## Commands: start / status / stop / reload

Every command takes a single flag — `--configPath` with the path to the master's
JSON config; there are no other flags.

```sh
# start — bring up the pool and supervise it (foreground)
vendor/bin/sconcur-server start --configPath=/app/master.json

# status — the master's state (exit 0 = running, 3 = stopped/stale)
vendor/bin/sconcur-server status --configPath=/app/master.json
#   running: pid=12345 workers=8

# stop — remove the state file (the stop signal) and wait for the master to exit
vendor/bin/sconcur-server stop --configPath=/app/master.json

# reload — soft roll of the workers one by one (zero-downtime) and wait for it to finish
vendor/bin/sconcur-server reload --configPath=/app/master.json
```

Exit codes: `start` — the master's exit code; `status` — `0` (running) /
`3` (stopped/stale); `stop` — `0` once the master has stopped, `1` on timeout; `reload`
— `0` once the roll completes, `3` if the master is not running, `1` on timeout/error.

> The same `--configPath` across all commands guarantees consistent
> `runtimeDir`/`name`: `status`/`stop`/`reload` take them from the config and so find
> the lock/state/trigger files.

`reload` is a soft restart of the workers. The command creates the trigger file
`<runtimeDir>/<name>.reload`; the master notices it and rolls the workers one by one:
it sends each `SIGTERM` (which leaves the `SO_REUSEPORT` group early and drains
in-flight), waits for it to exit up to `shutdownTimeoutMs` (otherwise `SIGKILL`), and
brings up a fresh process as a replacement. While one worker drains, the rest hold
traffic — so with N>1 the roll is downtime-free. A fresh `php worker.php` picks up the
new worker-script code from disk — this is the zero-downtime deployment scenario. Once
done, the master removes the trigger file, and the command returns `reloaded`.

> `reload` restarts the worker processes but does not re-read the master config
> (`workerCount`/arguments do not change on the fly). A single-worker pool has a brief
> gap on reload (kill-then-spawn) — zero-downtime is achieved with `N>1`.

Programmatic API (behind the CLI) — the `SConcur\Worker\WorkerMaster` class:

```php
use SConcur\Worker\WorkerMaster;

new WorkerMaster(
    workerScript: __DIR__ . '/worker.php',
    runtimeDir:   '/run/sconcur',
    logDir:       '/var/log/sconcur',
    workerCount:  0, // 0 = number of cores
    phpArgs:      ['-d', 'extension=' . __DIR__ . '/ext/build/sconcur.so'],
    workerArgs:   ['0.0.0.0:8080'],
)->run();
```

## Parameters

The JSON config keys match the `WorkerMaster` constructor parameter names exactly. Any
key left unset takes its default.

The config is validated strictly (an error → exit code `2`): an unknown top-level key
is rejected (protection against typos like `wokerCount`); `name` is restricted to the
set `[A-Za-z0-9._-]` (it is a path component and a rotation glob pattern); `rotateDays`,
`shutdownTimeoutMs`, `restartBackoffMs`, `maxRestartBackoffMs` must be `>= 0`.

| Key | Default | Purpose |
|---|---|---|
| `workerScript` | — (required) | The consumer's worker script. |
| `workerCount` | `0` (= number of cores) | How many workers to bring up. |
| `phpBinary` | current `PHP_BINARY` | Interpreter for the workers. |
| `phpArgs` | `[]` | Interpreter flags (array, e.g. `["-d", "extension=…"]`). |
| `workerArgs` | `[]` | Extra raw worker `argv` flags (array), appended after `server`. |
| `env` | `{}` | Extra worker env (object), merged over the inherited one. |
| `runtimeDir` | temp dir | Directory of the lock and state files (local FS). |
| `logDir` | `runtimeDir` | Log directory. |
| `name` | `sconcur-server` | Prefix for the log and state file names. |
| `rotateDays` | `3` | How many days to keep log files. |
| `logTo` | `file` | Where to write the log: `file` \| `stdout` \| `both` (for `docker logs` — `stdout`/`both`). |
| `restartPolicy` | `always` | `always` \| `on-failure` \| `never`. |
| `shutdownTimeoutMs` | `10000` | How long to wait for workers to drain before `SIGKILL`. |
| `restartBackoffMs` | `200` | Exponential backoff base in a crash loop. |
| `maxRestartBackoffMs` | `30000` | Backoff ceiling. |
| `panelPort` | `0` (off) | Port of the built-in [telemetry panel](admin-stats.md) (`/api/stats`, `/`, `/events`). Needed together with `adminToken`. |
| `adminToken` | empty (off) | Panel Bearer token; needed together with `panelPort`. |
| `server` | `{}` | Server parameters object → expanded into the worker's `argv` (see below). |

The `server` block — the forwarding principle. The master expands it into the
worker's `argv`: each key → a `--key=value` flag (booleans → `1`/`0`); there are no
positional arguments. This way the worker receives its parameters through arguments,
while the operator sets them in one JSON. The master does not inspect or hardcode the
key names — it forwards anything, so the same supervisor fits any worker that parses
`--key=value` argv. A non-scalar value in `server` is a config error (it cannot be
expressed on argv).

On top of the `server` keys the master appends the `--masterPid=<pid>` flag — this is
an ordinary argv flag; how (and whether) to use it is up to the worker script itself
(typically an orphan check: the worker exits if the master has died). There is no need
to specify `masterPid` in `server`.

The specific set of `server` keys is defined by the server itself — see
[Supported servers](#supported-servers).

### Full config (all parameters with their default values)

An annotated reference (JSON does not support comments — strip them in a real file).
Each key is shown with its default; omit the ones you are happy with.

```jsonc
{
  // --- master parameters (WorkerMaster) ---
  "workerScript": "/app/worker.php",   // REQUIRED — no default
  "workerCount": 0,                     // 0 = number of cores (nproc)
  "phpBinary": "/usr/local/bin/php",   // default = current PHP_BINARY
  "phpArgs": ["-d", "extension=/app/ext/build/sconcur.so"], // default []
  "workerArgs": [],                     // extra raw worker argv flags (after server)
  "env": {},                            // extra worker env (merged over the inherited one)
  "runtimeDir": "/run/sconcur",         // default = sys_get_temp_dir()
  "logDir": "/var/log/sconcur",         // default = runtimeDir
  "name": "sconcur-server",        // prefix for the log and state file names
  "rotateDays": 3,                      // how many days to keep logs
  "logTo": "file",                      // file | stdout | both (for docker logs — stdout/both)
  "restartPolicy": "always",            // always | on-failure | never
  "shutdownTimeoutMs": 10000,           // wait for workers to drain before SIGKILL
  "restartBackoffMs": 200,              // backoff base in a crash loop
  "maxRestartBackoffMs": 30000,         // backoff ceiling
  "panelPort": 0,                       // telemetry panel port (0 = off); with adminToken
  "adminToken": "",                     // panel Bearer token (with panelPort)

  // --- server parameters → expanded into worker argv (--key=value) ---
  // The set of keys is defined by the server itself (see "Supported servers");
  // below is an example for the HTTP server.
  "server": {
    "address": "0.0.0.0:8080"           // → to the worker as the --address=0.0.0.0:8080 flag
  }
}
```

## Supported servers

The master is server-agnostic: it only forwards the `server` keys into the worker's
`argv`. Which keys the worker understands is described in the doc of the corresponding
server:

- [HTTP server](http-server.md)
- [Socket server (TCP)](socket-server.md) — the `maxConnections` key instead of
  `maxRequests` (the same meaning of a scheduled stop against leaks); it also supports
  `reusePort` and `--masterPid`.

## Restart policy and `maxRequests`

| Policy | Behaviour |
|---|---|
| `always` (default) | Restart on any exit — clean or abnormal. |
| `on-failure` | Restart only on a non-zero code / death by signal. |
| `never` | One-shot: it ran, and that's it. |

`always` is the default for a reason: the
[`HttpServer(maxRequests: N)`](http-server.md) feature shuts a worker down cleanly,
with code 0 after N requests (a measure against memory leaks). For the master to bring
up a fresh process as a replacement, exactly `always` is needed — with `on-failure` a
clean exit on the limit does not trigger a restart. The worker's early closing of the
listener + `SO_REUSEPORT` give a rolling restart without traffic loss: while one worker
is being recreated, the kernel steers new connections to its neighbours.

Crash-loop protection. A worker that crashes right at startup does not cause a
spawn-crash-spawn spin: per-slot exponential backoff (base `restartBackoffMs`, doubled
on each fast crash, ceiling `maxRestartBackoffMs`). A worker that has lived longer than
~1 s is considered healthy — the backoff is reset.

## Self-termination of orphaned workers

If the master dies suddenly (crash, `SIGKILL`, OOM), the workers become orphaned and
would otherwise keep living, holding the port. To prevent that, the master passes
`HttpServer` its pid via the `--masterPid` flag (which `fromArgs()` supplies). On every
tick of the server loop the server compares `posix_getppid()` against this pid: while
the master is alive it is the worker's parent; after its death the kernel changes the
parent, `getppid()` stops matching (reliably, without susceptibility to PID reuse) → the
server starts its own graceful drain (as on `SIGTERM`), serves out in-flight and exits,
freeing the port. Outside the master there is no flag → `masterPid` is `null` and the
check is disabled.

## Stuck worker

A handler that goes into a native blocking call (`sleep()`, synchronous PDO/`curl`) or
into a CPU-bound loop freezes the worker's single PHP thread — the cooperative model
does not preempt it (see the "Handler timeout" section in the
[HTTP server doc](http-server.md)). `handlerTimeoutMs` on the Go side will return `504`
to clients, but the worker itself will not terminate — it stays `running` and silently
drops out of service.

Such a worker can only be terminated by killing the process:

- `reload` or `stop` — the master sends `SIGTERM`, waits `shutdownTimeoutMs`, then
  escalates to `SIGKILL`; this finishes off even a looping worker (but through a grace
  period). A native `sleep` is usually cleared by `SIGTERM` already (the signal
  interrupts the system call), a CPU loop — only by `SIGKILL`.
- `kill -9 <pid>` by hand → the master sees the death by signal and under `always`
  brings up a fresh worker as a replacement.

`maxRequests` and the orphan check do not help here: a stuck worker does not *finish* a
request (the counter does not grow), and the master is alive.

> Limitation. The master currently does not detect "alive but stuck" — it sees an
> ordinary `running` process and does not touch it until `reload`/`stop`/a manual
> `kill`. Automatic recovery (a heartbeat watchdog from the serve loop → `SIGKILL` +
> respawn when the mark goes stale) is a possible future improvement, see the plans.

## Logging

The master writes the log to a single daily file in `logDir` (with no worker index in
the name):

```
sconcur-server-2026-06-18.log
```

At the day boundary a new file is opened, and files older than `rotateDays` (3 by
default) are deleted. The line format follows the project's logger convention
(timestamp with microseconds, level, scope tag, message, trailing context array):

```
[Y-m-d H:i:s.uuuuuu] LEVEL [<scope>]: <message> [<context>]
```

`<scope>` is `master: <pid>` for master records and `worker: <pid> #<index>` for
records about a worker.

```
[2026-06-18 12:00:00.173957] INFO [master: 12345]: start workers=8 script=/app/worker.php runtimeDir=/run/sconcur []
[2026-06-18 12:00:00.180210] INFO [worker: 12346 #0]: spawned []
[2026-06-18 12:01:00.012044] ERROR [worker: 12346 #0]: exited code=1 uptime=0.3s; restarting in 200ms []
[2026-06-18 12:01:00.020000] ERROR [worker: 12346 #0]: PHP Fatal error: ... []
[2026-06-18 12:05:00.000100] INFO [master: 12345]: shutdown requested; forwarding SIGTERM to workers []
```

The master intercepts the workers' `stdout`/`stderr` and rewrites them into the same
log with the scope `worker: <pid> #<index>` (stderr → `ERROR`, stdout → `INFO`), so the
crash output (and the worker's access log) is preserved in a single format next to the
exit record.

### Where to write the log (`logTo`)

The `logTo` parameter sets the sink for the master's log:

- `file` (default) — only the daily file in `logDir`;
- `stdout` — only to the master's `STDOUT` (collected by `docker logs`/journald);
- `both` — both to the file and to `STDOUT`.

Under `docker logs` you need `stdout` or `both` (otherwise the output is empty — the
master writes only to the file, and the container's stdout is the master's stdout). This
does not affect performance: the master is outside the request hot path, records are
buffered and flushed once per supervision tick (~100 ms), not per line.

## Single instance, state and restart from the system

A single instance — via `flock`. At startup the master takes an exclusive
non-blocking lock on `runtimeDir/<name>.lock`. A second master with the same
`runtimeDir`+`name` gets `MasterAlreadyRunningException` and exits. The kernel releases
the lock automatically on the process's death (even on `SIGKILL`) — there is no
stale-lock problem, unlike with a PID file. The lock file is deliberately not deleted
(an empty leftover is harmless).

The state file `runtimeDir/<name>-state.json` (by default `sconcur-server-state.json`)
is an observable flag: pid, start time, worker count, worker-script path, status. It is
written atomically (temp file + rename); it is removed on a clean exit and remains after
a crash.

The state file is a control channel. On every tick the master checks that it exists;
its removal is the stop signal: the master starts the same graceful drain as on
`SIGTERM` (forwards the signal to workers, waits for in-flight, exits). This is exactly
how the `stop` command works (it simply removes the state file). The removal is logged
at `WARN` level (`state file removed; shutting down gracefully`). A consequence: if a
`/tmp` cleaner wipes the file — the master stops cleanly with all its workers, and an
external supervisor/guard brings it back (who restarts it is outside the master's
responsibility).

`status` and `stop` decide whether the master is alive by the lock, not by the pid from
the state file: they try to take the same `flock` — if that fails, a live master holds
the lock. This is immune to a stale state file and to PID reuse (the kernel releases the
lock only on the owner's death; on a zombie process the lock is already released too).
`stop` takes the pid for the signal from the state, but that is safe: the lock
guarantees that the exact master that wrote this state is the one alive.

Restart from the system. An external guard (cron/timer) brings up the master if it is
not running — most simply via `status` (which checks the lock itself and returns an exit
code):

```sh
# on a timer:
vendor/bin/sconcur-server status --configPath=/app/master.json >/dev/null \
  || vendor/bin/sconcur-server start --configPath=/app/master.json
```

`flock` guards against a race between two guards: if one is already starting the master,
the second gets `MasterAlreadyRunningException` and exits quietly.

> "Always up" vs a deliberate stop. The guard model restarts the master even after a
> deliberate `stop` (no state file → the guard brings it back). If you need the ability
> to deliberately turn it off and not bring it back — use a separate "disabled" marker
> or manage it through systemd.

## Graceful shutdown

The stop triggers are `SIGTERM`/`SIGINT` or removal of the state file (see above). In
either case the master:

1. stops restarting workers;
2. forwards `SIGTERM` to all live workers (each drains its own in-flight — see
   [the HTTP server's graceful](http-server.md));
3. waits for them to exit up to `shutdownTimeoutMs`, finishing off survivors with
   `SIGKILL`;
4. cleans up the state file, releases the lock and exits with code `0`.

`ext-pcntl` and `ext-posix` are required (they are enabled in the project's docker
images); without them the master throws `MissingPcntlException` at startup.

## Differences from systemd / supervisord

An external supervisor is a valid alternative. The built-in master is convenient when
you need to: start a pool "with one command" without external orchestration; have a
single graceful, a common log and a consistent rolling restart within one process tree;
and a tight coupling with the library's features (`maxRequests`, the `masterPid`
guard). Restarting the master itself can be handed off to systemd/a cron guard via
`status`/`stop` (see above) — the models complement each other.

## Testing

The tests do not depend on a docker service: the `SConcur\Tests\Impl\Worker\TestWorkerMaster`
harness runs `bin/sconcur-server` as a separate process on a loopback port, and the
worker is the shared demo server (`tests/servers/http/http-server.php`) with
`reusePort`, `masterPid` and a `/pid` route.

Coverage (`tests/feature/Worker/` + `tests/feature/Features/HttpServer/`):

- Supervision: spawning N workers and serving with all of them; restarting a killed
  one (`always`, including `signal=` from OOM/`SIGKILL`); self-exit on `maxRequests` →
  restart; the `on-failure` (a clean exit does not restart) and `never` policies.
- Stopping: graceful drain of in-flight on `SIGTERM`; the same on removal of the state
  file; all workers go down together with the master.
- Singleness/state: rejection of a second instance (lock); `status`/`stop`; `status`
  after a master crash → `stopped` (by the lock, immune to PID reuse).
- Resilience: throttling a crash loop with backoff; validation (no worker script,
  negative `workerCount`); self-termination of orphaned workers.
- `masterPid` (in isolation, `HttpServerMasterPidTest`): when `masterPid` = the parent,
  the server serves; when it is a foreign one, it shuts down cleanly on its own.
- Logger (unit, `MasterLoggerTest`): the line format, the context JSON, daily rotation
  with `rotateDays` retention.

---

See also: [HTTP server](http-server.md),
[Server admin statistics](admin-stats.md).
