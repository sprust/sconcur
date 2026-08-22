English | [Русский](worker-master.ru.md)

# Worker master

A supervisor that starts and watches over a pool of worker processes running one
script. It is the counterpart to [`SO_REUSEPORT`](http-server.md): the workers bind
one port and the kernel balances connections across them, scaling the server across
all cores without an external load balancer. Implementation: `src/Worker/` plus the
universal CLI `bin/sconcur-server`.

Each worker is a separate process started via `proc_open` (`pcntl_fork` after
loading the extension is forbidden — the Go runtime does not survive a fork). The
master itself does not load the extension: it is a pure supervisor on
`pcntl`/`posix` (both are required, otherwise it throws `MissingPcntlException`).

## Table of contents

- [Quick start](#quick-start)
- [Commands](#commands)
- [Parameters](#parameters)
- [Restart policy and `maxRequests`](#restart-policy-and-maxrequests)
- [Self-termination of orphaned workers](#self-termination-of-orphaned-workers)
- [Stuck worker](#stuck-worker)
- [Logging](#logging)
- [Single instance and state](#single-instance-and-state)
- [Graceful shutdown](#graceful-shutdown)
- [Testing](#testing)

## Quick start

You write only the worker script; the master is taken ready-made from `bin/`.

```php
// worker.php
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SConcur\Features\HttpServer\HttpServer;

require __DIR__ . '/vendor/autoload.php';

$factory = new Psr17Factory();

// fromArgs() assembles the server from argv: the master passes the keys of the
// `server` block as --key=value flags and its own pid as --masterPid.
$server = HttpServer::fromArgs(
    argv: $_SERVER['argv'],
    serverRequestFactory: $factory,
    responseFactory: $factory,
);

$server->serve(static fn (ServerRequestInterface $request): ResponseInterface =>
    $factory->createResponse(200)->withBody($factory->createStream('ok')));
```

> **`reusePort: true` must be set in the config's `server` block — otherwise the
> 2nd worker gets `EADDRINUSE`.**

```json
{
  "phpArgs": ["-d", "extension=/app/ext/build/sconcur.so"],
  "runtimeDir": "/run/sconcur",
  "logDir": "/var/log/sconcur",
  "rotateDays": 3,
  "groups": [
    {
      "name": "http",
      "workerScript": "/app/worker.php",
      "workerCount": 8,
      "server": {
        "address": "0.0.0.0:8080",
        "reusePort": true,
        "maxRequests": 10000
      }
    }
  ]
}
```

A master supervises one or more **groups**, and they need not be alike. One
supervisor, one lock, one journal and one panel can hold an HTTP pool and a
couple of queue-consumer pools at once:

```json
{
  "phpArgs": ["-d", "extension=/app/ext/build/sconcur.so"],
  "runtimeDir": "/run/sconcur",
  "groups": [
    {
      "name": "http",
      "workerScript": "/app/worker.php",
      "workerCount": 8,
      "server": { "address": "0.0.0.0:8080", "reusePort": true }
    },
    {
      "name": "orders",
      "workerScript": "/app/consumer.php",
      "workerCount": 2,
      "server": {
        "queues": [{ "name": "orders", "coroutineCount": 8 }]
      }
    }
  ]
}
```

```sh
vendor/bin/sconcur-server start --configPath=/app/master.json
```

`start` blocks in the foreground and supervises the pool until `SIGTERM`/`SIGINT`,
until the state file is removed, or until every worker has finished with nothing
left to restart.

The programmatic API behind the CLI is `SConcur\Worker\WorkerMaster`:

```php
MasterConfig::fromFile('/app/master.json')->toWorkerMaster()->run();
```

`WorkerMaster` itself takes its groups as `WorkerGroupConfig` objects, which is
what `MasterConfig` builds from the file.

## Commands

Every command takes a single flag — `--configPath`. The same config across all
commands guarantees consistent `runtimeDir`/`name`, so `status`/`stop`/`reload`
find the lock, state and trigger files.

```sh
vendor/bin/sconcur-server start   --configPath=/app/master.json  # bring up the pool (foreground)
vendor/bin/sconcur-server status  --configPath=/app/master.json  # running: pid=12345 workers=8 groups=2
vendor/bin/sconcur-server stop    --configPath=/app/master.json  # remove the state file and wait for exit
vendor/bin/sconcur-server reload  --configPath=/app/master.json  # roll the workers one by one
```

Exit codes: `start` — the master's own; `status` — `0` running / `3`
stopped-or-stale; `stop` — `0` once stopped (or none was running), `1` on timeout;
`reload` — `0` on completion, `3` if the master is not running, `1` on
timeout/error.

`status` prints the totals and then one line per group.

`reload` re-reads the config and rolls the workers onto it. The command creates
the trigger file `<runtimeDir>/<name>.reload` naming the config; the master reads
it, then rolls each group's workers one by one, sending each `SIGTERM` (which
leaves the `SO_REUSEPORT` group early and finishes the requests it has already
accepted), waiting up to `shutdownTimeoutMs` (otherwise `SIGKILL`) and spawning a
replacement. While one worker finishes up, the rest hold traffic — so with N>1
the restart is downtime-free, and a fresh `php worker.php` picks up new code from
disk.

What a reload may change: the set of groups (one added is spawned, one removed is
drained and not replaced), and each group's `workerCount`, `workerScript`,
`workerArgs`, `server`, `env`, `phpBinary`, `phpArgs`, `restartPolicy`, timeouts
and backoffs. Scaling a pool is therefore an edit plus a `reload`, not a restart.

What it may not: `runtimeDir` and `name` identify the running instance, and
`panelPort`, `adminToken` and `logDir` are bound at startup. Changing them is
logged as ignored and needs a full restart.

**A config that does not parse is refused and the master keeps running on the one
it has.** A typo must never take a working pool down; the refusal and its reason
go to the journal.

## Parameters

The config is validated strictly (an error → exit code `2`): an unknown key is
rejected at either level (protection against typos like `wokerCount`), `name` is
restricted to `[A-Za-z0-9._-]` at both levels (it is a path component, a rotation
glob and a `--group` value), and every timeout, count and day retention must be
`>= 0`.

### The master

| Key | Default | Purpose |
|---|---|---|
| `groups` | — (required) | The pools to supervise; at least one. |
| `runtimeDir` | temp dir | Directory of the lock and state files (local FS). |
| `logDir` | `runtimeDir` | Log directory. |
| `name` | `sconcur-server` | Prefix for the log and state file names. |
| `rotateDays` | `3` | How many days to keep log files. |
| `logTo` | `file` | `file` \| `stdout` \| `both` (for `docker logs` — `stdout`/`both`). |
| `panelPort` | `0` (off) | Port of the built-in [telemetry panel](admin-stats.md); needs `adminToken`. |
| `adminToken` | empty (off) | Panel Bearer token; needs `panelPort`. |

The next keys are the defaults every group inherits unless it names its own:
`phpBinary`, `phpArgs`, `env`, `restartPolicy`, `shutdownTimeoutMs`,
`restartBackoffMs`, `maxRestartBackoffMs`.

### A group

| Key | Default | Purpose |
|---|---|---|
| `name` | — (required) | Identifies the pool in the journal, in `status` and in `--group`. Unique. |
| `workerScript` | — (required) | The script each of its workers runs. |
| `workerCount` | `0` (= number of cores) | How many workers to bring up. |
| `server` | `{}` | Worker parameters → expanded into the worker's `argv`. |
| `workerArgs` | `[]` | Extra raw worker `argv` flags, appended after `server`. |
| `phpBinary` | the master's | Interpreter for this group's workers. |
| `phpArgs` | the master's | Interpreter flags, e.g. `["-d", "extension=…"]`. |
| `env` | the master's | Extra worker env, merged over the master's and the inherited one. |
| `restartPolicy` | the master's | `always` \| `on-failure` \| `never`. |
| `shutdownTimeoutMs` | the master's | How long to wait for a worker to finish before `SIGKILL`. |
| `restartBackoffMs` | the master's | Exponential backoff base in a crash loop. |
| `maxRestartBackoffMs` | the master's | Backoff ceiling. |

The `server` block is pure forwarding: each key becomes a `--key=value` flag. A
scalar travels as it is (booleans → `1`/`0`); a list or an object travels as JSON
in that same value, which is how a worker takes a structured setting — the queue
list of a [consumer](amqp.md#a-supervised-consumer), say. There is no shell on the
way (the command is passed as an array), so quotes and spaces inside a value are
just characters.

The master does not inspect or hardcode the key names, so the same supervisor fits
any worker that parses `--key=value` argv — which keys a worker understands is
defined by the worker itself ([HTTP](http-server.md), [socket](socket-server.md),
[WebSocket](websocket-server.md), [AMQP consumer](amqp.md)). On top of them the
master appends `--masterPid=<pid>`; how the worker uses it is up to the worker
script (typically the orphan check below).

Slot indices are local to a group, so the journal names both — `worker: 4711
orders #2` is slot 2 of the `orders` pool.

## Restart policy and `maxRequests`

| Policy | Behaviour |
|---|---|
| `always` (default) | Restart on any exit — clean or abnormal. |
| `on-failure` | Restart only on a non-zero code or death by signal. |
| `never` | One-shot. |

`always` is the default for a reason: [`maxRequests`](http-server.md#stopping-after-n-requests)
shuts a worker down cleanly with code 0, and only `always` brings up a replacement.
Together with the worker's early listener close and `SO_REUSEPORT` this gives a
rolling restart without traffic loss.

Crash-loop protection: a worker that crashes right at startup gets a per-slot
exponential backoff (base `restartBackoffMs`, doubled on each fast crash, ceiling
`maxRestartBackoffMs`). A worker that has lived longer than ~1 s is considered
healthy and the backoff is reset.

## Self-termination of orphaned workers

If the master dies suddenly (crash, `SIGKILL`, OOM), the workers would otherwise
keep living and holding the port. To prevent that the master passes its pid via
`--masterPid`, and on every tick of the serve loop the server compares
`posix_getppid()` against it: after the master's death the kernel reparents the
process, `getppid()` stops matching (immune to PID reuse), and the server starts
its own graceful shutdown — finishing the requests it has already accepted — and
exits, freeing the port. Outside the master there is no flag, so the check is
disabled.

## Stuck worker

A handler that goes into a native blocking call (`sleep()`, synchronous PDO/`curl`)
or a single monolithic internal call (a huge `preg_match`, `json_decode`) freezes
the worker's single PHP thread — nothing can preempt a native call (a userland CPU
loop is a different case, see [coroutine switching](coroutine-switching.md)).
`handlerTimeoutMs` on the Go side will return `504` to clients, but the worker
itself stays `running` and silently drops out of service. Neither `maxRequests` nor
the orphan check helps: a stuck worker does not *finish* a request, and the master
is alive.

Such a worker can only be killed:

- `reload` or `stop` — the master sends `SIGTERM`, waits `shutdownTimeoutMs`, then
  escalates to `SIGKILL`. A native `sleep` is usually cleared by `SIGTERM` already
  (the signal interrupts the system call), a CPU loop — only by `SIGKILL`;
- `kill -9 <pid>` by hand → the master sees the death by signal and under `always`
  brings up a replacement.

> Limitation: the master does not detect "alive but stuck" — it sees an ordinary
> `running` process and does not touch it until `reload`/`stop`/a manual kill.
> Automatic recovery (a heartbeat watchdog → `SIGKILL` + respawn) is on the
> roadmap.

## Logging

The master writes to a single daily file in `logDir`
(`sconcur-server-2026-06-18.log`); at the day boundary a new file is opened and
files older than `rotateDays` are deleted. The line format is
`[Y-m-d H:i:s.uuuuuu] LEVEL [<scope>]: <message> [<context>]`, where `<scope>` is
`master: <pid>` or `worker: <pid> #<index>`:

```
[2026-06-18 12:00:00.180210] INFO [worker: 12346 #0]: spawned []
[2026-06-18 12:01:00.012044] ERROR [worker: 12346 #0]: exited code=1 uptime=0.3s; restarting in 200ms []
```

The master intercepts the workers' `stdout`/`stderr` and rewrites them into the
same log (stderr → `ERROR`, stdout → `INFO`), so crash output and the worker's
access log are preserved next to the exit record. It also forces
`-d display_errors=stderr` into every worker command (ahead of `phpArgs`), so PHP's
own errors always reach the journal even when the deployment `php.ini` sets
`display_errors=Off`; a later `-d` wins, so `phpArgs` can override this
deliberately.

`logTo` sets the sink: `file` (default), `stdout` (collected by
`docker logs`/journald) or `both` — under `docker logs` you need one of the
latter two. This does not affect performance: records are buffered and flushed
once per supervision tick (~100 ms), not per line.

## Single instance and state

A single instance is enforced by `flock`: at startup the master takes an exclusive
non-blocking lock on `runtimeDir/<name>.lock`, and a second master with the same
`runtimeDir`+`name` gets `MasterAlreadyRunningException`. The kernel releases the
lock on the process's death (even on `SIGKILL`), so there is no stale-lock problem.

The state file `runtimeDir/<name>-state.json` holds pid, start time, the total
worker count, a per-group breakdown (`groups`: name, worker count, script) and
status; it is rewritten on a reload, written atomically, removed on a clean exit
and left behind after a crash. It is also a control channel: the master checks it
every tick, and its removal (logged at `WARN`) is the stop signal — exactly how
`stop` works. So if a `/tmp` cleaner wipes the file, the master stops cleanly with
all its workers.

`status` and `stop` decide whether the master is alive by taking the same `flock`,
not by the pid from the state file, which is immune to a stale state and to PID
reuse. An external guard (cron/timer) can restart the master via the `status` exit
code — `flock` guards against a race between two guards:

```sh
vendor/bin/sconcur-server status --configPath=/app/master.json >/dev/null \
  || vendor/bin/sconcur-server start --configPath=/app/master.json
```

Note that this model restarts the master even after a deliberate `stop` — if you
need a deliberate off switch, use a separate marker or manage it through systemd.
An external supervisor is a valid alternative overall; the built-in master is
convenient when you want to start a pool with one command, have a single graceful
shutdown, a common log and a consistent rolling restart within one process tree,
and tight coupling with the library's features (`maxRequests`, the `masterPid`
guard).

## Graceful shutdown

The stop triggers are `SIGTERM`/`SIGINT` or removal of the state file. In either
case the master stops restarting workers, forwards `SIGTERM` to all live ones
(each finishes the requests it has already accepted, see
[the HTTP server](http-server.md#graceful-shutdown)), waits for them up to
`shutdownTimeoutMs` finishing off survivors with `SIGKILL`, then cleans up the
state file, releases the lock and exits with code `0`.

## Testing

The tests do not depend on a docker service:
`SConcur\Tests\Impl\Worker\TestWorkerMaster` runs `bin/sconcur-server` as a
separate process on a loopback port, with the shared demo server as the worker.
Coverage (`tests/feature/Worker/` + `tests/feature/Features/HttpServer/`):
supervision (spawning N workers, restarting a killed one, self-exit on
`maxRequests` → restart, the `on-failure` and `never` policies), stopping
(graceful shutdown on `SIGTERM` and on removal of the state file), singleness
and state (rejection of a second instance, `status`/`stop`, `status` after a
crash), resilience (crash-loop backoff, config validation, orphan
self-termination) and the logger (line format, context JSON, daily rotation).

---

See also: [HTTP server](http-server.md), [Server statistics](admin-stats.md).
