English | [Русский](worker-master.ru.md)

# Worker master

A supervisor that keeps pools of worker processes up. It runs in the process that loaded
your application: you build it in PHP, and every worker is a fork of that process, so it
starts with the application already in memory. The workers of a pool share one port with
[`SO_REUSEPORT`](http-server.md#scaling-across-cores-so_reuseport), so a server scales
across all cores without a load balancer in front. Implementation: `src/Worker/`.

The master takes and sends its signals through the extension, so neither `pcntl` nor
`posix` is needed; without the extension `run()` fails with
`ExtensionNotLoadedException`. The master itself never runs anything asynchronous: a
process running the extension's runtime cannot fork its workers — the master forks
through the extension, which refuses in a process that runs threads (a plain
`pcntl_fork` there is allowed and rebuilds the runtime in the child, see the
[README](../README.md)) — and once `run()` has begun the extension refuses an
asynchronous call in the master's process with the reason.

## Table of contents

- [Quick start](#quick-start)
- [Roles and the worker's context](#roles-and-the-workers-context)
- [Loading the application before the master](#loading-the-application-before-the-master)
- [Parameters](#parameters)
- [Running, stopping, deploying](#running-stopping-deploying)
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

```php
// master.php
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SConcur\Features\HttpServer\HttpServer;
use SConcur\Worker\WorkerContext;
use SConcur\Worker\WorkerGroupConfig;
use SConcur\Worker\WorkerMaster;

require __DIR__ . '/vendor/autoload.php';

// Whatever every worker needs and none of them changes is built here, once: the
// workers are forks of this process and start with it in memory.
$factory = new Psr17Factory();

$master = new WorkerMaster(
    runtimeDir: '/run/sconcur',
    groups: [
        new WorkerGroupConfig(
            name: 'http',
            // What a worker runs, right after its fork.
            role: static function (WorkerContext $context) use ($factory): void {
                $server = new HttpServer(
                    serverRequestFactory: $factory,
                    responseFactory: $factory,
                    address: '0.0.0.0:8080',
                    maxRequests: 10_000,
                );

                $server->serve(static fn (ServerRequestInterface $request): ResponseInterface =>
                    $factory->createResponse(200)->withBody($factory->createStream('ok')));
            },
            workerCount: 8,
        ),
    ],
    logDir: '/var/log/sconcur',
);

exit($master->run());
```

```sh
php -d extension=/app/ext/build/sconcur.so master.php
```

The workers of a group share the port with no setting: every server binds with
`SO_REUSEPORT`, and the kernel spreads the connections over them. The price is that a
second process on the port joins rather than failing to bind — see
[a second process on the port](http-server.md#a-second-process-on-the-port).

`run()` blocks and supervises the pools until `SIGTERM`/`SIGINT`, until the state file is
removed ([`MasterControl::stop()`](#single-instance-and-state)), or until every worker
has finished with nothing left to restart.

A master supervises one or more groups, and they need not be alike. One supervisor, one
lock, one journal and one panel can hold an HTTP pool and a couple of queue-consumer
pools at once:

```php
$master = new WorkerMaster(
    runtimeDir: '/run/sconcur',
    groups: [
        new WorkerGroupConfig(name: 'http', role: $httpRole, workerCount: 8),
        new WorkerGroupConfig(name: 'orders', role: $ordersRole, workerCount: 2),
    ],
);
```

## Roles and the worker's context

A group's role is a closure the worker calls right after its fork. It builds what the
worker does — a server, a [consumer](amqp.md#a-supervised-consumer), a loop of its own —
and runs it. The worker leaves when the role returns, with code `0`.

The role gets a `WorkerContext`: the group's name (`group`), the worker's slot
(`slot`, from `0`) and the master's pid (`masterPid`). The same object is
`WorkerContext::current()` for the life of the worker, which is how what the role builds
finds it without being told: the three servers and the AMQP consumer watch the master
from it ([orphaned workers](#self-termination-of-orphaned-workers)), and take the
[telemetry](admin-stats.md) settings the master puts in the worker's environment
(`SCONCUR_TELEMETRY_SOCKET`, and `SCONCUR_SERVER_NAME` as `<group>:<slot>`).

An exception that escapes the role goes to the application's exception handler if one is
set, and is otherwise reported on stderr the way PHP reports it; the worker exits with
`255`. `exit()` in the role works as usual. A worker that dies on startup explains itself
in the journal at `ERROR`, whatever `display_errors` in `php.ini` says: the worker sets
`display_errors=stderr` on itself. In `ps` a worker shows as
`sconcur-server: <group> #<slot>`.

What a worker gets from the master's process, prepared before the role runs:

- Everything in memory: the application, the role, what the role captured. It is a copy:
  what a worker changes the master and the other workers do not see, and the other way
  round. Workers that need to share data go through Redis, a queue or a database.
- Its own stdin (`/dev/null`), stdout and stderr (pipes the master reads into the
  [journal](#logging)), and a [heartbeat pipe](#stuck-worker) of its own.
- The descriptors the process had open when `run()` was called — the application's: a
  log file a logger opened while loading is still written from the worker. What the
  master opened after that (its lock, journal, telemetry sockets, the other workers'
  pipes) is pointed at `/dev/null` in the worker.
- None of it reaches a program the worker starts (`shell_exec`, `proc_open`, a sidecar):
  everything above stderr is close-on-exec in the worker, so such a program gets its own
  stdio and nothing else.
- The application's error and exception handlers. The master keeps its own error handler
  over the application's while it supervises — a handler that turns warnings into
  exceptions would otherwise end the master at the first warning it silences — and the
  worker gets the application's back.
- PHP's own signal handling: the master's watching of `SIGTERM`/`SIGINT` is undone.
- Random generators of its own: `mt_rand()`, `rand()` and the like are reseeded.
- The master's objects are marked as destroyed, so their destructors never run in a
  worker; what the worker creates is destroyed as usual.

## Loading the application before the master

The point of building the master in the application's process is that the application is
loaded once and every worker shares its memory: its compiled code, its configuration,
its container. A worker that dies is replaced by another fork in about a millisecond,
without loading anything again. A framework is loaded by its own bootstrap — a
framework's console kernel `bootstrap()`, say — and only then the master is built and run.

What this asks of the application:

- **No asynchronous call before `run()`, and none in the master after it.** It starts the
  extension's runtime, and a process running it cannot fork: `run()` refuses to start with
  the reason (`ForkRefusedException`), and once it runs, the extension refuses the call.
  Code that runs in the master — an `onWatchdogEvent` handler, a framework's event
  listener behind it — must not make one either.
- **No connection open when `run()` is called.** A fork does not copy a connection, it
  shares it: every worker holds the same socket, two of them sending at once interleave
  their bytes, and the database answers with a protocol error or hands one worker's rows
  to another. Clients that connect on their first query, as most do, are fine; one that
  connected while the application loaded is to be closed before `run()`
  (`DB::disconnect()` in Laravel). A listening socket and a datagram one (syslog, statsd)
  are safe to share.
- Whatever the application computed while loading is the same in every worker: an id
  generated then, a timestamp taken then, a pid a logger remembered. Anything that has to
  differ between workers is made in the role.
- An extension that starts threads when `php` starts — some APM agents do — leaves a
  process that cannot fork. Such a process is refused at `run()`, with the reason;
  workers that each start a `php` of their own are not supported.

## Parameters

Both objects check what they are given as they are built and throw
`InvalidConfigException` on what cannot work: a name outside `[A-Za-z0-9._-]` (the
master's is a path component and a rotation glob, a group's goes into the journal and
the process title), a negative count or timeout, a watchdog threshold or
a ready timeout below its floor, two groups with one name, a master with no group.

### `WorkerMaster`

| Parameter | Default | Purpose |
|---|---|---|
| `runtimeDir` | — (required) | Directory of the lock and state files (local FS). |
| `groups` | — (required) | The pools to supervise, `WorkerGroupConfig` each; at least one. |
| `logDir` | `runtimeDir` | Log directory. |
| `name` | `sconcur-server` | Prefix for the lock, state and log file names. |
| `rotateDays` | `3` | How many days to keep log files, access-line files included; `0` keeps them all. |
| `logTo` | `LogTarget::File` | `File` \| `Stdout` \| `Both` (for `docker logs` — `Stdout`/`Both`). |
| `panelPort` | `0` (off) | Port of the built-in [telemetry panel](admin-stats.md); needs `adminToken`. |
| `adminToken` | empty (off) | Panel Bearer token; needs `panelPort`. |
| `onWatchdogEvent` | `null` | Called when the [watchdog](#stuck-worker) acts on a worker. |

### `WorkerGroupConfig`

| Parameter | Default | Purpose |
|---|---|---|
| `name` | — (required) | Identifies the pool in the journal, the state file and the panel. Unique within a master. |
| `role` | — (required) | What a worker runs, see [roles](#roles-and-the-workers-context). |
| `workerCount` | `0` (= logical CPUs, what `nproc` reports — twice the core count where hyperthreading is on) | How many workers to keep up. |
| `restartPolicy` | `RestartPolicy::Always` | `Always` \| `OnFailure` \| `Never`, see [below](#restart-policy-and-maxrequests). |
| `shutdownTimeoutMs` | `10000` | How long a stopping worker may finish before `SIGKILL`. |
| `restartBackoffMs` | `200` | First delay before restarting a worker that keeps crashing at startup; doubled with each such crash. |
| `maxRestartBackoffMs` | `30000` | Ceiling of that delay. |
| `watchdogTimeoutMs` | `60000` | How long a worker may stop signalling it is alive before it is killed and replaced — see [stuck worker](#stuck-worker). `0` switches the watch off; anything else must be at least 5000. |
| `readyTimeoutMs` | `30000` | A worker that dies before it [says it is up](#readiness), and within this long, failed to start. At least 1000; there is no "off". |
| `accessLog` | `AccessLogTarget::Stdout` | Where the workers write their access lines: `Stdout` — into the journal, through the master, which under heavy load drops some of them; `File` — each worker to a file of the day itself; `Off` — nowhere. See [access lines](#access-lines). |
| `env` | `[]` | What a worker's environment adds to the master's. |

## Running, stopping, deploying

`run()` returns the process's exit code: `0` after a stop, `70` when a group
[never came up](#a-group-that-never-comes-up). It throws when it cannot start: another
master holds the lock (`MasterAlreadyRunningException`), a directory cannot be created or
written (`RuntimePathException`), the process cannot fork (`ForkRefusedException`).

Stop it with `SIGTERM`/`SIGINT`, or from another process with `MasterControl::stop()`
(see [single instance and state](#single-instance-and-state)). Under systemd or
supervisord the master is the program, in the foreground; it supervises its own workers.

There is no reload. A master holds the application it loaded, and every worker it forks
gets that one, so new code is a new master: a deploy stops the master — it waits for its
workers to finish what they are serving — and starts it again, which loads the new
release. While the new one loads, nobody listens on the port; a proxy in front that
retries a refused connection covers that.

A deploy that replaces files in place without restarting the master mixes versions: a
worker forked after it gets the old code from the master's memory and, for a class the
master had not loaded yet, the new code from disk. Restart the master on every deploy.

## Readiness

A worker tells the master it is up: it is running and doing its work. The master uses it
to tell a group that [never came up](#a-group-that-never-comes-up).

The three servers and the [AMQP consumer](amqp.md#a-supervised-consumer) say it on
their own. Their serve loop does, once its first wait for work comes back with anything
but the server's own failure. A server binds its socket before anything else, so a
socket it cannot have is that failure, and such a worker is never up.

A role that does its work in a loop of its own — a `WaitGroup` loop, a consumer built on
`Queue::consume()`, a Redis subscription — says it itself, once the loop is running:

```php
use SConcur\Scheduler\Scheduler;

while (!$stopRequested) {
    // ... one round of work ...

    Scheduler::get()->markReady(); // only the first call says anything
}
```

`markReady()` does nothing outside a worker a master forked. A worker that never calls it
counts as up once it has run for longer than `readyTimeoutMs`.

How it travels: the same pipe the [watchdog](#stuck-worker) reads, one byte for "up".
The master opens it for every worker, with the watchdog off too.

## Restart policy and `maxRequests`

| Policy | Behaviour |
|---|---|
| `Always` (default) | Restart on any exit — clean or abnormal. |
| `OnFailure` | Restart on a non-zero code, on death by signal, and when the worker reached its own limit. |
| `Never` | One-shot. |

A server's [`maxRequests`](http-server.md#stopping-after-n-requests) (`maxConnections` for
the socket and WebSocket servers, `maxMessages` for the AMQP consumer) stops a worker
once it has handled that much, as a guard against leaks in a long-lived process. Under a
master:

- The worker says why it is stopping, before it finishes its last requests. The master
  forks its replacement at once, without the crash backoff and without counting it as a
  failure, so the slot is not empty for the length of a finish and a start. That is a
  replacement under `Always` and `OnFailure`; under `Never` the worker is let go.
- The limit is spread. Each worker stops at a number drawn between the limit and half
  again as many (`10000` becomes something from `10000` to `15000`). The workers of a
  pool start together and share the load evenly, so with one number they would all
  reach it within the same second and the pool would restart as one. Spread, they come
  apart, and further with each generation. The number drawn is in the worker's
  shutdown line: `stop accepting (reason=limit, limit=12408), draining 3 in-flight`.

The consumer's `maxRuntimeSeconds` and `maxMemoryBytes` are limits of the same kind: a
worker that stops at one says so, and the master replaces it under `OnFailure` too. They
are not spread, and the shutdown line names the one reached:
`stop accepting (reason=limit, maxRuntimeSeconds=3600), draining 1 in-flight`.

Without a master the limit is exact: a one-off consumer told to take `maxMessages: 100`
takes 100.

Crash-loop protection: a worker that crashes right at startup gets a per-slot
exponential backoff (base `restartBackoffMs`, doubled on each fast crash, ceiling
`maxRestartBackoffMs`). A worker that has lived longer than ~1 s is considered
healthy and the backoff is reset. So is one that reached its limit, however short it
lived: it served, and the crashes at start before it do not lengthen the wait after the
next one.

A worker that dies in the middle of its work — `exit()` in a handler, a fatal error, the
OOM killer — takes with it the requests it had accepted. And every worker accepts on a
socket of its own, so the connections the kernel had queued on that socket are reset with
it. With `net.ipv4.tcp_migrate_req` set to `1` (Linux 5.14+, off by default) the kernel
hands that queue to the sockets of the other workers instead. In the failure soak
(`make mem-leak-worker scenario=exit`: 4 workers, `exit()` twice a second, 8 requests in
flight) a death cost 1.17 requests with it off and 0.82 with it on; what the worker had
accepted is lost either way.

## A group that never comes up

When as many workers of a group as it has slots die before any of them is up — exit
with an error or by a signal, or cannot be forked at all — the master stops all its
groups and `run()` returns `70` (`EX_SOFTWARE`):

```
group http: no worker came up — 8 failed before any said it was up; stopping with code 70
```

That is a broken deploy, and systemd or an orchestrator sees it as one, instead of a
master respawning into the same failure with growing delays for as long as nobody reads
the journal. A role that throws as it starts is the usual cause.

A worker counts as up once it [says so](#readiness), or once it has run for longer than
`readyTimeoutMs` — so a role that never calls `markReady()` is not taken for a failure,
as long as it does not crash at startup. A clean exit is not a failure.

## Database connections

Every connection pool lives in a worker: each worker opens its own connections, and
nothing is shared between workers. A connection is not just a socket but state in the
process that opened it — TLS keys, protocol sequence numbers, an open transaction — so it
cannot be handed to another process. The master opens none, and must not have one open
when it forks (see [above](#loading-the-application-before-the-master)). What a database
sees is therefore the sum over the workers:

| Feature | Per worker, per connection pool | Set by |
|---|---|---|
| MySQL, PostgreSQL | up to `maxOpenConns`, 32 when it is `0`; opened on demand, and a pool unused for 5 minutes is closed | `maxOpenConns` of the `Connection` ([MySQL](mysql.md#connection-pool-and-concurrency)) |
| Redis | `poolSize` shared connections (4 by default, 64 at most), plus one for each blocking command in flight and one for each subscription | `poolSize`; the rest by how many run at once ([Redis](redis.md#blocking-commands)) |
| MongoDB | the driver's pool for each server of the deployment, 10 by default | `maxPoolSize` in the URI |

A MySQL or PostgreSQL pool is keyed by the driver, the DSN, the sizes and
`connMaxLifetimeMs`, so two `Connection`s that differ in any of them are two pools
in the same worker.

The defaults add up fast: 16 workers × 32 is 512 connections to MySQL or PostgreSQL,
against a `max_connections` of 151 in MySQL and 100 in PostgreSQL by default. Divide the
budget the server gives you, minus what else connects to it (migrations, cron jobs,
consoles), by the number of workers: 90 connections of PostgreSQL's 100 over 16 workers
is `maxOpenConns: 5`. With `workerCount: 0` the count is the number of logical CPUs, so
the same config needs a smaller cap on a bigger machine.

Leave room for a worker above `workerCount`: a worker that reached its limit finishes
its last requests while its replacement starts, and for that moment both hold their
pools.

When the cap per worker comes out smaller than the concurrency a worker needs, put a
connection pooler in front of the server: PgBouncer for PostgreSQL, ProxySQL for MySQL.
The workers hold connections to the pooler, and it shares fewer server connections
between them. In PgBouncer's transaction mode a server connection changes hands between
transactions, so nothing tied to the session survives outside one: a `SET`, a temporary
table, an advisory lock, `LISTEN`. Whether this library's statements work through such a
pooler has not been tested.

## Self-termination of orphaned workers

If the master dies suddenly (crash, `SIGKILL`, OOM), the workers would otherwise keep
living and holding the port. A server's serve loop compares its parent pid with the
master's (`WorkerContext::masterPid`, or the `masterPid` the server was built with),
asking the extension at most every 100 ms: after the master's death the kernel reparents
the worker, the parent pid stops matching (immune to PID reuse), and the server stops
itself — finishing the requests it has already accepted — and exits, freeing the port.
Outside a master there is nothing to compare with, and the check is off.

## Stuck worker

A handler that goes into a native blocking call (`sleep()`, synchronous PDO/`curl`)
or a single monolithic internal call (a huge `preg_match`, `json_decode`) freezes
the worker's single PHP thread — nothing can preempt a native call (a userland CPU
loop is a different case, see [coroutine switching](coroutine-switching.md)).
`handlerTimeoutMs` inside the extension will return `504` to clients, but the worker
itself stays running and silently drops out of service. Neither `maxRequests` nor
the orphan check helps: a stuck worker does not finish a request, and the master
is alive.

The master finds such a worker by itself and replaces it. Each worker has a pipe to the
master its fork made, beside stdout and stderr, and writes a byte to it every time its
PHP thread comes back to the scheduler, at most twice a second; the master reads the
pipe on every tick and times the last byte by its own clock. Stop coming back and the
bytes stop, which is what "stuck" means here. It costs one clock read per pass, and
nothing at all in a worker running without a master. The pipe is held by the extension,
close-on-exec, so no program the worker starts can mark the worker alive in its place.
The same pipe carries the worker's [readiness](#readiness) and its "stopping at my limit".

That covers a server's serve loop and equally any loop driving an ordinary
`WaitGroup` — a pool of periodic jobs, a consumer written by hand. A worker whose
loop drives neither can mark itself with `Scheduler::get()->markAlive()`, which is a
no-op outside a supervised worker. Past
`watchdogTimeoutMs` (60 s by default) the worker gets `SIGTERM`, then `SIGKILL` if it
is still there `shutdownTimeoutMs` later, and the slot is refilled the way any other
death refills it — under `Always`, and under `OnFailure` too, since a killed worker
did not exit cleanly. Under `Never` the slot stays empty, and a master left with no
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

Until the watchdog acts, a hung worker keeps accepting. Its accept loop runs on the
extension's runtime, not on the stuck PHP thread, so it goes on taking its share of new
connections, and those requests wait with it and die with it. Keep `watchdogTimeoutMs`
as short as the slowest legitimate blocking handler allows.

A worker that hung at startup is replaced after a backoff rather than at once: the
threshold is what it lived through, not work it did, and when the cause is shared — a
wedged upstream every worker calls synchronously — the replacement hangs the same
way, so without the backoff the pool would recycle itself forever. A worker that
served for an hour before hanging is not treated that way: what counts is how long it
kept marking itself alive, not how long it existed.

The 5000 ms floor exists because a worker marks itself at most twice a second: a
threshold near that would read the ordinary gap between two marks as a hang and kill
every worker of the group, replacements included.

Why a mark from PHP and not the telemetry snapshot: snapshots are pushed by a loop
inside the extension, on a runtime thread of its own, so a worker whose PHP thread is
frozen keeps reporting as if nothing had happened. The `workersHung` flag in
[server statistics](admin-stats.md) says the snapshots stopped arriving — a different
question, answered by a different thread.

What the watch leaves alone:

- A worker that has never marked itself alive — one that neither drives the scheduler
  nor calls `markAlive()`, so nothing about its PHP thread is known, and the watchdog
  acts only on evidence of a hang.
- A CPU-bound handler under [preemption](coroutine-switching.md)
  (`preemptionQuantumMs` above `0`): the scheduler parks its coroutine and goes back
  round the loop, so the marks keep coming. Anything waiting on I/O through SConcur keeps
  them coming too, and an idle server's poll wakes every 250 ms, so a worker with no
  requests still marks itself. A native `sleep`, PDO query or `curl` call blocks the PHP
  thread and marks nothing; that is the case the watchdog exists for. With preemption off
  (the default), a handler that computes for longer than the threshold leaves its worker
  unservable that long and gets it replaced: raise `watchdogTimeoutMs` past the longest
  such handler, or set it to `0` for that group.
- A worker that reached its limit after its replacement was forked. It has
  `shutdownTimeoutMs` to finish and gets `SIGKILL` past that, hung or not.
- A worker that is stopping but never finishes — `SIGTERM` taken, with one handler
  waiting on a result that will not arrive. Its serve loop keeps turning, so it keeps
  marking itself alive while serving nothing. Give such handlers a `handlerTimeoutMs`;
  the watchdog cannot see this one.
- A worker that hangs in its role before it reaches its serve loop — in a synchronous
  call made while the role sets up. It has never marked itself alive, and nothing tells
  that apart from a role that has no serve loop at all, so the slot stays running and is
  not refilled.

### Seeing it happen

Three places, in order of how far the news travels:

- The master's journal, on every step: `no heartbeat for …; sending SIGTERM`, then
  `hung worker did not exit on SIGTERM; sending SIGKILL`, then `still alive … after
  SIGKILL` if even that did not free the slot.
- `watchdogKills` per pool in [server statistics](admin-stats.md), and
  `sconcur_pool_watchdog_kills_total` / `sconcur_group_watchdog_kills_total` in the
  Prometheus scrape — not `workersHung`, which counts workers whose snapshots stopped
  (see above).
- A handler of your own, `onWatchdogEvent` of the `WorkerMaster`:

```php
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

$master = new WorkerMaster(runtimeDir: '/run/sconcur', groups: $groups, onWatchdogEvent: $onWatchdogEvent);
```

The handler runs in the master, inside the supervision tick: keep it short, and make no
asynchronous call in it (see [above](#loading-the-application-before-the-master)) —
whatever it throws is written to the journal and dropped, because alerting must not be
able to stop the supervisor from supervising.

Think of it per process rather than per request. One handler blocked in a native call
for a minute is not one slow request: for that minute the worker answers nobody, the
requests it had already accepted included. That is why the default treats it as a
hang. The replacement is not free either — `SIGTERM` is only serviced once the native
call returns, so if it runs past `shutdownTimeoutMs` the worker is `SIGKILL`ed and the
requests it was holding die with it. The way out that costs nothing is not to block
the thread: put such a call behind something that suspends a coroutine instead of the
process, or move it out of the request path.

## Logging

The master writes to a single daily file in `logDir`
(`sconcur-server-2026-06-18.log`); at the day boundary a new file is opened and
files older than `rotateDays` are deleted. The line format is
`[Y-m-d H:i:s.uuuuuu] LEVEL [<scope>]: <message> <context>`, where `<context>` is JSON
(`[]` when empty) and `<scope>` is `master: <pid>` or `worker: <pid> <group> #<index>` —
the group is part of it because a slot index only names a worker together with its pool:

```
[2026-06-18 12:00:00.180210] INFO [worker: 12346 http #0]: spawned []
[2026-06-18 12:01:00.012044] ERROR [worker: 12346 http #0]: exited code=1 uptime=0.3s; restarting in 200ms []
```

The master reads the workers' `stdout`/`stderr` and writes them into the same log
(stderr → `ERROR`, stdout → `INFO`), so crash output and the worker's access log are
preserved next to the exit record.

Records are buffered and flushed once per supervision tick (~100 ms), not per line;
where they go is `logTo` (`Stdout` or `Both` for `docker logs` and journald).

### Access lines

The HTTP and socket servers write a line per request or connection. A group's
`accessLog` says where:

| `accessLog` | Where the lines go |
|---|---|
| `Stdout` (default) | the worker's stdout, which the master reads and writes into the journal with the rest of the worker's output. Under heavy load lines are lost |
| `File` | each worker writes them itself to `<logDir>/<name>-access-YYYY-MM-DD.log`, opened for appending and reopened when the date changes; the journal's `rotateDays` removes the old ones with its own |
| `Off` | nowhere |

With `Stdout` every line of every worker passes through one PHP process — the master —
which reads the pipes once a tick and writes the lines out. Under load that process falls
behind. It spends CPU on the lines, and a worker whose pipe is full drops what its log
queue cannot hold: in the measurement below, at about 11 000 requests a second per
worker, almost half of the lines never reached the journal. A busy server that needs
every access line wants `File`, which takes the reader out of the way: a worker hands a
burst of lines to the kernel in one write. Several workers share the file, so each line
ends with the worker's place in its pool and its pid:

```
2026-10-20T12:00:00.123456 GET / 200 0.12ms http#0 pid=4711
```

A line goes to the file in one write together with the others of its burst, whole lines
only, and appending writes do not cut into each other, so the lines of different workers
never mix. A file that cannot be opened is said in the journal, and the lines go to
stdout instead. Lines the worker's queue had no room for are counted, and the count is
written to the journal at most once a minute (`log: N line(s) dropped in the last
minute`), whatever the target.

Measured on a group of 16 forked workers, the empty endpoint `/`, 256 connections, 20 s,
the processes of the pool on cores 0–15 and `wrk` on the other four, runs of the two
targets alternating — the medians of 3 runs with `stdout` and 5 with `file`:

| `accessLog` | rps | p99 | CPU of the process reading the workers' pipes | Access lines written |
|---|---:|---:|---:|---:|
| `stdout` | 172 422 | 14.9 ms | 0.56 of a core | 52.4% — the rest dropped in the workers |
| `file` | 176 760 | 21.1 ms | none | 100% |

The p99 with `file` is the p99 with no access lines at all: two runs with `off` gave
20.6 and 20.9 ms. What keeps it lower with `stdout` is not known; it is not the lines
written, which are twice as many with `file`.

A server started without a master writes its access lines to its stdout.

## Single instance and state

A single instance is enforced by `flock`: at startup the master takes an exclusive
non-blocking lock on `runtimeDir/<name>.lock`, and a second master with the same
`runtimeDir`+`name` gets `MasterAlreadyRunningException`. The kernel releases the
lock on the process's death (even on `SIGKILL`), so there is no stale-lock problem.

The state file `runtimeDir/<name>-state.json` holds pid, start time, the total worker
count, a per-group breakdown (`groups`: name and worker count) and status; it is
written atomically, removed on a clean exit and left behind after a crash. It is also a
control channel: the master checks it every tick, and its removal (logged at `WARN`) is
the stop signal. So if a `/tmp` cleaner wipes the file, the master stops cleanly with all
its workers.

Another process reaches a running master through `MasterControl`, by the same
`runtimeDir` and `name`:

```php
use SConcur\Worker\MasterControl;

$control = new MasterControl(runtimeDir: '/run/sconcur', name: 'sconcur-server');

$control->isRunning();   // whether a master holds the lock
$control->state();       // MasterState — pid, startedAt, workerCount, groups — or null
$control->stop();        // remove the state file and wait for the master to exit (15 s by default)
```

`isRunning()` takes the same `flock` the master holds rather than trusting the pid in the
state file, which is immune to a stale state and to PID reuse. `stop()` answers `true`
once no master runs — at once when none did — and `false` when it is still running at
the deadline; it goes on stopping, so with a longer `shutdownTimeoutMs` ask again.

## Graceful shutdown

The stop triggers are `SIGTERM`/`SIGINT` or removal of the state file. In either case the
master stops restarting workers and forwards `SIGTERM` to every worker. Each worker
finishes the requests it has already accepted — an HTTP worker also answers what its
open connections and its socket's queue still hold, see
[the HTTP server](http-server.md#graceful-shutdown). The master waits up to the longest
`shutdownTimeoutMs` of its groups, finishes off survivors with `SIGKILL`, then cleans up
the state file, releases the lock and returns `0` — or `70`, when the stop came from a
group that [never came up](#a-group-that-never-comes-up).

## Testing

The tests do not depend on a docker service: `SConcur\Tests\Impl\Worker\TestWorkerMaster`
runs a master as a separate process on a loopback port (`tests/servers/worker/master.php`,
which builds it from a config the test writes, its role running the shared demo server),
and reaches it through `MasterControl` (`tests/servers/worker/control.php`). Coverage
(`tests/feature/Worker/` + `tests/feature/Features/HttpServer/`): supervision (spawning
N workers, restarting a killed one, self-exit on `maxRequests` → restart, the `OnFailure`
and `Never` policies), a group that never comes up (exit `70`), stopping (graceful
shutdown on `SIGTERM` and on removal of the state file, a response still being written,
a client that connected before the stop), singleness and state (rejection of a second
instance, `MasterControl` state and stop, state after a crash), resilience (crash-loop backoff and
its reset by a worker that reached its limit, orphan self-termination), the logger (line
format, context JSON, daily rotation) and the access lines (`File` and `Off`).
`AppBeforeMasterTest` loads an application before the master and checks that a replaced
worker finds it in memory without loading it again, and that what it opened stays with
the worker but reaches no program the worker starts. `WorkerDescriptorsTest` checks that
a program a worker starts gets its stdio and nothing else, `ForkedWorkerTest` that
`exit()` in a worker stops its server, and `ProcessControlTest` that an asynchronous call
in the master's process is refused.

---

See also: [HTTP server](http-server.md), [Server statistics](admin-stats.md).
