English | [Русский](amqp.ru.md)

# AMQP (RabbitMQ)

An asynchronous AMQP 0-9-1 client. The connection, the channels, the topology,
publishing and consuming all live in the Go extension; PHP stays a thin
orchestrator. Inside a `WaitGroup` dozens of publishes and consumers run at the
same time; outside a fiber the same API works synchronously, as with every
SConcur feature.

The gain is the consumer. In `ext-amqp` and in `php-amqplib` alike, consuming a
queue holds the PHP thread: the worker is busy with one queue and can do nothing
else. Here the same loop suspends only its own coroutine, so one process pulls
several queues at once.

## Quick start

Publishing:

```php
use SConcur\Features\Amqp\Connection;
use SConcur\Features\Amqp\ExchangeTypeEnum;
use SConcur\Features\Amqp\Message;

$connection = new Connection('amqp://sc_user:_sc_password_567@sc-rabbitmq:5672/%2f');

$channel = $connection->channel();

$exchange = $channel->exchange('events');

$exchange->declare(type: ExchangeTypeEnum::Topic, durable: true);

$exchange->publish(
    new Message('{"id":1}', contentType: 'application/json', persistent: true),
    routingKey: 'order.created',
);
```

Straight into one queue, without an exchange of your own:

```php
$queue = $channel->queue('orders');

$queue->declare(durable: true);

$queue->publish('a plain body is a message too');
```

Consuming — three queues at once, in one process:

```php
use SConcur\Features\Amqp\Delivery;
use SConcur\WaitGroup;

$waitGroup = WaitGroup::create();

foreach (['orders', 'invoices', 'emails'] as $queueName) {
    $waitGroup->add(function () use ($connection, $queueName): void {
        // A channel of its own: the commands of one channel are serialized.
        $channel = $connection->channel(prefetchCount: 1);

        foreach ($channel->queue($queueName)->consume() as $delivery) {
            handle($delivery->body);

            $delivery->ack();
        }
    });
}

$waitGroup->waitAll();
```

While no queue has a message, the PHP thread is free for other work.

Reading a single message:

```php
$delivery = $queue->get();   // ?Delivery, null when the queue is empty

$delivery?->ack();
```

## What lives where

Not every call goes to the broker:

| Class | What it is | Goes to the broker |
| --- | --- | --- |
| `ConnectionOptions` | host, credentials, timeouts, TLS material — settled once, `readonly` | nothing, it is a value object |
| `Connection` | the connection handle | `connect()`, `close()`, `channel()`, `usedChannels()` |
| `Channel` | the channel handle | the constructor (opens the channel), `prefetch()`, `publish()`, `publishConfirmed()`, `enableConfirms()`, `waitForConfirms()`, `ack()`/`nack()`/`reject()`, `get()`, `consume()`, `close()` |
| `Queue` | a name and the channel to run on — a handle, built for free | `declare()`, `declarePassive()`, `bind()`, `unbind()`, `delete()`, `purge()`, `publish()`, `publishConfirmed()`, `get()`, `consume()` |
| `Exchange` | the same, for an exchange | `declare()`, `declarePassive()`, `bind()`, `unbind()`, `delete()`, `publish()`, `publishConfirmed()` |
| `Message` | the body and properties of a message being published | nothing, it is a value object |
| `Delivery` | a delivered message, and the means to settle it | `ack()`, `nack()`, `reject()` |

`$channel->queue('orders')` and `$channel->exchange('events')` cost nothing and
talk to nobody: the boundary is crossed exactly as often as a real AMQP method is
called.

## Connecting

A connection takes either an AMQP URI or a `ConnectionOptions`:

```php
use SConcur\Features\Amqp\Connection;
use SConcur\Features\Amqp\ConnectionOptions;

$connection = new Connection('amqp://login:password@broker:5672/app');

$connection = new Connection(new ConnectionOptions(
    host:                  'broker',
    port:                  5672,
    login:                 'login',
    password:              'password',
    vhost:                 'app',
    connectTimeoutSeconds: 3.0,
    readTimeoutSeconds:    0.0,
    writeTimeoutSeconds:   0.0,
    rpcTimeoutSeconds:     0.0,
    heartbeatSeconds:      60,
    channelMax:            256,
    frameMaxBytes:         131072,
    connectionName:        'api',
));
```

The URI is the form RabbitMQ documents, and its path is the vhost — with the
encoding the specification asks for rather than the intuitive one:

| URI | vhost |
| --- | --- |
| `amqp://broker` | `/`, the default |
| `amqp://broker/` | the empty vhost — a legal one, and not the same thing |
| `amqp://broker/app` | `app` |
| `amqp://broker/%2f` | `/` |

Everything else is a query parameter, named as the broker's own documentation
names it: `heartbeat`, `connection_timeout` (milliseconds), `channel_max`,
`frame_max`, `cacertfile`, `certfile`, `keyfile`, `verify`, `auth_mechanism`,
`connection_name`. `amqps://` turns on TLS and defaults the port to 5671.

Opening is lazy: the constructor touches nothing, and the first command opens the
connection. `connect()` is there for a worker that would rather fail at start-up
than under load. A connection that *died* is not reopened by itself — the next
call says so, and reconnecting is `close()` then `connect()`.

The timeouts bound different things: `connectTimeoutSeconds` the dial,
`writeTimeoutSeconds` a publish, `readTimeoutSeconds` a consumer's wait for the next
delivery, and `rpcTimeoutSeconds` every other single method. They are seconds because
that is the unit an AMQP URI and the broker's own documentation state them in; the wire
carries milliseconds. `0` leaves the Go side to apply its own default, and for
`readTimeoutSeconds` it means "wait indefinitely".

## Topology

A declaration is one call with named arguments:

```php
use SConcur\Features\Amqp\ExchangeTypeEnum;

$queue = $channel->queue('orders');

$info = $queue->declare(
    durable:    true,
    exclusive:  false,
    autoDelete: false,
    arguments:  ['x-max-priority' => 10],
);

$info->messageCount;    // ready for delivery at the moment of the declaration
$info->consumerCount;   // consumers attached at that moment
$info->name;            // the broker's generated name, when the declaration asked for one

$channel->exchange('events')->declare(type: ExchangeTypeEnum::Topic, durable: true);

$queue->bind(exchange: 'events', routingKey: 'order.*');
$queue->unbind(exchange: 'events', routingKey: 'order.*');

$queue->purge();                                   // how many messages were removed
$queue->delete(ifUnused: false, ifEmpty: false);   // how many went with the queue

$channel->exchange('events')->delete(ifUnused: false);
```

`declarePassive()` is the other half: it asks whether the queue or exchange
exists, without creating or changing anything, and answers a missing one with a
`404` that closes the channel. It is a separate call rather than a flag because
it is a different question — and because a poll for a message count should not
have to repeat every setting the queue was declared with.

An empty queue name asks the broker to generate one; it arrives in the answer and
becomes the handle's own name.

`ExchangeTypeEnum` is `Direct`, `Fanout`, `Topic` or `Headers`.

## Publishing

```php
$channel->publish('a body', exchange: 'events', routingKey: 'order.created');

$channel->publish(
    new Message(
        body:          '{"id":1}',
        contentType:   'application/json',
        persistent:    true,
        priority:      3,
        correlationId: 'order-1',
        expiration:    '60000',
        headers:       ['x-attempt' => 1],
    ),
    exchange:   'events',
    routingKey: 'order.created',
    mandatory:  true,
);

$queue->publish('into this queue');                 // through the default exchange
$channel->exchange('events')->publish('...', routingKey: 'order.created');
```

`persistent: true` is the delivery mode: a durable queue writes the message to
disk before acknowledging it. `mandatory: true` asks the broker to send back a
message it cannot route instead of dropping it — which only means something if
something waits for the answer, and that is `publishConfirmed()`.

`Message` is `readonly` and its properties are named constructor arguments, so a
misspelled one is a TypeError at the call site rather than a property the broker
never receives.

A message nobody set a content type on carries none. AMQP distinguishes an absent
property from an empty one, and `ext-amqp`'s habit of publishing `text/plain` for
everything is not carried over.

## Publisher confirms

`basic.publish` expects no reply, so `publish()` returns as soon as the message is
handed over and says nothing about the broker having stored it.
`publishConfirmed()` is the call that waits:

```php
$channel->publishConfirmed(
    new Message('{"id":1}', persistent: true),
    exchange:   'events',
    routingKey: 'order.created',
    timeoutSeconds: 5.0,
);
```

It returns when the broker has taken responsibility for the message, and raises
otherwise:

| Exception | What happened |
| --- | --- |
| `PublishNackedException` | the broker answered `basic.nack` — it could not store the message |
| `UnroutableMessageException` | the message reached no queue; `getReturnedMessage()` hands it back, properties and all |
| `PublishConfirmTimeoutException` | no answer within the deadline. The message may still be on its way |

Confirm mode is turned on by the first `publishConfirmed()`; `enableConfirms()`
does it explicitly. AMQP has no way to turn it back off, and `waitForConfirms()` on
a channel that never entered it raises `ChannelException` rather than waiting out
its deadline for something that cannot arrive.

Publishing a batch needs no API of its own — concurrency is the batch:

```php
$waitGroup = WaitGroup::create();

foreach ($messages as $message) {
    $waitGroup->add(function () use ($connection, $message): void {
        $channel = $connection->channel();

        $channel->queue('orders')->publishConfirmed($message, timeoutSeconds: 5.0);

        $channel->close();
    });
}

$waitGroup->waitAll();
```

`waitForConfirms(timeoutSeconds)` is the other shape: publish a run of messages on one
channel, then drain their confirms in one wait. It fails on the first message the
broker did not take.

## Consuming

`consume()` returns a generator of `Delivery`:

```php
$channel = $connection->channel(prefetchCount: 10);

foreach ($channel->queue('orders')->consume() as $delivery) {
    handle($delivery->body);

    $delivery->ack();
}
```

The prefetch belongs to the channel, not to the consumer: it is what bounds how
many unacknowledged deliveries the broker pushes at it. A channel opened with no
argument gets 3 (`Channel::DEFAULT_PREFETCH_COUNT`); `$channel->prefetch()` changes
it later. One is the right answer for a pool of coroutines — it hands the next
message to whichever one is free instead of filling the buffer of a busy one.

The generator owns the consumer. Leaving the loop — a `break`, a `return`, an
exception, or the coroutine being unwound by `WaitGroup::stop()` — cancels it and
gives the delivery stream back; there is no `cancel()` to remember.

The full form:

```php
$queue->consume(
    autoAck:     false,
    consumerTag: '',        // empty asks the broker for one
    exclusive:   false,
    noLocal:     false,
    arguments:   ['x-priority' => 10],
);
```

The consumer must be read in the coroutine that opened it: when the coroutine
ends its flow is stopped, and the Go side cancels the consumer. This is the same
caveat as for `HttpClient`, `SocketClient` and `WsClient`.

The loop ends quietly only when this coroutine's flow is stopped. Everything else
that takes the consumer away raises: the broker cancelling it (its queue was
deleted, a node failed over), the channel dying, and the connection's `readTimeoutSeconds`
passing with nothing delivered. The last one is worth stating plainly, because it
looks like an ending and is not: the queue is merely idle, so a wait that outran the
deadline raises `QueueException` ("Consumer timeout exceed") rather than ending the loop —
a worker that read the silence as "the queue is closed" would stop reading a queue that
still has work coming. Leaving the loop cancels the consumer on the way out, as any other
exit from it does; what is left behind is the queue, not a consumer nobody reads.

Deliveries a consumer received but never acknowledged go back into the queue when
the channel is closed, not when the consumer is cancelled — that is AMQP, not a
property of this implementation.

## Settling a delivery

```php
$delivery->ack();                    // the broker may forget the message
$delivery->ack(multiple: true);      // and everything before it on this channel
$delivery->nack(requeue: true);      // refuse; put it back
$delivery->reject();                 // refuse exactly this one, do not put it back
```

Settling belongs to the delivery, not to the queue. An acknowledgement names its
message by delivery tag on the channel it arrived on, so
`$queue->ack($envelope->getDeliveryTag())` made the caller carry a number from one
object to another and gave them the chance to carry the wrong one.

Settling twice is refused locally, before it reaches the wire: the broker answers
a second acknowledgement of the same tag by closing the channel, which takes every
other consumer on it down as collateral. `isSettled()` says whether it has been.

A delivery holds its channel weakly, so one an application kept does not hold the
channel — and through it the connection — open. Settling it after the channel is
gone raises `ChannelException`.

`Message::fromDelivery($delivery)` builds the message back out of a delivery,
which is what a retry onto another exchange or a hand-rolled dead-letter hop
needs.

## Retries and delays

There is nothing to configure here, because AMQP has no retry primitive: a broker
either holds a message or hands it back at once. Everything below is built out of
what the broker does have — dead-lettering and TTL — and all of it works with the
API as it stands.

### A delay queue

The standard shape. The main queue dead-letters into a queue nobody consumes; that
queue expires its messages back into the main one:

```php
$main = $channel->queue('orders');
$wait = $channel->queue('orders.wait');

$main->declare(durable: true, arguments: [
    'x-dead-letter-exchange'    => '',
    'x-dead-letter-routing-key' => 'orders.wait',
]);

$wait->declare(durable: true, arguments: [
    'x-message-ttl'             => 500,      // the delay
    'x-dead-letter-exchange'    => '',
    'x-dead-letter-routing-key' => 'orders',
]);
```

A handler that refuses the message without requeueing it starts the round trip:

```php
$delivery->nack(requeue: false);   // -> orders.wait -> 500 ms -> orders
```

Measured on the compose broker, a 500 ms TTL brings the message back after ~510 ms.

### The attempt count comes from the broker

Every dead-letter hop is recorded in the `x-death` header, so nothing needs to
count attempts by hand:

```php
$attempt = 0;

foreach ($delivery->header('x-death') ?? [] as $death) {
    if ($death['queue'] === 'orders' && $death['reason'] === 'rejected') {
        $attempt = $death['count'];
    }
}

if ($attempt >= 5) {
    $channel->queue('orders.failed')->publish(Message::fromDelivery($delivery));

    $delivery->ack();   // settled: it is somebody else's problem now

    return;
}

$delivery->nack(requeue: false);
```

Two things to know about the header, both observed on the broker in
`docker-compose.yml` rather than assumed:

- a message that has never been dead-lettered carries no `x-death` at all, which
  is why the loop starts from zero rather than reading an entry that is not there;
- it is a field array of field tables, one per queue the message was dead-lettered
  from, most recent first — so after the second delivery it reads
  `orders.wait/expired=1`, `orders/rejected=1`, and the counts climb together with
  every round. Looking the entry up by `queue` and `reason` says what is meant;
  indexing the array by position happens to work and stops working the moment the
  topology grows another hop.

It counts *deliberate* refusals only. A message returned because the worker died
was refused by nobody, and this counter does not move — see
[when the worker itself dies](#when-the-worker-itself-dies).

### A delay per message

`expiration` is the message's own TTL, in milliseconds as a decimal string, so one
wait queue can serve delays that differ per message:

```php
$wait->publish(new Message($body, expiration: '300'));
```

One caveat, and it is the broker's rather than this feature's: a queue expires its
messages from the head. A message with a 1-second TTL sitting behind one with a
10-second TTL leaves after ten seconds, not one. A growing backoff is therefore
usually built as several wait queues with fixed TTLs — `orders.wait.1s`,
`orders.wait.10s`, `orders.wait.60s` — with the handler choosing which one to
dead-letter into by the attempt count, rather than as one queue with per-message
expirations.

### Republishing with your own counter

When the routing has to change, or the counter has to mean something of your own,
republish instead of dead-lettering. `Message::fromDelivery()` rebuilds the message
so only what you change differs:

```php
$attempt = ($delivery->header('x-attempt') ?? 0) + 1;

$channel->queue('orders.wait.10s')->publish(new Message(
    body:    $delivery->body,
    headers: ['x-attempt' => $attempt] + $delivery->properties->headers,
));

$delivery->ack();
```

### A short delay in the handler

`Sleeper::usleep()` suspends the coroutine and not the process, so the other
coroutines of the worker keep going. It is the right tool for tens of
milliseconds and the wrong one for minutes: the delivery stays unacknowledged for
the whole wait, holding its prefetch slot and counting against the broker as in
flight.

### Bounding one job

A handler that hangs holds its coroutine for ever, and with a prefetch of one that
coroutine will never take another message. `handlerTimeoutMs` puts a deadline on it:

```json
{
  "queues": [{ "name": "orders", "coroutineCount": 8 }],
  "prefetchCount": 1,
  "handlerTimeoutMs": 30000
}
```

A job that runs past it is unwound where it stands — the handler's `finally` blocks
run — and the delivery is refused exactly like one whose handler threw, so
`requeueOnFailure` decides where it goes and `onError` is told, with a
`CoroutineTimeoutException`. The coroutine survives and takes the next message; one
slow job costs one message and not a consumer.

**Where the message goes is worth being sure about**, because the default is the
harshest of the three and it is easy to reach by accident:

| The queue and the setting | What happens to the message |
| --- | --- |
| the default, no dead-letter exchange | **dropped** — refused without requeue, and nothing catches it |
| the default, with a dead-letter exchange | sent there, to be looked at later |
| `requeueOnFailure: true` | put back into the queue |

So `handlerTimeoutMs` on a queue with no dead-letter exchange is a decision to throw
the job away when it runs long. That is often right — it has already spent its
budget — but it is a decision, and a queue carrying anything worth keeping wants a
dead-letter exchange before it gets a deadline.

`requeueOnFailure: true` and a deadline together are almost always a trap: a job that
did not fit in thirty seconds will not fit in thirty seconds again, so it goes round
for ever, and each round is slower than an ordinary failure because it burns the
whole allowance first. If it has to go back, bound the rounds — a quorum queue with
`x-delivery-limit` counts them, where `x-death` does not: a requeue is not a
dead-letter hop, so the counter above never moves.

One thing this is not: a worker being stopped while a handler is still working. There
the application never decided anything, so the message is not settled at all and
comes back on its own — see [when the worker itself dies](#when-the-worker-itself-dies)
for the difference between "the runtime answered for the job" and "nobody answered".

One limit comes with it, from the mechanism underneath — see
[coroutine timeout](coroutine-timeout.md). A handler already inside a broker call is not
cut until that call returns: that one is bounded by the connection's own
`rpcTimeoutSeconds` and `writeTimeoutSeconds`, which is a different setting and worth
having as well. A handler busy with pure computation *is* cut, because the pool arms
preemption while it consumes (`preemptionQuantumMs`, on by default).

The same deadline is available inside a handler for a part of the work, without
configuring the pool at all:

```php
use SConcur\Deadline;

$queueConsumer->consume(
    connection: $connection,
    handler: static function (Delivery $delivery): void {
        $enriched = Deadline::run(timeoutMs: 500, callback: fn() => enrich($delivery->body));

        store($enriched);
    },
);
```

### When the worker itself dies

Everything above answers for a handler that failed. A handler that takes the whole
process with it — a job that runs the heap into `memory_limit`, or one the kernel's
OOM killer picks — is answered by the broker instead, and the answer is better than
it sounds: an unacknowledged message belongs to the broker until it is
acknowledged, so a connection that dies hands every message on it straight back to
its queue.

Measured, with a worker holding a prefetch of three and dying on the first message:

```
Fatal error: Allowed memory size of 67108864 bytes exhausted
---
after the crash: ready=3 consumers=0
first back: body=boom redelivered=true
```

Two things worth reading twice. Nothing was lost. And **all three** came back, not
just the one being handled: the broker had handed out three, none were
acknowledged, so all three are owed again. A worker holding a prefetch of fifty
returns fifty. That is the other reason a pool of coroutines wants
`prefetchCount: 1` — the price of a crash is one message per coroutine.

Whether PHP dies on its own limit or is killed outright makes no difference here.
The first prints a fatal error, the second runs no PHP at all; the safety net in
both cases is the socket closing, not anything this library does.

What the crash does cost:

- **The side effects already happened.** AMQP gives at-least-once and nothing more.
  A job that charged a card and then died charged it, and will charge it again when
  the message comes back. Handlers that touch the outside world have to be
  idempotent; no runtime can decide that for you.
- **`x-death` does not count this.** The attempt counter above grows on a
  *deliberate* refusal — a `nack`, a `reject`, an expired TTL. A message returned
  because the connection died was never refused by anybody, so the counter stays
  where it was and a dead-letter policy built on it never fires. A message that
  reliably kills its worker will kill the next one, and the one after that.

The broker's own answer to that last one is a quorum queue with a delivery limit,
which counts every redelivery — including the ones nobody asked for:

```php
$queue->declare(durable: true, arguments: [
    'x-queue-type'     => 'quorum',
    'x-delivery-limit' => 3,
]);
```

```
round 1: redelivered=false x-delivery-count=NULL
round 2: redelivered=true  x-delivery-count=1
round 3: redelivered=true  x-delivery-count=2
round 4: redelivered=true  x-delivery-count=3
round 5: the queue is empty — the broker took the message away itself
```

`x-delivery-count` is kept by the broker and grows on every delivery after the
first, whatever ended the one before. Past `x-delivery-limit` the message is
dropped, or dead-lettered where the queue names an exchange for it. This is the
only mechanism here that catches a message which kills the process, and it needs no
support from this library — those are ordinary queue arguments.

`QueueConsumer`'s `maxMemoryBytes` is worth having but answers a different
question. It is checked by the supervisor coroutine every `pollIntervalMs` against
`memory_get_usage()`, so it catches a worker that grew over a thousand messages and
sends it into a clean drain. It does not catch a single job that allocates a
gigabyte without pausing: nothing else runs while that loop runs, so the supervisor
never gets to look. Set it well under `memory_limit` and treat it as a guard
against creep, not against a spike.

### What the supervised consumer does

`QueueConsumer` settles what the handler left open — returning acknowledges,
throwing refuses — and `requeueOnFailure` decides between putting the message back
and letting it dead-letter. That is the whole of its policy: it counts no attempts
and applies no backoff. A retry policy is the handler's, built out of the pieces
above; the topology it needs belongs to the worker script, because the runtime
declares nothing.

## Connections and channels on the Go side

A connection is pooled by its options: two `Connection` objects built with the
same ones share a single socket to the broker, so building one per request is
cheap. They share it in the broker's eyes as well — an exclusive queue declared
through one is usable through the other. `connectionName` is part of the pool key,
so naming a connection is how an application asks for one that is not shared.
`connectTimeoutSeconds` is the one option left out of the key: it bounds the dial and
nothing the broker ever sees. A pooled connection with no owners left is closed
after five minutes of idling, and `close()` — or the destructor of a dropped
object — gives up ownership.

Channels are held in a registry keyed by an opaque id, so an acknowledgement may
come from another coroutine, and therefore another flow, than the consumer that
received the message. A channel is closed by `close()`, by the destructor of a
dropped `Channel`, when its connection dies, or by the sweeper that collects
channels with no consumers that have run no command for 30 minutes.

A delivery tag belongs to the channel that delivered the message. That is also why
a channel is not shared between coroutines — give each coroutine its own.

## TLS and SASL EXTERNAL

TLS is asked for by `TlsOptions`, or by an `amqps://` URI:

```php
use SConcur\Features\Amqp\ConnectionOptions;
use SConcur\Features\Amqp\SaslMethodEnum;
use SConcur\Features\Amqp\TlsOptions;

$connection = new Connection(new ConnectionOptions(
    host:       'broker.internal',
    port:       5671,
    saslMethod: SaslMethodEnum::External,
    tls:        new TlsOptions(
        caCert: '/etc/ssl/rabbit/ca.pem',
        cert:   '/etc/ssl/rabbit/client.pem',
        key:    '/etc/ssl/rabbit/client.key',
        verify: true,
    ),
));
```

| Field | Meaning |
| --- | --- |
| `caCert` | the authority the broker's certificate is checked against; with none named, the system store is used |
| `cert`, `key` | the client certificate and its private key. The two go together — naming one without the other fails the dial |
| `verify` | `true` by default; `false` skips the check of the broker's certificate altogether |

The files are read by the extension inside the worker's own process, so the paths
are the ones that process sees.

The broker's certificate is checked against `host`, so it has to be valid for
exactly that name, and as a SAN entry — Go does not accept a bare common name. A
dial that cannot read a file, cannot parse the CA or fails the handshake raises
`ConnectionException`.

`SaslMethodEnum::External` replaces the login and password with the client
certificate: the broker takes the identity out of it, and neither is sent at all.
It needs three things on the broker — the `rabbitmq_auth_mechanism_ssl` plugin,
`EXTERNAL` among its `auth_mechanisms`, and a user named the way the broker reads
the name out of the certificate. Asking for it without a client certificate is
refused where the options are written, rather than at the handshake.

The TLS material and the SASL method all belong to the pool key, so two
connections differing in any of them do not share a socket.

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
    $waitGroup->add(function () use ($connection): void {
        // One unacknowledged message at a time, so the broker gives the next one to
        // whichever coroutine is free instead of filling one buffer.
        $channel = $connection->channel(prefetchCount: 1);

        foreach ($channel->queue('orders')->consume() as $delivery) {
            handle($delivery->body);

            $delivery->ack();
        }
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
| channels per connection | 256 (`ConnectionOptions::MAX_CHANNELS`); the 257th fails with `504 channel id space exhausted` | one channel per coroutine, and the topology needs one of its own, so a connection carries 255 consumers; a `connectionName` gives an application a connection of its own, and with it another 256 |
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
use SConcur\Features\Amqp\Connection;
use SConcur\Features\Amqp\Consumer\QueueConsumer;
use SConcur\Features\Amqp\Delivery;

require __DIR__ . '/vendor/autoload.php';

// Everything about the queues comes from argv, which is how a master configures
// its workers. The credentials do not: a password in argv is visible in `ps`.
$queueConsumer = QueueConsumer::fromArgs($_SERVER['argv']);

$connection = new Connection(getenv('RABBITMQ_DSN'));

$queueConsumer->consume(
    connection: $connection,
    handler: static function (Delivery $delivery): void {
        handle($delivery->body);
    },
);
```

The handler does not settle the delivery: **returning acknowledges it, throwing
refuses it.** A handler that settled it itself — a selective reject, an ack before
some slow follow-up work — is left alone, because a delivery knows whether it has
been settled.

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
| `handlerTimeoutMs` | `0` (no limit) | How long one message may spend in the handler. A job that runs past it is cut and refused like any other failure; the coroutine takes the next message — see [bounding one job](#bounding-one-job). |
| `requeueOnFailure` | `false` | What happens to a message whose handler threw: dead-lettered or dropped by default, put back when true. A policy with attempts and a backoff belongs to the handler — see [retries and delays](#retries-and-delays). |
| `maxMessages` | `0` (no limit) | Drain and exit after this many messages. A budget, not a hard count: the coroutines already inside a handler finish their message too, so a pool of N may end up to N-1 over. |
| `maxRuntimeSeconds` | `0` (no limit) | Drain and exit after this long. |
| `maxMemoryBytes` | `0` (no limit) | Drain and exit once the PHP heap passes this. A guard against creep, not against a spike — see [when the worker itself dies](#when-the-worker-itself-dies). |
| `drainTimeoutMs` | `5000` | How long a stop waits for the handlers that are mid-message. |
| `pollIntervalMs` | `200` | How often the supervisor coroutine wakes to look at the stop flags and the limits. |
| `maxReconnectAttempts` | `10` | How many times in a row a consumer the broker took away reopens its queue, a second apart, before giving it up. `0` ends it on the first failure. |
| `preemptionQuantumMs` | `5` (`0` off) | Automatic-preemption quantum while consuming. It is what lets `handlerTimeoutMs` and a stop reach a handler busy with computation — see [coroutine switching](coroutine-switching.md). |
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

A coroutine that is running a handler finishes that message and settles it, then
ends. A coroutine that is waiting for a delivery has nothing to finish, so once
nothing is mid-message any more the group is stopped and those waits end with it —
inside the consume generator, whose teardown cancels the consumer without waiting
for the broker to answer, because the scheduler has already detached the fiber.

Keep `drainTimeoutMs` below the master's `shutdownTimeoutMs`, or `SIGKILL` lands
in the middle of the drain.

A handler that throws is reported through the optional `onError` callback and
costs one message, not one consumer: the runtime refuses the delivery and the same
coroutine takes the next one.

### A consumer the broker takes away

More than a deleted queue ends a consumer: a channel dies over an unrelated `404`, a
cluster node fails over, the connection's `readTimeoutSeconds` passes. The coroutine
reopens its queue on a fresh channel a second later, and says so:

```
consumer: orders lost (…: Consumer sconcur-ctag-7 was cancelled by the broker.); reopening in 1000ms, attempt 1 of 10
```

Past `maxReconnectAttempts` in a row it gives the queue up. A queue that was deleted
answers `404` for ever, and a consumer retrying for ever would keep the worker alive as a
pool with dead queues in it. A consumer that stayed up longer than the pause between
attempts gets a fresh budget, so a failure hours later is not counted against the last one.

Two things it does not do. It does not reopen the **connection**: that one is shared by
every coroutine of the worker, and closing it from one of them would take the channels of
all the others with it — so a dead connection fails every consumer in turn, the worker
exits non-zero, and the [master](worker-master.md) starts a fresh process. And it does not
resettle the message that was in flight: it was never acknowledged, so the broker hands it
out again on its own.

Once every consumer has given up, `consume()` raises whatever ended the first one, so the
worker exits rather than sitting there with nothing to pull.

## Values a field table can carry

Queue and exchange arguments and message headers are AMQP field tables. Scalars, lists
and nested tables travel as themselves; the two AMQP kinds PHP has no type for get one:

```php
use SConcur\Features\Amqp\Decimal;
use SConcur\Features\Amqp\Timestamp;

$queue->publish(new Message($body, headers: [
    'price' => new Decimal(exponent: 2, significand: 1999),   // 19.99, and ->toFloat() says so
    'when'  => new Timestamp(microtime(true)),                // whole seconds, (string) for the digits
]));
```

They keep their kind on the wire, so another client reading the same queue sees a decimal
and a timestamp rather than a float and an integer — and they come back as the same
objects. A class of your own joins them by implementing
`SConcur\Features\Amqp\AmqpValue`, whose `toAmqpValue()` is asked what the value stands
for instead of the encoder refusing it.

## Errors

Every broker failure is a `SConcur\Exceptions\Amqp\AmqpException`, and the reply
code the broker named is the exception's own code. The one exception to that is the
last row of the table — a configuration bug, not a broker one:

```php
use SConcur\Exceptions\Amqp\QueueException;

try {
    $queue->declarePassive();
} catch (QueueException $exception) {
    if ($exception->getCode() === 404) {
        $queue->declare(durable: true);
    }
}
```

| What happened | Exception | Code |
| --- | --- | --- |
| the broker refused the method (404, 406, …) | `QueueException`, `ExchangeException`, `ChannelException` — the one of the call | the reply code |
| the broker answered with a connection-level code (5xx), or the connection died | `ConnectionException` | the reply code; a connection that dropped mid-frame reports the driver's own `501`, and only a failure nobody put a code on reports 0 |
| the channel is gone — the broker closed it over an earlier failure | `ChannelException` | the reply code that closed it, 0 when the broker named none |
| a publish was nacked, returned, or never confirmed | `PublishNackedException`, `UnroutableMessageException`, `PublishConfirmTimeoutException` | the reply code of a return, 0 otherwise |
| a value cannot travel in a field table | `InvalidAmqpValueException` | 0 |
| the queue list of a consumer worker is not one | `InvalidQueueSpecException` (a `LogicException`: a config bug, not a broker one) | 0 |

A failure the broker punishes with a closed channel (a passive declare of a queue
that does not exist, a publish to a missing exchange) leaves the `Channel` closed:
`isOpen()` reports it, every later call on it raises `ChannelException`, and the Go
side has already released it. Open a new channel to carry on — the connection is
untouched.

That exception names what closed the channel when the broker said so, which is the only
way to see the cause of a failure that carries no reply of its own: `basic.publish`
expects none, so a publish to an exchange that is not there is answered by the channel
going away, and the reply code lands on whatever ran next.

A connection that died ends the same calls differently: a command on one of its channels
raises `ConnectionException`, and so does a consumer whose stream it took with it.

A connection-level failure marks the `Connection` closed as well, and it is not
reopened by itself:

```php
if (!$connection->isOpen()) {
    $connection->close();
    $connection->connect();
}
```

## Coming from ext-amqp or php-amqplib

The API is SConcur's own, not either library's. What moves where:

| ext-amqp | php-amqplib | here |
| --- | --- | --- |
| `new AMQPConnection([...])` + `connect()` | `new AMQPStreamConnection(...)` | `new Connection($dsn)` |
| `new AMQPChannel($connection)` | `$connection->channel()` | `$connection->channel()` |
| `new AMQPQueue($channel)` + `setName()` | — | `$channel->queue('orders')` |
| `setFlags(AMQP_DURABLE)` + `declareQueue()` | `queue_declare($q, false, true, false, false)` | `$queue->declare(durable: true)` |
| `declareQueue(): int` | `queue_declare(): array` | `$queue->declare(): QueueInfo` |
| `setFlags(AMQP_PASSIVE)` + `declareQueue()` | `queue_declare($q, true)` | `$queue->declarePassive()` |
| `$exchange->publish($body, $key, AMQP_MANDATORY, ['delivery_mode' => 2])` | `basic_publish(new AMQPMessage(...), $ex, $key)` | `$channel->publish(new Message($body, persistent: true), exchange:, routingKey:, mandatory: true)` |
| publishing through an exchange named `''` | the same | `$queue->publish($body)` |
| `$queue->consume($callback)` | `basic_consume(...)` + `$channel->consume()` | `foreach ($queue->consume() as $delivery)` |
| `$queue->ack($envelope->getDeliveryTag())` | `$message->ack()` | `$delivery->ack()` |
| `setConfirmCallback()` + `waitForConfirm()` | `set_ack_handler()` + `wait_for_pending_acks()` | `$channel->publishConfirmed(...)` |
| `setReturnCallback()` + `waitForBasicReturn()` | `set_return_listener()` | `UnroutableMessageException` |
| `startTransaction()` / `commitTransaction()` | `tx_select()` / `tx_commit()` | — publisher confirms replaced them; RabbitMQ discourages transactions |
| `basicRecover()` | `basic_recover()` | — `$delivery->nack(requeue: true)` |
| `pconnect()`, `pdisconnect()` | — | — persistent connections are a php-fpm notion; the Go-side pool outlives the PHP object anyway |
| `AMQP_*` bit masks | positional booleans | named arguments and `ExchangeTypeEnum` |

Neither extension is required at runtime. `ext-amqp` stays a `require-dev`
dependency because one test exchanges real messages with it on a live broker and
compares every property, header and field-table entry
(`tests/feature/Features/Amqp/AmqpBehaviourParityTest.php`) — the two
implementations must put the same bytes on the wire, whatever their APIs look
like.

Two behaviours differ from both libraries on purpose:

- a message with no content type carries none, where `ext-amqp` publishes
  `text/plain`;
- `$delivery->properties->clusterId` is always null. AMQP 0-9-1 excludes
  cluster-id from publishing, and the driver does not surface it on a delivery
  either.

## Limits

The general limits — CLI only, Linux only, NTS only, no `pcntl_fork` — are in the
[README](../README.md).

- TLS and SASL EXTERNAL are implemented but not covered by tests, because the
  compose broker listens without TLS — see
  [TLS and SASL EXTERNAL](#tls-and-sasl-external).
- `basic.publish`'s `immediate` flag is never sent: RabbitMQ has not implemented
  it since 3.0 and closes the channel on one that sets it.
- AMQP transactions are not implemented. Publisher confirms replaced them, and the
  broker's own documentation recommends against them.
- A consume is bounded by the connection's `readTimeoutSeconds`, not by a per-call one.
- Publisher confirms and returned messages that no wait collects are dropped past
  1024 and 128 respectively: a returned message carries its whole body, and a
  publisher that never waits would otherwise fill the heap.
- The `x-delayed-message` exchange type of the community delayed-message plugin
  cannot be declared: `ExchangeTypeEnum` is closed to the four types AMQP 0-9-1
  itself defines. The delay patterns that need no plugin are in
  [retries and delays](#retries-and-delays).
- A `Timestamp` at or above 2^63 is refused when published. AMQP counts unsigned
  64-bit seconds, but neither a PHP int nor the Go time the field is built from holds
  the upper half of that range.
- A `Decimal` significand above 2^31-1 travels through SConcur bit for bit and reads
  back the same, but the field carries it as 32 bits and RabbitMQ's own clients read
  those as signed — so another client sees it as negative. A negative decimal cannot be
  expressed at all, which is why `Decimal::SIGNIFICAND_MIN` is zero.

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
once: consuming holds the PHP thread in both `ext-amqp` and `php-amqplib`, so a
process is pinned to one queue, while here the same loop suspends only its
coroutine — three consumers waiting on a 200 ms delay finish in one delay, not
three, which is what `tests/feature/Features/Amqp/AmqpConsumeTest.php` measures.
