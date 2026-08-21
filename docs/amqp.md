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

$waitGroup->wait();
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
| `AMQP_NOWAIT` | do not wait for the broker's reply to a declare, delete or bind |
| `AMQP_REQUEUE` | put the refused message back into the queue |
| `AMQP_JUST_CONSUME` | read the consumer this queue already opened instead of opening another |

A fresh `AMQPQueue` is auto-delete, as in the extension: `setFlags()` with a mask
that has no `AMQP_AUTODELETE` turns that off.

## Consuming

`consume(callable $callback = null, ?int $flags = null, ?string $consumerTag = null)`
calls the callback for every delivery and returns when the callback returns
`false`. The callback receives the `AMQPEnvelope` and, optionally, the
`AMQPQueue` it came from.

The consumer must be read in the coroutine that opened it: when the coroutine
ends its flow is stopped, and the Go side cancels the consumer. This is the same
caveat as for `HttpClient`, `SocketServer` and `WsClient`.

Without a callback, `consume()` only registers the consumer; a later call with
`AMQP_JUST_CONSUME` reads it on without sending another `basic.consume`. With no
consumer open, `AMQP_JUST_CONSUME` raises `AMQPQueueException`.

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
been confirmed or rejected, and raises `AMQPQueueException` on timeout. It also
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
building one per request is cheap. A pooled connection with no owners left is
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

## Where the calque differs

The list is closed: everything not in it repeats the extension exactly, and the
parity tests are what keep it that way.

| Method or behaviour | What SConcur does | Why |
| --- | --- | --- |
| `pconnect()`, `pdisconnect()`, `preconnect()` | synonyms of `connect()`, `disconnect()`, `reconnect()`; `isPersistent()` is always true | persistent connections are a php-fpm notion. An SConcur worker is long-lived, and the connection lives in the Go-side pool anyway |
| `setConfirmCallback()`, `setReturnCallback()` | the callbacks are kept in PHP and run from `waitForConfirm()` / `waitForBasicReturn()` | the extension calls them from its own reading loop; here that loop is `waitFor*` |
| `getMaxChannels()`, `getMaxFrameSize()`, `getHeartbeatInterval()` | report what the handshake settled on once connected, the requested values before that | the negotiated values are only known after the handshake |
| `getUsedChannels()` | counted in the Go-side registry, so the call goes to the extension; 0 and a warning when not connected | a PHP-side counter would miss the channels the sweeper closes |
| `getChannelId()` | the number of the channel within its connection, assigned by this feature | the driver does not expose the AMQP channel number |
| `AMQPEnvelope::getClusterId()` | always null | AMQP 0-9-1 excludes cluster-id from publishing, and the driver does not surface it on a delivery either |
| `consume()` | feeds the callback with the deliveries of its own consumer | `ext-amqp` dispatches every delivery of the connection into whichever consume loop is running — a shape that only exists because the extension can run one loop at a time. Here one coroutine per consumer replaces it |
| the confirm callback's `$multiple` | always false | the driver resolves the broker's "up to and including" confirms into individual ones |
| `AMQPException` | extends `RuntimeException` | the project's rule for runtime failures. Every `catch` from ext-amqp code still matches, since `RuntimeException` is an `Exception` |
| `AMQPChannel::__destruct()`, `AMQPConnection::__destruct()` | best-effort close and disconnect | PHP tells the Go side nothing about garbage collection; the sweepers are the backstop |
| ini settings (`amqp.host`, `amqp.auto_ack`, …) | not read | there is no PHP extension here to configure. The defaults are the extension's own, and credentials come from the constructor array |

## Limits

The general limits — CLI only, Linux only, NTS only, no `pcntl_fork` — are in the
[README](../README.md).

- TLS (`cacert`, `cert`, `key`, `verify`) and `AMQP_SASL_METHOD_EXTERNAL` are
  passed to the Go dialer, but the test suite runs against a plain broker, so
  they are not covered by tests.
- `AMQP_IMMEDIATE` is accepted and sent; RabbitMQ has not implemented it since
  3.0 and closes the channel.
- `AMQPQueue::consume()` is bounded by the connection's `read_timeout`, not by a
  per-call timeout.

## Benchmarks

`tests/benchmarks/amqp/` — `publish.php`, `get.php` and `consume.php`, each in
three modes: native `ext-amqp`, SConcur outside a coroutine, SConcur in
coroutines. Run them with `make bench-amqp-publish`, `make bench-amqp-get`,
`make bench-amqp-consume`.

What the numbers say (1000 calls, one broker in the same Docker network; see
[benchmarks](benchmarks.md) for how to read them):

| Operation | native | sync | async |
| --- | --- | --- | --- |
| 1000 publishes | 3.7 ms | 33.9 ms | 28.5 ms |
| 1000 `basic.get` calls | 28.9 ms | 109.9 ms | 26.6 ms |
| 10 queues × 500 pre-filled messages, consumed | 194 000 msg/s | 23 300 msg/s | 116 300 msg/s |

Publishing is where the native extension wins: `basic.publish` expects no reply,
so it costs one write, while every SConcur call also crosses the PHP ↔ Go
boundary. On `basic.get` the async fan already matches the extension, and it is
five times the synchronous path.

On a queue that is already full, the extension consumes faster per message — the
concurrency has nothing to overlap there. The gain is elsewhere: a worker that
waits for messages on several queues serves all of them at once instead of being
pinned to one, which is what
`tests/feature/Features/Amqp/AmqpConsumeTest.php` measures — three consumers
waiting on a 200 ms delay finish in one delay, not three.
