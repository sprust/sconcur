English | [Русский](redis.ru.md)

# Redis

Asynchronous work with Redis on top of redis-rs. A command goes into the
extension and runs there while the coroutine is suspended, so commands issued by
many coroutines at the same time travel down one socket as a single pipeline and
come back in one round trip. Outside a `WaitGroup` the same API works
synchronously.

That is what the feature is for. One command in isolation is slower than
phpredis — it pays the boundary crossing on top of the same work — and the
[benchmarks](benchmarks.md) say so plainly.

## Quick start

```php
$redis = new \SConcur\Features\Redis\Connection(
    dsn: 'redis://127.0.0.1:6379/0',
    timeoutMs: 5000,
);

$redis->set('user:1', $json, ttlSeconds: 3600);
$value = $redis->get('user:1');

// a command with no method of its own
$redis->command('SETRANGE', ['user:1', 0, 'x']);

// a batch: one round trip instead of three
$replies = $redis->pipeline()
    ->command('SET', ['a', '1'])
    ->command('INCR', ['a'])
    ->command('GET', ['a'])
    ->execute();
```

Inside `WaitGroup::add(...)` the same calls run concurrently.

## Connection and dsn

```php
new Connection(
    dsn: 'redis://user:password@redis:6379/0',
    timeoutMs: 5000,
    poolSize: 4,
    connMaxLifetimeMs: 0,
);
```

| Parameter | Meaning |
| --- | --- |
| `dsn` | `redis://[user:password@]host:port/db`, `rediss://…` for TLS, `unix:///path/to.sock` for a socket |
| `timeoutMs` | deadline for one command, 30000 by default; `0` means no deadline |
| `poolSize` | multiplexed connections per process for this dsn; 4 by default, 64 at most |
| `connMaxLifetimeMs` | how long a pooled connection is kept before it is replaced; `0` keeps it for the life of the process |

The only query parameter a TCP dsn takes is `protocol`; a unix-socket dsn also
takes `db`, `user` and `pass`, which have nowhere else to go there. Any other
parameter is refused with `InvalidRedisDsnException` rather than ignored — a
parameter read by nothing means the connection does not behave the way the
configuration says it does.

`protocol=3` (RESP3) is refused too. It changes the reply shape of several
commands — `HGETALL` answers a map rather than a flat list, a scored range
answers pairs — and the typed methods below are written against RESP2. Accepting
it would hand them a shape they read wrongly and quietly.

The object owns no socket. Connections live in the extension, keyed by the dsn
and the sizing, and an unused pool is closed five minutes after its last command.

`timeoutMs` is the deadline over a command and over a cursor batch. Subscribing
is bounded by the dial timeout below rather than by it, because a subscription
waits for messages that may never come and the connection's own deadline is not
the right bound for that. The driver's per-command timeout is turned off
deliberately: it defaults to 500 ms, which would cut off every blocking command
by design and every slow one by accident.

Dialling is bounded separately, at five seconds, because the reconnect that runs
behind the commands has nobody's deadline over it and an attempt against a host
that swallows packets has to end by itself. A connection that cannot be made is
re-tried twice and then reported: a wrong password or a closed port arrives as
`RedisConnectionException`, not as a deadline that says nothing about what is
wrong.

## Values are bytes

Nothing is prefixed, serialized or compressed on the way through. A value is the
bytes you pass and the bytes the server returns, binary-safe in both directions.

Arguments may be `string`, `int` or `float` and nothing else. `bool`, `null`,
arrays and objects raise `InvalidRedisArgumentException`: a client that turns
`false` into an empty string writes a value the application never asked for, and
that mistake only ever surfaces later, in the data. Serialize what you store.

Floats are written in their shortest form that reads back as the same double, so
a sorted-set score survives the round trip unchanged.

## Replies

A reply arrives in the shape the server sent it:

| RESP | PHP |
| --- | --- |
| null | `null` |
| integer | `int` |
| simple string, bulk string | `string` (binary-safe) |
| array | `list<mixed>` |
| error | an exception (or `Dto\ErrorReply` inside a pipeline) |

The protocol is RESP2. See the dsn section for why RESP3 is refused rather than
half-supported.

## The typed methods

`command()` is the way to call any command. A method of its own exists where it
shapes the reply or assembles options whose order is easy to get wrong:

- strings and counters: `get`, `set`, `setNx`, `getSet`, `getDel`, `mGet`,
  `mSet`, `incrBy`, `decrBy`, `incrByFloat`, `append`, `strLen`
- keys: `del`, `unlink`, `exists`, `expire`, `expireAt`, `ttl`, `persist`,
  `rename`, `type`, `randomKey`
- hashes: `hGet`, `hSet`, `hMGet`, `hGetAll`, `hDel`, `hExists`, `hIncrBy`,
  `hLen`, `hKeys`, `hVals`
- lists: `lPush`, `rPush`, `lPop`, `rPop`, `lRange`, `lLen`, `lRem`, `lTrim`,
  `blPop`, `brPop`
- sets: `sAdd`, `sRem`, `sMembers`, `sIsMember`, `sCard`, `sPop`
- sorted sets: `zAdd`, `zRange`, `zRangeByScore`, `zRem`, `zScore`, `zCard`,
  `zIncrBy`
- scripts: `eval`, `evalSha`, `scriptLoad`
- server: `ping`, `dbSize`, `info`, `flushDb`

Some of them return something the server did not: `hGetAll`,
`zRange(withScores: true)` and `zRangeByScore(withScores: true)` fold the flat
reply into a map, and `mGet`/`hMGet` key their answers by what was asked for
instead of by position (so a key asked for twice appears once). `ttl` answers
`null` for a key without an expiry and `false` for a key that is not there,
because Redis says `-1` and `-2` and both read like a duration.

`evalSha` falls back to `EVAL` when the server answers `NOSCRIPT`: a flushed
script cache after a restart is expected, not exceptional.

## Pipelines and transactions

```php
$replies = $redis->pipeline()
    ->command('SET', ['a', '1'])
    ->command('INCR', ['a'])
    ->execute();
```

Every command is written before any answer is read, so N commands cost one round
trip. The answer is a list of replies in the order the commands were added.

A failed command does not fail the batch — the server ran the others, and their
results are not worth throwing away over one typo. The failure takes its own
place in the list as a `Dto\ErrorReply` carrying the code and the message.

A send that never reached the server leaves the pipeline as it was, so retrying
it is retrying the same commands.

```php
$replies = $redis->transaction(static function (Pipeline $transaction): void {
    $transaction->command('INCRBY', ['counter', 1]);
    $transaction->command('LPUSH', ['events', 'inc']);
});
```

`transaction()` wraps the batch in `MULTI`/`EXEC`: the server runs it as one unit
with nothing in between. The callback cannot read anything as it goes — a
transaction's replies all arrive after `EXEC` — and calling `execute()` inside it
is refused, because those commands would go out on their own, outside the
transaction, with their replies dropped.

A command the server refuses while queueing aborts the whole transaction:
`EXEC` answers `EXECABORT` and none of it runs. That is the difference from a
plain pipeline, where the other commands run around the bad one.

Commands are written raw inside a pipeline, with `command()` alone. The typed
methods reshape the reply of the command they name, and a pipeline hands back a
plain list; tying the shaping to a position in that list is exactly where a
mistake would not be visible.

Optimistic locking with `WATCH` needs a connection pinned across round trips and
is not supported.

## Blocking commands

```php
$item = $redis->blPop(['queue:jobs'], timeoutSeconds: 5);
```

Redis serves one connection strictly in order, so a command that waits holds up
everything queued behind it on the same socket. A blocking command therefore gets
a connection of its own for the length of the call, and the commands of every
other coroutine keep flowing.

The task deadline has to cover the wait. `blPop`/`brPop` raise it themselves; on
the raw path you pass both, and a deadline shorter than the command's own timeout
is refused rather than cutting the wait short:

```php
$redis->command('BLPOP', ['queue', 5], blocking: true, timeoutMs: 6000);
```

You rarely need `blocking: true`. `BLPOP`, `BRPOP`, `BLMOVE`, `BLMPOP`,
`BRPOPLPUSH`, `BZPOPMIN`, `BZPOPMAX`, `BZMPOP`, `WAIT` and `WAITAOF` are
recognised by name, and `XREAD`/`XREADGROUP` by the `BLOCK` option in their
arguments. The flag is there for a command whose waiting neither of those can
see, and `blocking: false` forces a command onto the shared pool — which is a
way to stall it, and exists for the tests that prove that.

A connection taken for a blocking command is given back when the command ends,
so a consumer loop does not pay a handshake per iteration; one cut off by a
deadline or a stop is given up instead, because it is still owed an answer. One
dsn holds at most 64 of them at once, and asking for the sixty-fifth fails
rather than opening it.

Waiting forever (`timeoutSeconds: 0`) is only coherent with `timeoutMs: 0`. Such
a command is still stopped by the flow ending.

## Cursors

```php
foreach ($redis->scan(match: 'user:*', count: 500) as $key) {
    // ...
}

foreach ($redis->hScan('user:1') as $field => $value) {
    // ...
}
```

`scan`, `hScan`, `sScan` and `zScan` keep the cursor in the extension and hand
PHP one batch at a time, so a keyspace larger than memory is walked without ever
holding it whole. `count` is a hint to the server about how much work one round
trip does; `batchSize` is how many elements cross the boundary at a time. They
are different numbers, and neither bounds the total.

`hScan` and `zScan` yield field => value and member => score; `scan` and `sScan`
yield the elements.

`SCAN` gives no snapshot: a key that existed throughout the walk is returned at
least once, one added or removed along the way may or may not appear, and
duplicates are possible. That is Redis, not this feature.

Abandoning the iterator early is safe — the flow ends, and the cursor and its
connection are released with it. Iterating the same result a second time inside a
coroutine opens a second cursor and leaves the first to that release, so a loop
that re-scans repeatedly should call `scan()` again instead.

## Pub/Sub

```php
$subscription = $redis->subscribe(channels: ['news'], patterns: ['user:*']);

foreach ($subscription as $message) {
    echo $message->channel, ' ', $message->payload, PHP_EOL;
}

$subscription->close();
```

A subscription owns a connection outright, because the protocol puts the
connection itself into subscriber mode. Grouping channels into one subscription
is therefore much cheaper than one subscription per channel: a thousand
subscriptions are a thousand sockets.

Messages are pulled in batches — the extension hands over whatever has arrived,
so a lone message crosses immediately and a fast publisher costs one crossing per
batch instead of one per message. `$message` is a `Dto\Message` with `channel`,
`payload` and `pattern` (empty unless the message came through a pattern
subscription).

`add()` and `remove()` change the channels of a running subscription;
`read()` pulls the next message for a caller who would rather write their own
loop. `close()` releases the connection, and so does the flow ending — the
subscription does not have to be closed by hand, and closing it twice is not an
error.

Sharded channels (`SSUBSCRIBE`) are not supported: they matter in a cluster,
which is not supported either.

## Failures

| Exception | When |
| --- | --- |
| `RedisCommandException` | the server refused the command; `errorCode` holds `WRONGTYPE`, `NOSCRIPT`, `MOVED`, `LOADING`, … |
| `RedisConnectionException` | the server is unreachable, the login was refused, the socket went away mid-command, or the flow was stopped under the command |
| `RedisTimeoutException` | `timeoutMs` ran out |
| `SubscriptionClosedException` | a subscription or a cursor was used after it was closed |
| `RedisTimeoutException` | a cursor batch ran past `timeoutMs` |
| `UnsupportedRedisCommandException` | a command that would change the state of a shared connection |
| `InvalidRedisArgumentException` | an argument that is not a string, an int or a float, or a payload this side could not use |
| `NestedPipelineExecutionException` | `execute()` called inside a `transaction()` callback |
| `InvalidRedisDsnException` | an unusable dsn |

The last four extend `LogicException` — they are usage mistakes, not conditions
of the server — and the rest extend `RedisException`, itself a
`RuntimeException`.

Which one a failure becomes is decided by the core and travels with it, not
guessed from the message: half of an error's text belongs to the server, and a
Lua script answering `redis.error_reply('connect: ...')` must not be able to pick
the exception an application catches.

A connection that drops is re-established behind the commands, but a command
that was in flight when it happened fails, and nothing is retried on its own: the
server may have run it and lost only the answer, and a retried `INCR` would count
twice.

## Refused commands

These change the state of the connection they run on, and ordinary commands share
connections. Each is refused by name, with the replacement in the message:

| Command | Instead |
| --- | --- |
| `SUBSCRIBE`, `PSUBSCRIBE`, `UNSUBSCRIBE`, `PUNSUBSCRIBE` | `subscribe()` and the subscription's own methods |
| `QUIT` | nothing — it would close a socket other coroutines are using |
| `CLIENT REPLY`, `SETNAME`, `SETINFO`, `NO-TOUCH`, `NO-EVICT`, `PAUSE`, `UNPAUSE` | nothing; the read-only rest of `CLIENT` (`LIST`, `INFO`, `ID`) is allowed |
| `SSUBSCRIBE`, `SUNSUBSCRIBE` | not supported |
| `MULTI`, `EXEC`, `DISCARD` | `transaction()` |
| `WATCH`, `UNWATCH` | not supported |
| `SELECT` | the database number in the dsn |
| `AUTH`, `HELLO` | the login, password and `?protocol=` in the dsn |
| `RESET`, `MONITOR`, `SWAPDB` | nothing — these would change what every other coroutine on that socket is talking to |

`CLIENT REPLY OFF|SKIP` is the worst of them and the reason the list checks
subcommands: it tells the server not to answer, and an answer that never comes
shifts the connection's queue by one for good. Nothing errors, so nothing
reconnects, and every later command on that connection reads the previous one's
reply.

A blocking command inside a pipeline is refused too — a pipeline is one unit on
one connection, and a wait inside it would park the batch.

`CLIENT KILL` is allowed and is not harmless: killing this process's own
connections makes one command per killed connection fail before the pool
re-establishes them. It is allowed because it is how a connection is killed at
all, not because it is safe. `DEBUG SLEEP` and a synchronous `FLUSHALL` are
allowed for the same reason and stall the whole server while they run.

## Limits

- **No cluster and no sentinel.** The driver can do both, so this is scope rather
  than impossibility — but a cluster is a different connection model, not a
  switch: keys route by slot, the server answers `MOVED`/`ASK` mid-command, a
  pipeline cannot span slots, and pub/sub needs sharded channels. It has to be
  designed, and v1 is not it.
- **No `WATCH`, so no optimistic locking.** `WATCH` only means anything on the
  connection that later runs `MULTI`/`EXEC`, with the caller reading values in
  between to decide. Ordinary commands here share a connection with every other
  coroutine, so a `WATCH` on it would be watching on their behalf too. Supporting
  it means pinning one connection across several round trips, the way the SQL
  feature pins one for a transaction.
- **No consumer groups over streams.** `XREAD` and `XREADGROUP` work as commands
  (with `blocking: true` where the arguments carry `BLOCK`). What is missing is
  the supervised consumer AMQP has — a worker the scheduler drives, with
  acknowledgement and a graceful drain — which is a subsystem rather than a
  command.
- **No sharded pub/sub.** The driver does not say whether a message arrived as
  `message` or `smessage`, so the kind could not be reported honestly; and
  sharded channels exist for a cluster, which is out anyway.
- **A subscription costs a connection.** In RESP2 a connection in subscriber mode
  accepts only the subscribe commands, `PING` and `QUIT` — it cannot carry
  anything else, so it cannot be shared. RESP3 lifts that restriction, and using
  it to put subscriptions back on the shared pool is the obvious next step, not a
  thing this version does.
- **Nothing is reported to the panel.** The workload sections a worker sends are
  the servers' and the queue consumer's (see [the panel](admin-stats.md)); redis
  adds none of its own, so a worker busy with nothing but redis shows its process
  metrics and nothing about the commands it ran.
- **RESP2 only.** See the dsn section.
- The library's general limits are in the [README](../README.md).
