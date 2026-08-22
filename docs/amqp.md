English | [Русский](amqp.ru.md)

# AMQP (RabbitMQ)

An asynchronous AMQP 0-9-1 client. The connection, the channels, the topology,
publishing and consuming all live in the Go extension; PHP stays a thin
orchestrator. Inside a `WaitGroup` dozens of publishes and consumers run at the
same time; outside a fiber the same API works synchronously, as with every
SConcur feature.

The public API is a calque of the PECL [`amqp`](https://github.com/php-amqp/php-amqp)
extension — the same classes, the same methods, the same flags and constants.
Moving an application over is a change of `use` lines.

The main gain is the consumer. In `ext-amqp`, `AMQPQueue::consume()` holds the
PHP thread: the worker is busy with one queue and can do nothing else. Here the
same call suspends only its own coroutine, so one process pulls several queues at
once.

## Quick start

Publishing:

```php
use SConcur\Features\Amqp\AMQPChannel;
use SConcur\Features\Amqp\AMQPConnection;
use SConcur\Features\Amqp\AMQPExchange;

use const SConcur\Features\Amqp\AMQP_DELIVERY_MODE_PERSISTENT;
use const SConcur\Features\Amqp\AMQP_DURABLE;
use const SConcur\Features\Amqp\AMQP_EX_TYPE_TOPIC;
use const SConcur\Features\Amqp\AMQP_NOPARAM;

$connection = new AMQPConnection([
    'host'     => 'sc-rabbitmq',
    'port'     => 5672,
    'login'    => 'sc_user',
    'password' => '_sc_password_567',
    'vhost'    => '/',
]);

$connection->connect();

$channel  = new AMQPChannel($connection);
$exchange = new AMQPExchange($channel);

$exchange->setName('events');
$exchange->setType(AMQP_EX_TYPE_TOPIC);
$exchange->setFlags(AMQP_DURABLE);
$exchange->declareExchange();

$exchange->publish('{"id":1}', 'order.created', AMQP_NOPARAM, [
    'content_type'  => 'application/json',
    'delivery_mode' => AMQP_DELIVERY_MODE_PERSISTENT,
]);
```

Consuming — three queues at once, in one process:

```php
use SConcur\Features\Amqp\AMQPEnvelope;
use SConcur\Features\Amqp\AMQPQueue;
use SConcur\WaitGroup;

$waitGroup = WaitGroup::create();

foreach (['orders', 'invoices', 'emails'] as $queueName) {
    $waitGroup->add(function () use ($connection, $queueName) {
        $queue = new AMQPQueue(new AMQPChannel($connection));

        $queue->setName($queueName);
        $queue->setFlags(AMQP_DURABLE);
        $queue->declareQueue();

        $queue->consume(function (AMQPEnvelope $envelope, AMQPQueue $queue): bool {
            handle($envelope->getBody());

            $queue->ack($envelope->getDeliveryTag());

            return true; // false ends the consume loop
        });
    });
}

$waitGroup->waitAll();
```

While no queue has a message, the PHP thread is free for other work.

Reading a single message:

```php
$envelope = $queue->get(); // ?AMQPEnvelope, null when the queue is empty

if ($envelope !== null) {
    $queue->ack($envelope->getDeliveryTag());
}
```

## Migrating from ext-amqp

The classes carry their original names inside `SConcur\Features\Amqp`, so only
the imports change:

| Before | After |
| --- | --- |
| `use AMQPConnection;` | `use SConcur\Features\Amqp\AMQPConnection;` |
| `use AMQPChannel;` | `use SConcur\Features\Amqp\AMQPChannel;` |
| `use AMQPExchange;` | `use SConcur\Features\Amqp\AMQPExchange;` |
| `use AMQPQueue;` | `use SConcur\Features\Amqp\AMQPQueue;` |
| `use AMQPEnvelope;` | `use SConcur\Features\Amqp\AMQPEnvelope;` |
| `catch (AMQPQueueException)` | `use SConcur\Features\Amqp\AMQPQueueException;` |
| `AMQP_DURABLE` (global constant) | `use const SConcur\Features\Amqp\AMQP_DURABLE;` |

Constants live in the same namespace and are imported with `use const`; the file
that declares them is loaded through composer's `autoload.files`.

The extension itself is not required at runtime. It is a `require-dev`
dependency pinned to an exact version, because the test suite compares the calque
against the installed extension — the set of public methods, every signature and
every constant value (`tests/feature/Features/Amqp/AmqpDriverParityTest.php`),
plus an exchange of real messages between the two implementations on a live
broker (`AmqpBehaviourParityTest`). Raising the pinned version re-runs those
tests, and a failure means the calque has drifted from the original.

## What lives where

Not every call goes to the broker:

| Class | Holds | Goes to the broker |
| --- | --- | --- |
| `AMQPConnection` | credentials, TLS paths, timeouts, the connection handle | `connect()`, `disconnect()`, `reconnect()`, `getUsedChannels()` |
| `AMQPChannel` | the channel handle, prefetch, the confirm and return callbacks | the constructor (opens the channel), `qos()` and the prefetch setters, `startTransaction()`, `confirmSelect()`, `waitForConfirm()`, `waitForBasicReturn()`, `basicRecover()`, `close()` |
| `AMQPExchange` | name, type, flags, arguments | `declareExchange()`, `delete()`, `bind()`, `unbind()`, `publish()` |
| `AMQPQueue` | name, flags, arguments, consumer tag | `declareQueue()`, `delete()`, `bind()`, `unbind()`, `purge()`, `get()`, `consume()`, `ack()`/`nack()`/`reject()`, `recover()`, `cancel()` |
| `AMQPEnvelope` | the body and properties of a delivered message | nothing, it is a value object |

So `setName()`, `setType()`, `setFlags()` and `setArgument()` cost nothing: the
boundary is crossed exactly as often as a real AMQP method is called.

## Flags

The calque takes flags as the integer bit mask `ext-amqp` uses. Each flag is a
boolean parameter of an AMQP 0-9-1 method:

| Flag | Where it lands |
| --- | --- |
| `AMQP_DURABLE` | `durable` of a queue or exchange declaration |
| `AMQP_PASSIVE` | the declare-passive form: check that it exists, do not create |
| `AMQP_EXCLUSIVE` | `exclusive` of a queue declaration and of a consumer |
| `AMQP_AUTODELETE` | `autoDelete` of a declaration |
| `AMQP_INTERNAL` | `internal` of an exchange declaration |
| `AMQP_NOLOCAL` | `noLocal` of a consumer |
| `AMQP_AUTOACK` | the broker treats the message as acknowledged on delivery (`get()`, `consume()`) |
| `AMQP_IFEMPTY` | `ifEmpty` of a queue deletion |
| `AMQP_IFUNUSED` | `ifUnused` of a queue or exchange deletion |
| `AMQP_MANDATORY` | the message must be routable, otherwise it comes back (see below) |
| `AMQP_IMMEDIATE` | `immediate` of a publish (RabbitMQ does not implement it) |
| `AMQP_MULTIPLE` | acknowledge or refuse every delivery up to and including the tag |
| `AMQP_NOWAIT` | do not wait for the broker's reply to a deletion — the only calls that take it |
| `AMQP_REQUEUE` | put the refused message back into the queue |
| `AMQP_JUST_CONSUME` | read the consumer this queue already opened instead of opening another |

A fresh `AMQPQueue` is auto-delete, as in the extension: `setFlags()` with a mask
that has no `AMQP_AUTODELETE` turns that off.

## Consuming

`consume(?callable $callback = null, ?int $flags = null, ?string $consumerTag = null): void`
calls the callback for every delivery and returns when the callback returns
`false`. The callback receives the `AMQPEnvelope` and, optionally, the
`AMQPQueue` it came from.

The consumer must be read in the coroutine that opened it: when the coroutine
ends its flow is stopped, and the Go side cancels the consumer. This is the same
caveat as for `HttpClient`, `SocketClient` and `WsClient`.

Without a callback, `consume()` only registers the consumer; a later call with
`AMQP_JUST_CONSUME` reads it on without sending another `basic.consume`. It has to
be the same `AMQPQueue` object that opened it — with none of its own open,
`AMQP_JUST_CONSUME` raises `AMQPQueueException`.

`cancel()` ends the consumer and leaves the channel open. Deliveries the consumer
received but never acknowledged go back into the queue when the channel is
closed, not when the consumer is cancelled — that is AMQP, not a property of this
implementation.

`read_timeout` of the connection bounds the wait for the next delivery (0 waits
indefinitely). When it expires, the consume loop ends with `AMQPQueueException`
("consumer timeout exceed"), as it does in the extension.

## Publisher confirms and returned messages

```php
$channel->confirmSelect();

$channel->setConfirmCallback(
    function (int $deliveryTag, bool $multiple): bool {
        return true;   // false ends the wait loop
    },
    function (int $deliveryTag, bool $multiple, bool $requeue): bool {
        return true;
    },
);

$exchange->publish('{"id":1}', 'order.created');

$channel->waitForConfirm(2.0);   // seconds; 0 waits until the broker answers
```

`waitForConfirm()` returns once every message published since the last call has
been confirmed or rejected, and raises `AMQPQueueException` ("Wait timeout
exceed") on timeout. The default timeout of 0 means "wait until the broker
answers", as it does in the extension: the wait then ends only on an answer, on
the channel going away, or on the coroutine's flow being stopped. A channel that
was never put into confirm mode has nothing to wait for and runs into the
timeout — pass one. It also
collects the messages the broker returned as unroutable and hands them to the
return callback.

A message published with `AMQP_MANDATORY` that has nowhere to go comes back:

```php
$channel->setReturnCallback(
    function (
        int $replyCode,
        string $replyText,
        string $exchange,
        string $routingKey,
        AMQPBasicProperties $properties,
        string $body,
    ): bool {
        return false;
    },
);

$exchange->publish('nowhere', 'unbound', AMQP_MANDATORY);

$channel->waitForBasicReturn(2.0);
```

Transactions are the usual three calls — `startTransaction()`,
`commitTransaction()`, `rollbackTransaction()` — on a channel that is not in
confirm mode.

## Connections and channels on the Go side

A connection is pooled by its credentials and tuning: two `AMQPConnection`
objects built with the same parameters share one connection to the broker, so
building one per request is cheap. They share it in the broker's eyes as well —
an exclusive queue declared through one is usable through the other, where
`ext-amqp` would give the second object a connection of its own and the broker
would refuse it. `connection_name` is part of the pool key, so naming a
connection is how an application asks for one that is not shared. `connect_timeout` is
the one connection parameter left out of the key: it bounds the dial and nothing the
broker ever sees. A pooled connection with no owners left is
closed after five minutes of idling, and `disconnect()` (or the destructor of a
dropped object) gives up ownership.

Channels are held in a registry keyed by an opaque id, so an acknowledgement may
come from another coroutine — and therefore another flow — than the consumer that
received the message. A channel is closed by `close()`, by the destructor of a
dropped `AMQPChannel`, when its connection dies, or by the sweeper that collects
channels with no consumers that have run no command for 30 minutes.

A delivery tag belongs to the channel that delivered the message: acknowledge it
on the queue whose channel received it. That is also why a channel is not shared
between coroutines — give each coroutine its own.

## TLS and SASL EXTERNAL

TLS is chosen by the certificate paths rather than by a flag: name `cacert`, `cert`
or `key` and the connection dials `amqps://`, name none of them and it dials
`amqp://`. There is no way to ask for TLS without naming at least one file, which
is the rule `ext-amqp` follows too.

| Credential | Meaning |
| --- | --- |
| `cacert` | the authority the broker's certificate is checked against; with none named, the system store is used |
| `cert`, `key` | the client certificate and its private key. The two go together — naming one without the other fails the dial |
| `verify` | `true` by default; `false` skips the check of the broker's certificate altogether |

The files are read by the extension inside the worker's own process, so the paths
are the ones that process sees.

The broker's certificate is checked against the `host` credential, so it has to be
valid for exactly that name, and as a SAN entry — Go does not accept a bare common
name. A dial that cannot read a file, cannot parse the CA or fails the handshake
raises `AMQPConnectionException`.

`AMQP_SASL_METHOD_EXTERNAL` replaces the login and password with the client
certificate: the broker takes the identity out of it, and `login` / `password` are
not sent at all. It needs three things on the broker — the
`rabbitmq_auth_mechanism_ssl` plugin, `EXTERNAL` among its `auth_mechanisms`, and a
user named the way the broker reads the name out of the certificate.

```php
use const SConcur\Features\Amqp\AMQP_SASL_METHOD_EXTERNAL;

$connection = new AMQPConnection([
    'host'        => 'broker.internal',
    'port'        => 5671,
    'vhost'       => '/',
    'cacert'      => '/etc/ssl/rabbit/ca.pem',
    'cert'        => '/etc/ssl/rabbit/client.pem',
    'key'         => '/etc/ssl/rabbit/client.key',
    'verify'      => true,
    'sasl_method' => AMQP_SASL_METHOD_EXTERNAL,
]);
```

The three paths, `verify` and `sasl_method` all belong to the pool key, so two
connections differing in any of them do not share a socket to the broker.

**None of this is covered by tests.** The broker in `docker-compose.yml` listens
without TLS, so the section describes what the code does, not behaviour a test run
confirms. Covering it needs a TLS listener on that broker and a generated
certificate chain; until then, verify it against your own broker before an
application leans on it.

## Scaling a consumer

Several coroutines may consume one queue, in one process and across several, which
is the usual way to scale a worker: every coroutine opens its own channel and its
own consumer, and the broker hands the messages out between them.

```php
$waitGroup = WaitGroup::create();

for ($worker = 0; $worker < 10; ++$worker) {
    $waitGroup->add(function () use ($connection, $queueName) {
        $channel = new AMQPChannel($connection);

        // One unacknowledged message at a time, so the broker gives the next one to
        // whichever coroutine is free instead of filling one buffer.
        $channel->setPrefetchCount(1);

        $queue = new AMQPQueue($channel);

        $queue->setName($queueName);
        $queue->setFlags(AMQP_DURABLE);
        $queue->declareQueue();

        $queue->consume(function (AMQPEnvelope $envelope, AMQPQueue $queue): bool {
            handle($envelope->getBody());

            $queue->ack($envelope->getDeliveryTag());

            return true;
        });
    });
}

$waitGroup->waitAll();
```

Processes are the other axis: the [worker master](worker-master.md) supervises a
pool of them running one script, and a consumer worker needs no listening socket
to be supervised. Each process runs its own Go runtime and its own connection
pool; nothing is shared between them.

What bounds each axis:

| Limit | Value | What to do about it |
| --- | --- | --- |
| channels per connection | 256, the ceiling `ext-amqp` sets (`PHP_AMQP_MAX_CHANNELS`); the 257th fails with `504 channel id space exhausted` | one channel per coroutine means ~255 consumers per connection; a `connection_name` gives an application a connection of its own, and with it another 256 |
| one connection is one socket | every channel of a connection is multiplexed over it, and the driver serializes the frames it writes | spread the coroutines over several named connections before the socket, not the channel count, becomes the ceiling |
| a process is one PHP thread | coroutines overlap the waiting, not the work: a handler that computes blocks the others while it runs | scale processes for CPU-bound handlers, coroutines for handlers that wait |

Two rules the sections above state and this one depends on: a consumer is read in
the coroutine that opened it, and a channel is not shared between coroutines —
the commands of one channel are serialized, so ten coroutines on one channel are
a queue of ten.

## A supervised consumer

`Features\Amqp\Consumer\QueueConsumer` is the worker shape of the section above:
several queues pulled at once by one process, each queue weighted by how many
coroutines pull it, and a stop that drains rather than cuts. It is what a
[worker master](worker-master.md) group runs.

```php
// consumer.php
use SConcur\Features\Amqp\AMQPEnvelope;
use SConcur\Features\Amqp\AMQPQueue;
use SConcur\Features\Amqp\Consumer\QueueConsumer;

require __DIR__ . '/vendor/autoload.php';

// Everything about the queues comes from argv, which is how a master configures
// its workers. The credentials do not: a password in argv is visible in `ps`.
$queueConsumer = QueueConsumer::fromArgs($_SERVER['argv']);

$connection = new AMQPConnection([...]);
$connection->connect();

$queueConsumer->consume(
    connection: $connection,
    handler: static function (AMQPEnvelope $envelope, AMQPQueue $queue): void {
        handle($envelope->getBody());

        $queue->ack($envelope->getDeliveryTag());
    },
);
```

The group that runs it:

```json
{
  "name": "orders",
  "workerScript": "/app/consumer.php",
  "workerCount": 2,
  "server": {
    "queues": [
      { "name": "orders", "coroutineCount": 8 },
      { "name": "invoices", "coroutineCount": 2, "prefetchCount": 5 }
    ],
    "prefetchCount": 1,
    "maxMessages": 10000
  }
}
```

| Flag | Default | Purpose |
| --- | --- | --- |
| `queues` | — (required) | The queues and their weights. A list of objects: `name`, `coroutineCount` (default 1), optional `prefetchCount`. |
| `prefetchCount` | `1` | Unacknowledged messages one coroutine may hold, unless its queue names its own. |
| `maxMessages` | `0` (no limit) | Drain and exit after this many messages. |
| `maxRuntimeSeconds` | `0` (no limit) | Drain and exit after this long. |
| `maxMemoryBytes` | `0` (no limit) | Drain and exit once the PHP heap passes this. |
| `drainTimeoutMs` | `5000` | How long a stop waits for the handlers that are mid-message. |
| `masterPid` | none | Injected by the master; the worker drains as soon as it is orphaned. |

`queues` is a list of objects rather than a delimited string because AMQP allows
almost any UTF-8 in a queue name, colons included — names like `tenant:1:orders`
are ordinary, and any separator inside a name would make the parse ambiguous. The
master JSON-encodes it into the flag; there is no shell on the way.

The list is validated before the first `basic.consume`: a missing or duplicated
name, a weight below one, and the channel budget — a coroutine is a channel, and
one connection carries 255 of them, so a worker asking for more is told at startup
instead of meeting `504 channel id space exhausted` under load.

What it does not do: declare anything. Topology belongs to whoever owns it, and a
consumer that redeclared a queue with the wrong flags would take the channel down
with a `406` instead of consuming — so the worker script declares what it owns
before handing over, and `queueSpecs()` tells it what that is. A pool started
before anything published into its queue would otherwise crash-loop on a `404`.

The pool reports itself to the [panel](admin-stats.md) like any server: how many
coroutines it has consuming, what they delivered, acknowledged and refused, and
how long a delivery spends in a handler.

### Stopping

`SIGTERM` (or one of the limits above) drains in two steps, because the two halves
of a pool are in different states.

A coroutine that is running a handler finishes that message and acknowledges it,
then ends. A coroutine that is waiting for a delivery has no callback to return
from, so once nothing is mid-message any more the group is stopped and those
waits end with it. Cancelling them instead would not do: a `basic.cancel` from
another coroutine closes the delivery stream, and the consumer parked on it
surfaces that as a failure rather than a clean end.

Keep `drainTimeoutMs` below the master's `shutdownTimeoutMs`, or `SIGKILL` lands
in the middle of the drain.

A handler that throws is reported through the optional `onError` callback and ends
the coroutine it ran in — closing that channel hands the message back to the
broker exactly once, where answering for a handler that may already have
acknowledged it risks a double settle. The other coroutines keep going, so one
poisoned message costs a share of the capacity, not the worker.


## Errors

The exception classes are the extension's, and so is the reply code on them:

```php
try {
    $queue->declareQueue();
} catch (AMQPQueueException $exception) {
    if ($exception->getCode() === 404) {
        // declare it and try again
    }
}
```

A reply code the broker names travels with the exception, and which exception is
raised follows the extension:

| What happened | Exception | Code |
| --- | --- | --- |
| the broker refused the method (404, 406, …) | the one of the class whose method was called | the reply code |
| the broker answered with a connection-level code (5xx), or the connection died | `AMQPConnectionException` | the reply code, 0 for a network failure |
| the channel is gone — the broker closed it over an earlier failure | `AMQPChannelException` | 0 |

A failure the broker punishes with a closed channel (a passive declare of a queue
that does not exist, a publish to a missing exchange) leaves the `AMQPChannel`
closed: `isConnected()` reports it, every later call on it raises
`AMQPChannelException`, and the Go side has already released it. Open a new
channel to carry on — the connection is untouched.

A connection-level failure marks the `AMQPConnection` disconnected as well, so
the usual recovery reads the same as it did on the extension:

```php
if (!$connection->isConnected()) {
    $connection->reconnect();
}
```

## Where the calque differs

The list is closed: everything not in it repeats the extension exactly, and the
parity tests are what keep it that way.

| Method or behaviour | What SConcur does | Why |
| --- | --- | --- |
| `pconnect()`, `pdisconnect()`, `preconnect()` | synonyms of `connect()`, `disconnect()`, `reconnect()`; `isPersistent()` is always true | persistent connections are a php-fpm notion. An SConcur worker is long-lived, and the connection lives in the Go-side pool anyway |
| two `AMQPConnection` objects with the same credentials | share one connection to the broker, and everything scoped to a connection with it (exclusive queues, connection-wide qos) | the pool is what makes building a connection per request cheap; `connection_name` opts out of the sharing |
| `disconnect()` | closes every channel opened through this connection object | the handle is what the Go side hands the channels out on; releasing it releases them |
| `setConfirmCallback()`, `setReturnCallback()` | the callbacks are kept in PHP and run from `waitForConfirm()` / `waitForBasicReturn()` | the extension calls them from its own reading loop; here that loop is `waitFor*` |
| the confirm callback's `$multiple`, the nack callback's `$requeue` | always false | the driver resolves the broker's "up to and including" confirms into individual ones, and does not carry the requeue flag of a nack |
| `getMaxChannels()`, `getMaxFrameSize()`, `getHeartbeatInterval()` | report what the handshake settled on once connected, the requested values before that | the negotiated values are only known after the handshake |
| `getUsedChannels()` | counted in the Go-side registry, so the call goes to the extension; 0 and a warning when not connected | a PHP-side counter would miss the channels the sweeper closes |
| `getChannelId()` | the number of the channel within its connection, assigned by this feature | the driver does not expose the AMQP channel number |
| `AMQPEnvelope::getClusterId()` | always null | AMQP 0-9-1 excludes cluster-id from publishing, and the driver does not surface it on a delivery either |
| `consume()` | feeds the callback with the deliveries of its own consumer | `ext-amqp` dispatches every delivery of the connection into whichever consume loop is running — a shape that only exists because the extension can run one loop at a time. Here one coroutine per consumer replaces it |
| `AMQP_JUST_CONSUME` | reads the consumer *this* `AMQPQueue` opened | the extension resolves it through the channel's consumer registry, so any queue object on that channel picks it up. Here a consumer is a delivery stream owned by the coroutine that opened it, and handing that stream to another object is how two coroutines end up reading one — the same reason `consume()` feeds its own consumer only |
| `read_timeout` on a consumer | ends the consumer as well as the loop | the stream and the consumer behind it are one resource here; the extension leaves the consumer registered |
| `AMQPException` | extends `RuntimeException` | the project's rule for runtime failures. Every `catch` from ext-amqp code still matches, since `RuntimeException` is an `Exception` |
| `AMQPChannel::__destruct()`, `AMQPConnection::__destruct()` | close and disconnect, without waiting for the broker to answer | PHP tells the Go side nothing about garbage collection, and a coroutine that was stopped has no flow left to answer on — so the release is sent and not awaited. It is what keeps a stopped coroutine from leaving its channel open |
| `waitForBasicReturn()` after `waitForConfirm()` | finds nothing and runs into its timeout | `waitForConfirm()` collects the returned messages too, as it does in the extension. Both implementations refuse the sequence; the extension answers it at once with "unexpected method received", where this one waits out the deadline |
| the constants | live in the feature's namespace, imported with `use const` | the global names belong to the extension itself and would collide with it wherever both are installed |
| `AMQPDecimal`, `AMQPTimestamp` | `final readonly`, which the project's own rules forbid | the parity test compares those modifiers with the extension: a subclass of either works there and must work here |
| an `AMQPTimestamp` above `PHP_INT_MAX` | refused when published | AMQP counts unsigned 64-bit seconds and `AMQPTimestamp::MAX` allows the whole range, but neither a PHP int nor the Go time the field is built from can hold its upper half. The extension wraps it into a date before 1970; this says so instead |
| a channel of a connection that was reconnected | reports `isConnected() === false` | releasing the handle closes its channels on the Go side, so the objects standing for them stop claiming to be open — the `if (!$channel->isConnected())` guard has to fire |
| publisher confirms and returned messages a wait loop never collects | the oldest are dropped past a few hundred | a returned message carries its whole body, and an application that publishes with `AMQP_MANDATORY` and never calls `waitForBasicReturn()` would otherwise fill the heap. The extension keeps none at all when no callback is registered |
| ini settings (`amqp.host`, `amqp.auto_ack`, …) | not read | there is no PHP extension here to configure. The defaults are the extension's own, and credentials come from the constructor array |

## Limits

The general limits — CLI only, Linux only, NTS only, no `pcntl_fork` — are in the
[README](../README.md).

- TLS and `AMQP_SASL_METHOD_EXTERNAL` are implemented but not covered by tests,
  because the compose broker listens without TLS — see
  [TLS and SASL EXTERNAL](#tls-and-sasl-external).
- `AMQP_IMMEDIATE` is accepted and sent; RabbitMQ has not implemented it since
  3.0 and closes the channel.
- `AMQPQueue::consume()` is bounded by the connection's `read_timeout`, not by a
  per-call timeout.

## Benchmarks

`tests/benchmarks/amqp/` — `publish.php`, `get.php` and `consume.php`, each in
three modes: native `ext-amqp`, SConcur outside a coroutine, SConcur in
coroutines. Run them with `make bench-amqp-publish`, `make bench-amqp-get`,
`make bench-amqp-consume`.

The numbers are in [benchmarks](benchmarks.md#amqp-rabbitmq), where they sit
beside the other features and are read the same way. The short of it: publishing
is where the native extension wins and nothing can be done about it —
`basic.publish` expects no reply, so it costs one write while every SConcur call
also crosses the PHP ↔ Go boundary, and there is nothing to overlap. `basic.get`
does wait for the broker, and running the calls at the same time recovers most of
that.

What none of those tables measure is the reason this feature exists. They pit one
call against one call on a queue that already has its messages, where concurrency
has nothing to hide behind. The gain is a worker that waits on several queues at
once: `AMQPQueue::consume()` in `ext-amqp` holds the PHP thread, so a process is
pinned to one queue, while here the same call suspends only its coroutine — three
consumers waiting on a 200 ms delay finish in one delay, not three, which is what
`tests/feature/Features/Amqp/AmqpConsumeTest.php` measures.
