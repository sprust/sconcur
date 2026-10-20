English | [Русский](worker-master.ru.md)

# Worker master

A supervisor that starts and watches over a pool of worker processes running one
script. It is the counterpart to [`SO_REUSEPORT`](http-server.md): the workers bind
one port and the kernel balances connections across them, scaling the server across
all cores without an external load balancer. Implementation: `src/Worker/` plus the
universal CLI `bin/sconcur-server`.

Each worker is a separate process: by default a copy of the master made by `fork`,
or a `php` of its own started via `proc_open` (see
[Launch: exec or fork](#launch-exec-or-fork)). The master loads the
extension too and takes and sends its signals through it, so neither `pcntl` nor
`posix` is needed; `start` without the extension fails with
`ExtensionNotLoadedException`. It never starts the extension's runtime: the master
does no asynchronous work of its own.

Either way a worker gets its stdin/stdout/stderr and none of the master's own
descriptors: it would otherwise keep every one the master holds open in the child —
the telemetry sockets, the panel's connections, the lock and the log — so the spawn
points all of them at `/dev/null` instead. A forked worker also keeps what was open
before the master started (see
[Loading the application in the master](#loading-the-application-in-the-master)).

## Table of contents

- [Quick start](#quick-start)
- [Commands](#commands)
- [Parameters](#parameters)
- [Launch: exec or fork](#launch-exec-or-fork)
- [Readiness](#readiness)
- [Restart policy and `maxRequests`](#restart-policy-and-maxrequests)
- [A group that never comes up](#a-group-that-never-comes-up)
- [Database connections](#database-connections)
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

Everything a pool needs lives in its group. The flat form — `workerScript`,
`workerCount`, `workerArgs` and `server` at the top level, one pool per master —
is no longer read: a config that still carries those keys is refused on load with
`... belong to a group now — move them into an entry of "groups"`. Moving one
over means wrapping what was at the top level in a single entry of `groups` and
giving it a `name`.

```sh
php -d extension=/app/ext/build/sconcur.so vendor/bin/sconcur-server start --configPath=/app/master.json
```

The master loads the extension itself — here with `-d`, or through `php.ini`. Workers
launched by `fork`, the default, are copies of it and have it already; the `phpArgs`
line matters to a group with `launch: exec`, and is accepted under `fork` as long as the
extension it names is the one loaded.

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

Every command takes `--configPath`; `--group` narrows `status` and `reload` to one
pool. `start` and `stop` accept it too and refuse a name the config does not
describe, but they serve the whole config either way. The
same config across all commands guarantees consistent `runtimeDir`/`name`, so
`status`/`stop`/`reload` find the lock, state and trigger files.

```sh
vendor/bin/sconcur-server start   --configPath=/app/master.json  # bring up the pools (foreground)
vendor/bin/sconcur-server status  --configPath=/app/master.json  # running: pid=12345 workers=8 groups=2
vendor/bin/sconcur-server stop    --configPath=/app/master.json  # remove the state file and wait for exit
vendor/bin/sconcur-server reload  --configPath=/app/master.json  # re-read the config and roll the workers
vendor/bin/sconcur-server reload  --configPath=/app/master.json --group=orders  # roll one pool
```

`--group=NAME` narrows `status` to one pool and `reload` to rolling one, leaving
its neighbours where they are. A name the config does not define is a usage error
(exit `2`). `stop` takes the whole master — there is one lock and one state file,
and stopping half of it is not a thing.

Exit codes: `start` — the master's own: `0` after a stop, `70` when a group never came
up (see [below](#a-group-that-never-comes-up)), `1` when it could not start; `status`
— `0` running / `3` stopped-or-stale; `stop` — `0` once stopped (or none was running),
`1` on timeout; `reload` — `0` on completion, `3` if the master is not running, `1` on
timeout, or when the master refused the reload or gave it up — the reason is printed
(`reload failed: …`) and is in the journal too.

`status` prints the totals and then one line per group.

`reload` re-reads the config and rolls the workers onto it. The command creates
the trigger file `<runtimeDir>/<name>.reload` naming the config; the master reads
it, then rolls each group's workers one at a time, the new one first:

1. It starts a replacement beside the worker of the slot.
2. It waits for the replacement to say it is up (see [readiness](#readiness)), at most
   `readyTimeoutMs`.
3. The replacement takes the slot, and the old worker gets `SIGTERM`: it stops
   accepting, finishes the requests it has already accepted and exits; past
   `shutdownTimeoutMs` it gets `SIGKILL`.
4. Once the old worker is gone, the next slot.

The group never runs below its `workerCount` while it rolls, and at most one process
above it. A fresh `php worker.php` picks up new code from disk; a
[forked](#launch-exec-or-fork) one picks up the application's, while what the master
itself loaded stays as it was.

The old worker and its replacement run side by side for a moment, so they must be able
to share the port: `reusePort: true` in `server`, or the group's `listen`. A worker that
binds its address alone cannot be replaced this way — its replacement fails to bind
and the reload is given up.

A replacement that exits before it is up, or is not up within `readyTimeoutMs`, gives
the reload up:

- the replacement is stopped, and the slots the reload had not reached keep the
  workers they have;
- the slots it had already rolled keep their new workers: those came up;
- the group's previous settings are put back, so a worker that crashes later is
  respawned on what was running, not on what failed;
- the journal says `group <name>: reload given up: <reason>`, and `reload` exits with
  `1` and the same reason.

A version that crashes on startup therefore costs one replacement, not the pool.

What a reload may change: the set of groups (one added is spawned, one removed
has its workers finish their work and is not replaced), and each group's
`workerCount`, `workerScript`, `workerArgs`, `server`, `env`, `launch`,
`phpBinary`, `phpArgs`, `restartPolicy`, timeouts and backoffs. Scaling a pool is therefore
an edit plus a `reload`, not a restart.

What it may not: `runtimeDir` and `name` identify the running instance, and
`panelPort`, `adminToken` and `logDir` are bound at startup. A reload reads the new
values and ignores them without a word — a full restart is what applies them. A
group's `listen` is the one exception that is refused rather than ignored: its
workers accept on the socket the master bound at the start, so a reload that moves it
is turned down with the reason in the journal. A group added by a reload gets its
socket then, and one removed closes its socket once its workers have finished; a
`start` whose `listen` address is taken fails before any worker runs.

A config that does not parse, or that names a `workerScript` that is not there, is
refused and the master keeps running on the one it has. A typo must never take a
working pool down; the refusal and its reason go to the journal. The same goes for a
trigger naming a config file the master cannot find — which is why the CLI resolves the
path to an absolute one before writing it, the master reading it from its own working
directory rather than the operator's.

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
`launch`, `phpBinary`, `phpArgs`, `env`, `restartPolicy`, `shutdownTimeoutMs`,
`restartBackoffMs`, `maxRestartBackoffMs`, `watchdogTimeoutMs`, `readyTimeoutMs`.

### A group

| Key | Default | Purpose |
|---|---|---|
| `name` | — (required) | Identifies the pool in the journal, in `status` and in `--group`. Unique. |
| `workerScript` | — (required) | The script each of its workers runs. |
| `workerCount` | `0` (= logical CPUs, what `nproc` reports — twice the core count where hyperthreading is on) | How many workers to bring up. |
| `server` | `{}` | Worker parameters → expanded into the worker's `argv`. |
| `workerArgs` | `[]` | Extra raw worker `argv` flags, appended after `server`. |
| `listen` | empty | An address the master binds once and hands to every worker of the group as `--listenFd` — [one socket for the whole pool](http-server.md#one-socket-for-the-whole-pool) instead of `SO_REUSEPORT`. Excludes `reusePort` and `listenFd` in `server`. |
| `launch` | the master's (`fork`) | `fork` — a copy of the master; `exec` — a `php` of its own on the script. See [Launch: exec or fork](#launch-exec-or-fork). |
| `phpBinary` | the master's | Interpreter for this group's workers. Under `fork` only the php the master runs on, named by its path, a symlink or a name on `PATH`. |
| `phpArgs` | the master's | Interpreter flags, e.g. `["-d", "extension=…"]`; under `fork` only `-d name=value`. |
| `env` | the master's | Extra worker env, merged over the master's and the inherited one. |
| `restartPolicy` | the master's | `always` \| `on-failure` \| `never`. |
| `shutdownTimeoutMs` | the master's | How long to wait for a worker to finish before `SIGKILL`. |
| `restartBackoffMs` | the master's | Exponential backoff base in a crash loop. |
| `maxRestartBackoffMs` | the master's | Backoff ceiling. |
| `watchdogTimeoutMs` | the master's (`60000`) | How long a worker may stop signalling it is alive before it is killed and replaced — see [stuck worker](#stuck-worker). `0` switches the watch off; anything else must be at least 5000. |
| `readyTimeoutMs` | the master's (`30000`) | How long a `reload` waits for each replacement to say it is up before it gives the reload up — see [readiness](#readiness). At least 1000; there is no "off". |

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

## Launch: exec or fork

A group's `launch` says how its workers are started. `fork`, the default, makes each
worker a copy of the master: the master is a `php` with the extension already loaded,
and the copy sets up the worker's environment and argv and `require`s `workerScript`.
`exec` starts each one as `php <phpArgs> workerScript <argv>`. The worker script is the
same either way.

| | `exec` | `fork` |
|---|---|---|
| A worker starts as | a new `php`: interpreter, `php.ini`, the extension | a copy of the master: nothing to start or load |
| Master start to the first answer of one worker (demo server, median of 7) | 36–40 ms | 24–30 ms |
| `phpBinary` | any | the master's own only (by path, symlink or name on `PATH`); another one is refused |
| `phpArgs` | any flags | only `-d name=value`, applied with `ini_set` after `display_errors=stderr` |
| `php.ini` | its own | the master's |
| What a `reload` picks up | new code of everything | new application code when its files are replaced in place, not when a deploy switches a symlink to a new release (see below); the library and the extension the master loaded stay as they were until it restarts |
| Scope the script runs in | global | a function's |

What a fork group's `phpArgs` may carry is checked at `start` and on every `reload`,
and a group that asks for what cannot be honoured is refused rather than run without
it: an `extension` has to be loaded in the master already, and a directive PHP fixes
at startup (`extension_dir`, `opcache.enable_cli`) cannot be set at all.

The script runs inside a function, so its top-level variables are that function's
locals. `$argv`, `$argc` and `$_SERVER['argv']` hold what a `php` started on the
script would have had. A script that relies on `global` or on `$GLOBALS` holding its
top-level variables needs `exec`.

The rest is the same as for `exec`. The child is prepared before PHP runs in it:
stdin from `/dev/null`, stdout and stderr onto the master's pipes, so the journal reads
as before, and every descriptor the master opened itself pointed at `/dev/null`. The
signal handlers the master installed are put back to PHP's own, the random generators
are reseeded, and the master's objects are marked as destroyed so their destructors
never run in a worker. An uncaught exception goes to the exception handler, if one is
set, and is otherwise reported on stderr the way PHP reports it; the worker exits with
`255`, and `exit()` works as usual. In `ps` a forked worker shows as
`sconcur-server: <group> #<slot>`. Telemetry, the watchdog and `--masterPid` work as
with `exec`.

A fork is refused in a process that runs threads, because a fork keeps only the
calling thread. The master's own code never starts the extension's runtime, but an
`onWatchdogEvent` handler that used SConcur's asynchronous features would, and every
fork after it would fail; the journal says why.

A forked worker loads the application's classes through the autoloader the master
loaded when it started, and Composer's autoloader holds its paths with symlinks already
resolved. A deploy that writes a new release into a directory of its own and switches a
`current` symlink to it leaves that autoloader pointing into the old release: after a
`reload` the worker script comes from the new release and the classes it loads from the
old one. Restart the master on such a deploy, or launch the group with `exec`.

`exec` is the choice for a group that needs:

- another interpreter, another `php.ini` (`-c`) or none (`-n`);
- a worker script that relies on running in the global scope;
- a `reload` that picks up a new library or extension without restarting the master;
- a `reload` after a deploy that switches a symlink to a new release.

Before 0.17 every worker was started by `exec`. A config written then that names
another php or passes `phpArgs` other than `-d name=value` is refused at `start`, with
the reason and the group's name; `launch: exec` on the group, or at the top level for
all of them, brings back what it had.

### Loading the application in the master

A forked worker is a copy of the master, and of whatever its process loaded before the
master started. An application that loads itself first and starts the master after is
loaded once: every worker starts with it in memory, and the pages stay shared until a
worker writes to them. There is no setting for it; it is what the process does before
`start`:

```php
// server.php: php -d extension=sconcur.so server.php start --configPath=master.json
require __DIR__ . '/vendor/autoload.php';

$GLOBALS['app'] = require __DIR__ . '/bootstrap/app.php';

exit(new SConcur\Worker\MasterCli()->run($argv));
```

The groups that use the application have `launch: fork`, and their worker script takes
it from `$GLOBALS` or a static instead of loading it. The script runs inside a
function, so its own top-level variables are not global, but one the entry script set
is. `status`, `stop` and `reload` do not need the application and work through
`bin/sconcur-server` with the same `--configPath`.

Measured with `make bench-worker-memory`: 12 workers, a stand-in application of about
1 250 classes (Composer's classmap, some 11 MiB of compiled code), OPcache off in the
CLI, after 5 000 requests. Pss counts each shared page in shares, so its sum is what
the pool takes from the machine:

| The workers start as | Pss, all workers | Pss per worker | Private_Dirty, all workers |
|---|---:|---:|---:|
| `exec` | 275.5 MiB | 23.0 MiB | 259.9 MiB |
| `fork`, the application loaded by the worker script | 190.5 MiB | 15.9 MiB | 176.0 MiB |
| `fork`, the application loaded before the master started | 63.8 MiB | 5.3 MiB | 38.6 MiB |

The master adds 4–11 MiB of its own in each case.

What the master takes care of:

- Descriptors open when the master starts stay open in forked workers: a log file a
  logger opened while the application loaded is written from every worker. Only the
  descriptors the master opens itself are pointed at `/dev/null`.
- The application's error handler is kept out of the master's loop: a handler that
  turns warnings into exceptions would otherwise end the master at the first warning
  it silences, and the master silences one on every tick. Every forked worker runs
  under the handler again, and an exception that escapes the worker script goes to the
  application's exception handler, as it would in a `php` of its own.
- A scheduler built while the application loaded marks the worker alive for the
  watchdog, not the master.

What is left to the application:

- No asynchronous call before the master starts. It starts the extension's runtime, a
  process with the runtime running cannot fork, and `start` is refused with the reason.
- No connection opened before the master starts. A fork does not copy a connection, it
  shares it: the master and every worker hold the same socket, and each takes it for
  its own. Two workers sending at once interleave their bytes on one stream, and the
  database answers with a protocol error (`Packets out of order`, `server has gone
  away`). A response goes to whichever worker reads first, so one request can get the
  rows of another's query. And a worker that closes the connection closes it for all.
  Most clients connect on the first query, as Laravel's do, and those are fine; a
  connection made while loading is to be closed before the master starts
  (`DB::disconnect()`). A listening socket and a datagram one (syslog, statsd) are safe
  to share.
- The server is built in the worker script. Its flags (`server`, `--listenFd`,
  `--masterPid`) reach the worker's argv, not the master's.
- A `reload` restarts the workers with the application the master loaded. Changed
  application code takes a restart of the master.

## Readiness

A worker tells the master it is up: it is running and doing its work. A `reload` waits
for that from every replacement before it stops the worker the replacement takes over
from, and the master uses it to tell a group that [never came
up](#a-group-that-never-comes-up).

The three servers and the [AMQP consumer](amqp.md#a-supervised-consumer) say it on
their own. Their serve loop does, once its first wait for work comes back with anything
but the server's own failure. A server binds its socket before anything else, so a
socket it cannot have is that failure, and such a worker is never up.

A worker script that does its work in a loop of its own — a `WaitGroup` loop, a
consumer built on `Queue::consume()`, a Redis subscription — says it itself, once the
loop is running:

```php
use SConcur\Scheduler\Scheduler;

while (!$stopRequested) {
    // ... one round of work ...

    Scheduler::get()->markReady(); // only the first call says anything
}
```

`markReady()` does nothing outside a worker started by a master. A script that never
calls it is never up: it runs and is restarted as before, but a `reload` gives up on its
first replacement after `readyTimeoutMs`. The master cannot tell such a script from one
that hangs while it loads, and it does not guess.

How it travels: the same pipe the [watchdog](#stuck-worker) reads, one byte for "up".
The master opens it for every worker, with the watchdog off too.

## Restart policy and `maxRequests`

| Policy | Behaviour |
|---|---|
| `always` (default) | Restart on any exit — clean or abnormal. |
| `on-failure` | Restart on a non-zero code, on death by signal, and when the worker reached its own limit. |
| `never` | One-shot. |

A server's [`maxRequests`](http-server.md#stopping-after-n-requests) (`maxConnections` for
the socket and WebSocket servers, `maxMessages` for the AMQP consumer) stops a worker
once it has handled that much, as a guard against leaks in a long-lived process. Under a
master:

- The worker says why it is stopping, before it finishes its last requests. The master
  starts its replacement at once, without the crash backoff and without counting it as
  a failure, so the slot is not empty for the length of a finish and a start. That is a
  replacement under `always` and `on-failure`; under `never` the worker is let go.
- The limit is spread. Each worker stops at a number drawn between the limit and half
  again as many (`10000` becomes something from `10000` to `15000`). The workers of a
  pool start together and share the load evenly, so with one number they would all
  reach it within the same second and the pool would restart as one. Spread, they come
  apart, and further with each generation. The number drawn is in the worker's
  shutdown line: `stop accepting (reason=limit, limit=12408)`.

Without a master the limit is exact: a one-off consumer told to take `maxMessages: 100`
takes 100.

Crash-loop protection: a worker that crashes right at startup gets a per-slot
exponential backoff (base `restartBackoffMs`, doubled on each fast crash, ceiling
`maxRestartBackoffMs`). A worker that has lived longer than ~1 s is considered
healthy and the backoff is reset.

## A group that never comes up

When as many workers of a group as it has slots die before any of them is up — exit
with an error or by a signal, or cannot be started at all — the master stops all its
groups and exits with code `70` (`EX_SOFTWARE`):

```
group http: no worker came up — 8 failed before any said it was up; stopping with code 70
```

That is a broken deploy, and systemd or an orchestrator sees it as one, instead of a
master respawning into the same failure with growing delays for as long as nobody reads
the journal.

A worker counts as up once it [says so](#readiness), or once it has run for longer than
`readyTimeoutMs` — so a script that never calls `markReady()` is not taken for a
failure, as long as it does not crash at startup. A clean exit is not a failure. Only
the groups the master started with are checked: one a `reload` added must not take a
running pool down, and its failures are left to the backoff.

## Database connections

Every pool lives in a worker: each worker opens its own connections, and nothing is
shared between workers. A connection is not just a socket but state in the process that
opened it — TLS keys, protocol sequence numbers, an open transaction — so it cannot be
handed to another process. The master opens none. What a database sees is therefore the
sum over the workers:

| Feature | Per worker, per pool | Set by |
|---|---|---|
| MySQL, PostgreSQL | up to `maxOpenConns`, 32 when it is `0`; opened on demand, and a pool unused for 5 minutes is closed | `maxOpenConns` of the `Connection` ([MySQL](mysql.md#connection-pool-and-concurrency)) |
| Redis | `poolSize` shared connections (4 by default, 64 at most), plus one for each blocking command in flight and one for each subscription | `poolSize`; the rest by how many run at once ([Redis](redis.md#blocking-commands)) |
| MongoDB | the driver's pool for each server of the deployment, 10 by default | `maxPoolSize` in the URI |

A pool is the DSN together with the sizes, so two `Connection`s with different sizes are
two pools in the same worker.

The defaults add up fast: 16 workers × 32 is 512 connections to MySQL or PostgreSQL,
against a `max_connections` of 151 in MySQL and 100 in PostgreSQL by default. Divide the
budget the server gives you, minus what else connects to it (migrations, cron jobs,
consoles), by the number of workers: 90 connections of PostgreSQL's 100 over 16 workers
is `maxOpenConns: 5`. With `workerCount: 0` the count is the number of logical CPUs, so
the same config needs a smaller cap on a bigger machine.

Leave room for a worker or two above `workerCount`. A [reload](#commands) runs each
replacement beside the worker it replaces, and a worker that reached its limit finishes
its last requests while its replacement starts — for that moment both hold their pools.

When the cap per worker comes out smaller than the concurrency a worker needs, put a
connection pooler in front of the server: PgBouncer for PostgreSQL, ProxySQL for MySQL.
The workers hold connections to the pooler, and it shares fewer server connections
between them. In PgBouncer's transaction mode a server connection changes hands between
transactions, so nothing tied to the session survives outside one: a `SET`, a temporary
table, an advisory lock, `LISTEN`. Whether this library's statements work through such a
pooler has not been tested.

A connection opened before the master starts is a different problem — one socket shared
by every forked worker; see [loading the application in the
master](#loading-the-application-in-the-master).

## Self-termination of orphaned workers

If the master dies suddenly (crash, `SIGKILL`, OOM), the workers would otherwise
keep living and holding the port. To prevent that the master passes its pid via
`--masterPid`, and the serve loop compares its parent pid against it, asking the
extension at most every 100 ms: after the master's death the kernel reparents the
process, the parent pid stops matching (immune to PID reuse), and the server starts
its own graceful shutdown — finishing the requests it has already accepted — and
exits, freeing the port. Outside the master there is no flag, so the check is
disabled.

## Stuck worker

A handler that goes into a native blocking call (`sleep()`, synchronous PDO/`curl`)
or a single monolithic internal call (a huge `preg_match`, `json_decode`) freezes
the worker's single PHP thread — nothing can preempt a native call (a userland CPU
loop is a different case, see [coroutine switching](coroutine-switching.md)).
`handlerTimeoutMs` inside the extension will return `504` to clients, but the worker
itself stays `running` and silently drops out of service. Neither `maxRequests` nor
the orphan check helps: a stuck worker does not *finish* a request, and the master
is alive.

The master finds such a worker by itself and replaces it. A worker writes a byte to
an extra pipe (descriptor 3, opened by `proc_open` beside stdout and stderr; a forked
worker is handed it) every time its PHP thread comes back to the scheduler, at most
twice a second; the master reads that pipe on every supervision tick and times the last
byte by its own clock. The same pipe carries the worker's [readiness](#readiness) and
its "stopping at my limit".
Stop coming back and the bytes stop, which is what "stuck" means here. It costs one
clock read per pass, and nothing at all in a worker running without a master.

That covers a server's serve loop and equally any loop driving an ordinary
`WaitGroup` — a pool of periodic jobs, a consumer written by hand. A worker whose
loop drives neither can mark itself with `Scheduler::get()->markAlive()`, which is a
no-op outside a supervised worker. Past
`watchdogTimeoutMs` (60 s by default) the worker gets `SIGTERM`, then `SIGKILL` if it
is still there `shutdownTimeoutMs` later, and the slot is refilled the way any other
death refills it — under `always`, and under `on-failure` too, since a killed worker
did not exit cleanly. Under `never` the slot stays empty, and a master left with no
live worker at all exits:

```
worker: 4711 http #0 no heartbeat for 61.2s (limit 60000ms); sending SIGTERM
worker: 4711 http #0 exited code=0 uptime=94.3s; restarting in 200ms
```

`SIGTERM` usually interrupts the native call the worker is stuck in, and the worker
then shuts down in order, which is why the line above says `code=0`. `SIGKILL` is for
what it does not free: a CPU loop with preemption off, or a process in
uninterruptible I/O — and the second one takes no signal at all, so if the slot is
still occupied two seconds after the kill the journal says so and the slot stays down
until the kernel releases it.

A worker that hung at startup is replaced after a backoff rather than at once: the
threshold is what it lived through, not work it did, and when the cause is shared — a
wedged upstream every worker calls synchronously — the replacement hangs the same
way, so without the backoff the pool would recycle itself forever. A worker that
served for an hour before hanging is not treated that way: what counts is how long it
kept marking itself alive, not how long it existed.

`watchdogTimeoutMs` is either `0` or at least 5000. A worker marks itself alive at
most twice a second, so a threshold near that reads the ordinary gap between two
marks as a hang and kills every worker of the group, replacements included; a smaller
value is refused when the config is read rather than quietly raised.

Why a mark from PHP and not the telemetry snapshot: snapshots are pushed by a loop
inside the extension, on a runtime thread of its own, so a worker whose PHP thread is
frozen keeps reporting as if nothing had happened. The `workersHung` flag in
[server statistics](admin-stats.md) says the snapshots stopped arriving — a different
question, answered by a different thread.

What the watch leaves alone:

- A worker that has never marked itself alive — one that neither drives the scheduler
  nor calls `markAlive()`, so nothing about its PHP thread is known. The watchdog acts
  on evidence of a hang, not on its absence, so such a worker is supervised exactly as
  it was before.
- A CPU-bound handler under [preemption](coroutine-switching.md), when the worker
  arms it (`preemptionQuantumMs` above `0`): the scheduler parks its coroutine and goes back round the loop, so
  the marks keep coming while it computes. Everything that waits on I/O keeps them
  coming too, preemption or not — a suspended coroutine hands control back to the
  loop, and so does an idle server's poll, which wakes every 250 ms with nothing to
  do, so a worker that serves no request at all still marks itself. I/O here means I/O
  through SConcur — a native `sleep`, a PDO query or a `curl` call blocks the PHP
  thread and marks nothing, which is the case the watchdog exists for. Only a handler
  that returns control to nobody stops the marks. With preemption off (the default,
  `preemptionQuantumMs: 0`), a handler that computes for longer than the threshold
  leaves its worker unservable for that whole time, and the worker will be replaced —
  raise `watchdogTimeoutMs` past the longest such handler, or set it to `0` for that
  group.
- A worker that has left its slot: one a `reload` replaced, or one that reached its
  limit after its replacement was started. It has `shutdownTimeoutMs` to finish and
  gets `SIGKILL` past that, hung or not. The replacement a `reload` is waiting on is
  not watched either: `readyTimeoutMs` bounds it. A group being retired after a reload
  removed it from the config is watched, and deliberately: `retire()` has no deadline
  of its own, so a hung worker would otherwise hold the retiring group open forever.
- A worker that is stopping but never finishes — `SIGTERM` taken, with one handler
  waiting on a result that will not arrive. Its serve loop keeps turning, so it keeps
  marking itself alive while serving nothing. Give such handlers a `handlerTimeoutMs`;
  the watchdog cannot see this one.
- A worker that hangs before it reaches its serve loop — in a synchronous call made
  during bootstrap. It has never marked itself alive, and nothing tells that apart
  from a worker script that has no serve loop at all, so such a slot stays what it
  was before the watchdog existed: `running`, and not refilled.

Killing one by hand still works as before: `kill -9 <pid>`, and under `always` the
master brings up a replacement.

### Seeing it happen

Three places, in order of how far the news travels:

- The master's journal, on every step: `no heartbeat for …; sending SIGTERM`, then
  `hung worker did not exit on SIGTERM; sending SIGKILL`, then `still alive … after
  SIGKILL` if even that did not free the slot.
- `watchdogKills` per pool in [server statistics](admin-stats.md), and
  `sconcur_pool_watchdog_kills_total` / `sconcur_group_watchdog_kills_total` in the
  Prometheus scrape. Note that the older `workersHung` answers a different question —
  it means the extension's runtime stopped sending snapshots, and a worker killed by
  the watchdog usually never looked `hung` at all.
- A handler of your own, wherever the master is started from your code. Through the
  CLI, which is what an application embedding `sconcur-server` drives:

```php
use SConcur\Worker\MasterCli;
use SConcur\Worker\WatchdogEvent;
use SConcur\Worker\WatchdogEventEnum;

$onWatchdogEvent = static function (WatchdogEvent $event): void {
    // $event->event is HeartbeatLost, KillEscalated or KillSurvived; ->group, ->slot,
    // ->pid, ->ageSeconds and ->watchdogTimeoutMs say which worker and why. Send it
    // wherever you watch things from.
    if ($event->event === WatchdogEventEnum::HeartbeatLost) {
        error_log(sprintf('worker %d of %s hung for %.1fs', $event->pid, $event->group, (float) $event->ageSeconds));
    }
};

exit(new MasterCli(onWatchdogEvent: $onWatchdogEvent)->run($argv));
```

Or straight on the master, when the config is built in memory rather than read
from a file:
`MasterConfig::fromArray($config)->toWorkerMaster($onWatchdogEvent)->run()`.

The handler runs inside the supervision tick, so keep it short — and whatever it
throws is written to the journal and dropped, because alerting must not be able to
stop the supervisor from supervising.

The watch is on by default, including for a config written before it existed. If any
of your handlers legitimately holds the PHP thread longer than 60 s — a native
`file_get_contents` off a slow volume, a synchronous call to a slow upstream, a long
native computation — raise `watchdogTimeoutMs` past the longest of them or set it to
`0` for that group.

Think of it per process rather than per request. One handler blocked in a native call
for a minute is not one slow request: for that minute the worker answers nobody, the
requests it had already accepted included. That is why the default treats it as a
hang. The replacement is not free either — `SIGTERM` is only serviced once the native
call returns, so if it runs past `shutdownTimeoutMs` the worker is `SIGKILL`ed and the
requests it was holding die with it. The way out that costs nothing is not to block
the thread: put such a read behind something that suspends a coroutine instead of the
process, or move it out of the request path.

## Logging

The master writes to a single daily file in `logDir`
(`sconcur-server-2026-06-18.log`); at the day boundary a new file is opened and
files older than `rotateDays` are deleted. The line format is
`[Y-m-d H:i:s.uuuuuu] LEVEL [<scope>]: <message> [<context>]`, where `<scope>` is
`master: <pid>` or `worker: <pid> <group> #<index>` — the group is part of it because a
slot index only names a worker together with its pool:

```
[2026-06-18 12:00:00.180210] INFO [worker: 12346 http #0]: spawned []
[2026-06-18 12:01:00.012044] ERROR [worker: 12346 http #0]: exited code=1 uptime=0.3s; restarting in 200ms []
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
`maxRequests` → restart, the `on-failure` and `never` policies), reload (each
replacement up before the old worker stops, given up on a replacement that dies or
never says it is up, a script with `markReady()`), a group that never comes up (exit
`70`), stopping
(graceful shutdown on `SIGTERM` and on removal of the state file), singleness
and state (rejection of a second instance, `status`/`stop`, `status` after a
crash), resilience (crash-loop backoff, config validation, orphan
self-termination) and the logger (line format, context JSON, daily rotation).
`ForkedWorkerMasterTest` runs every one of those scenarios once more with the
workers launched by fork. `PreloadedWorkerMasterTest` starts the master from a script
that loads an application first and checks what reaches its forked workers.

---

See also: [HTTP server](http-server.md), [Server statistics](admin-stats.md).
