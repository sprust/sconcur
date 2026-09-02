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
| `Channel` | the channel handle; `Connection::channel()` opens the channel and hands it over | `prefetch()`, `publish()`, `publishConfirmed()`, `enableConfirms()`, `waitForConfirms()`, `ack()`/`nack()`/`reject()`, `get()`, `consume()`, `close()` |
| `Queue` | a name and the channel to run on — a handle, built for free | `declare()`, `declarePassive()`, `bind()`, `unbind()`, `delete()`, `purge()`, `publish()`, `publishConfirmed()`, `get()`, `consume()` |
| `Exchange` | the same, for an exchange | `declare()`, `declarePassive()`, `bind()`, `unbind()`, `delete()`, `publish()`, `publishConfirmed()` |
| `Message` | the body and properties of a message being published | nothing, it is a value object |
| `Delivery` | a delivered message, the means to settle it, and `channel()` — a channel to publish on | `ack()`, `nack()`, `reject()`; `channel()` opens one under a supervised consumer, once per handler |
| `RetryTopology` | the wait queues a delayed publish goes through | `declare()` — the one call here that creates topology |

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

A publish the broker refused can be tried again without the caller writing the loop:

```php
$queue->publishConfirmed(
    message: $message,
    timeoutSeconds: 5.0,
    retries: 3,
    retryDelaysSeconds: [1, 3, 4],
);
```

`retryDelaysSeconds` is read by attempt number — one second after the first failure,
three after the second, four after the third — and it does not bound how many
attempts there are: that is what `retries` is for. An attempt past the end of the
list takes the last entry, so `[1, 3, 4]` backs off to four seconds and stays there
however many retries follow. The default is an empty schedule, which tries again at
once.

The two arguments are the same on all three objects that publish — `Channel`,
`Queue` and `Exchange` — since all three end up in the same call.

Only the three failures in the table above are retried, because they are the three
the broker answered with. A channel or a connection that died raises past the loop
on purpose: the handle is gone by then, every further attempt fails the same way,
and retrying would spend the whole schedule to arrive at the exception the first
attempt already had. Reopening is the application's decision, not something a
publish makes behind it.

A retried confirm timeout can duplicate the message — the first attempt may have
been stored and only the answer lost. That is the at-least-once AMQP offers anyway,
and the same reason a handler has to be idempotent.

The wait between attempts is the worker's, not the broker's: `retryDelaysSeconds`
suspends the coroutine, and a worker that restarts during it takes the message with
it. There is nothing on the broker to come back to — the message was never accepted,
which is the whole reason it is being retried. A graceful drain cuts the wait the
same way. So keep this schedule short, and where the work has to outlive the worker,
put it on the broker instead: a message already in a [wait queue](#a-delayed-publish)
is the broker's, and its publisher can go away entirely.

There is nothing to retry on a plain `publish()`, which is why it takes no such
argument: `basic.publish` expects no reply, so a publish that returns has said
nothing, and one that raises did so because the channel is gone.

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

The prefetch bounds how many unacknowledged deliveries the broker pushes at a
consumer. A channel opened with no argument asks for 3
(`Channel::DEFAULT_PREFETCH_COUNT`); `$channel->prefetch()` changes it later. One is
the right answer for a pool of coroutines — it hands the next message to whichever
one is free instead of filling the buffer of a busy one.

It is per consumer unless asked otherwise: `$channel->prefetch(count: 10, global:
true)` makes the limit the channel's, shared by every consumer on it. Both forms are
the broker's `basic.qos`; the difference is only what the count is counted against.

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

That is the generator's contract. A worker that would rather keep pulling than handle
those failures itself uses the [supervised consumer](#a-consumer-the-broker-takes-away),
which reopens the queue instead of ending.

Deliveries a consumer received but never acknowledged go back into the queue when
the channel is closed, not when the consumer is cancelled — that is AMQP, not a
property of this implementation.

### How a delivery reaches the handler

Nothing in Go ever calls into PHP. The extension is a library PHP calls, and every
crossing starts on the PHP side — so a consumer is not Go pushing work at a worker
that listens for it. It is PHP leaving a standing question in Go, and being resumed
once Go can answer it.

`consume()` sends one `Consume` command and gets back a task key, the handle for
this stream. That command opens the consumer on the broker and registers the stream
under that key, holding on to the delivery channel the driver fills from the socket.
Every turn of the `foreach` is a `next()` on that key — "the next delivery for this
stream" — and the coroutine parks until it is answered.

The waiting happens in Go, on the goroutine that runs the `next`, not by PHP
polling the broker for a message. The
PHP side sits in a single `waitAny()` that serves every parked coroutine at once:
whichever answer becomes ready first is routed back to the coroutine that asked for
it, and that coroutine is resumed with its `Delivery`.

```mermaid
sequenceDiagram
    participant H as Handler (PHP coroutine)
    participant S as Scheduler (PHP)
    participant G as Consumer goroutine (Go)
    participant B as Broker

    H->>S: consume() — push(Consume)
    S->>G: push, returns at once
    G->>B: basic.consume
    B-->>G: consumer tag
    G-->>S: result — the tag
    S->>H: resume, the foreach begins

    H->>S: next(taskKey), the coroutine parks
    S->>G: next, returns at once
    S->>S: waitAny() — one wait for every parked coroutine
    Note over G,B: the goroutine waits on the driver's delivery channel
    B-->>G: delivery
    G-->>S: result
    S->>H: resume with the Delivery
    Note over H: the handler runs, then ack()
    H->>S: next(taskKey), and again
```

Publishing, declaring and acknowledging cross the same way and differ only in that
they answer once instead of streaming: one command out, one result back, the
coroutine parked in between. A consumer is the same exchange left open.

This is what makes a hundred consumer coroutines affordable in one process: none of
them is running while it waits, and every answer comes back through the same wait
point. It is also why the consumer has to be read in the coroutine that opened it —
the stream belongs to that coroutine's flow, and stopping the flow cancels it.

## Settling a delivery

```php
$delivery->ack();                    // the broker may forget the message
$delivery->nack(requeue: true);      // refuse, put the message back in the queue
$delivery->nack(requeue: false);     // refuse, do not put it back
$delivery->reject();                 // the same refusal, defaulting to not putting it back
```

`nack` and `reject` are the same AMQP refusal and differ in one thing: the default.
`nack` puts the message back unless told otherwise; `reject` does not put it back
unless asked. A refusal that does not requeue goes wherever the queue sends it — the
dead-letter exchange it names, or nowhere at all if it names none.

Each of the three settles one delivery. AMQP has a batch form as well — "and
everything before this tag on the channel" — which this library does not offer: it
pays off only while earlier deliveries are deliberately left unsettled, and that is
the one moment nobody can tell a message still being worked on from one already
finished. Under a [supervised consumer](#the-channel-a-handler-publishes-on) those
earlier deliveries belong to the handlers running beside this one; on a channel of
one's own they are whatever the caller fanned out and has not finished yet. The
broker cannot undo a batch, and what it settled early is lost if the process dies.

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

A delivery that is never settled at all — the process died before it got that far —
is answered by the broker rather than by this library, and it comes back to its
queue along with everything else the connection was holding. What that costs is in
[when the worker itself dies](#when-the-worker-itself-dies).

## Retries and delays

AMQP has no retry primitive and no delayed publish: a broker either holds a message
or hands it back at once. What it does have is dead-lettering and TTL, and
everything below is built out of those. `publish(delayMs: …)` packages the standard
shape; the recipes after it are for when the standard shape is not the one you want.

### A delayed publish

`Queue::publish(delayMs: …)` sends a message that becomes available only after the
delay. The waiting is done by a queue nobody consumes, so the delays a queue serves
are declared once at start-up, next to the application's own topology:

```php
use SConcur\Features\Amqp\RetryTopology;

$channel->queue('orders')->declare(durable: true);

RetryTopology::declare(
    channel:  $channel,
    queue:    'orders',
    delaysMs: [1_000, 5_000, 30_000],
);
```

That declares `orders.wait.1000`, `orders.wait.5000` and `orders.wait.30000`, each
holding its messages for its own delay and dead-lettering them into `orders`
afterwards. Publishing into one of them is what a delay is:

```php
$channel->queue('orders')->publish($message, delayMs: 5_000);
```

One queue per delay rather than one queue and a per-message expiration, because a
classic queue expires only from its head: a thirty-second message at the front
holds a one-second message behind it for the full thirty. The cost is that the
delay has to be one of the declared ones — `delayMs: 900` against the topology above
addresses a queue that is not there.

Which matters, because a plain `publish()` says nothing about where the message
went. Use `publishConfirmed(delayMs: …)` while the topology is being settled: it is
mandatory by default, so a delay nothing serves raises `UnroutableMessageException`
instead of dropping the message.

This is where a job waits, and it waits on the broker. Measured with a ten-second
delay and a worker killed three seconds into it:

```
worker died at 3002 ms
job came back at 10025 ms
```

Seven seconds left, not ten and not never: the broker counts from the moment the
message entered the wait queue, and nothing a client does restarts that. A restart,
a deploy or an OOM kill changes nothing for a message already in a wait queue — and
with a durable queue and a `persistent` message, neither does a broker restart.

Nothing waits alongside it, either. The message sits in a queue nobody consumes, so
the worker is not holding it at all: the prefetch slot is free and the coroutines
take the next message as usual. That is the difference from `retryDelaysSeconds`,
where the coroutine waits with its delivery still unacknowledged — the other
coroutines keep running, but that one takes no new message until its handler
returns, and a stop waits for that handler to finish.

`RetryTopology` is the one thing in this library that declares anything, and it
declares only when it is called. The queue itself is left alone: it is the
application's, with arguments this has no business guessing.

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

Note the seam. The republish and the `ack` are two operations, and a worker that
dies between them leaves the message in both places: the copy it published, and the
original the broker takes back. Dead-lettering has no seam — `nack(requeue: false)`
is one operation, performed by the broker. Republish when the routing or the counter
has to be yours, dead-letter when it does not.

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
`CoroutineTimeoutException`. The worker carries on and takes the next message; one
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

None of this covers a message the broker never judged. A handler that could not be lent a
channel at all, one whose connection went away mid-command, or one whose channel was
already gone when it tried — nothing the broker said is about those messages, so they go
back into the queue whatever the setting says. The policy above is for jobs that failed.
A publish the broker refused with a reply code — a 404 to an exchange that is not there —
is a verdict and follows the policy.

Once, and no more. The last of the three is a guess: a channel dies from its connection
going away and from what was asked of it alike, and this side cannot tell those apart at
the moment it has to place the message. So the second chance is only ever given to a
message the broker has not handed out before, and a handler that keeps killing its own
channel sends its message to the policy on the next delivery instead of round for ever.
The worker also asks the pool twice before giving up on a channel, because the first ask
after a connection is lost is the one that discovers it.

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

There is exactly one way to lose it, and it has to be asked for:
`consume(autoAck: true)` has the broker answer for a message as it leaves, so
nothing is outstanding and nothing comes back. Everything below is about the
default.

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
question. It is checked on every turn of the serve loop against
`memory_get_usage()`, so it catches a worker that grew over a thousand messages and
sends it into a clean drain. It does not catch a single job that allocates a
gigabyte without pausing: nothing else runs while that loop runs, so the loop never
gets to look. Set it well under `memory_limit` and treat it as a guard against
creep, not against a spike.

### What the supervised consumer does

`QueueConsumer` settles what the handler left open — returning acknowledges,
throwing refuses — and `requeueOnFailure` decides between putting the message back
and letting it dead-letter. That is the whole of its policy: it counts no attempts
and applies no backoff. A retry policy is the handler's, built out of the pieces
above; the topology it needs belongs to the worker script, because the runtime
declares nothing.

`tests/consumers/amqp/amqp-consumer.php` is such a worker written out: it declares
its wait queues alongside its own topology at start-up, and its `retry:<n>` handler
republishes a failed job with a delay that grows by attempt, on [the channel it was
lent](#the-channel-a-handler-publishes-on).

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
| `verify` | `true` by default. `false` asks to accept a certificate that was not checked, which the extension refuses — see below |

The files are read by the extension inside the worker's own process, so the paths
are the ones that process sees.

The broker's certificate is checked against `host`, so it has to be valid for
exactly that name, and as a SAN entry — a bare common name is not accepted. A
dial that cannot read a file, cannot parse the CA or fails the handshake raises
`ConnectionException`.

`verify: false` is refused rather than honoured: the TLS layer takes a
certificate chain and a client identity and has no switch for accepting a
certificate it cannot check. A dial asking for it fails with a
`ConnectionException` saying so. Point `caCert` at the authority that signed the
broker's certificate instead — that is what the option was reached for against a
self-signed development broker.

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
| channels per connection | 256 (`ConnectionOptions::MAX_CHANNELS`); the 257th fails with `504 channel id space exhausted` | one channel per coroutine, and channel numbering starts at one, so a connection carries 255 consumers; a `connectionName` gives an application a connection of its own, and with it another 256 |
| one connection is one socket | every channel of a connection is multiplexed over it, and the driver serializes the frames it writes | spread the coroutines over several named connections before the socket, not the channel count, becomes the ceiling |
| a process is one PHP thread | coroutines overlap the waiting, not the work: a handler that computes blocks the others while it runs | scale processes for CPU-bound handlers, coroutines for handlers that wait |

Two rules the sections above state and this one depends on: a consumer is read in
the coroutine that opened it, and a channel is not shared between coroutines —
the commands of one channel are serialized, so ten coroutines on one channel are
a queue of ten.

## A supervised consumer

`Features\Amqp\Consumer\QueueConsumer` is the worker shape of the section above:
several queues pulled at once by one process, each queue weighted by how many
consumers pull it, and a stop that drains rather than cuts. It is what a
[worker master](worker-master.md) group runs.

It is a server in everything but the socket. The Go side opens the consumers and
publishes every delivery of all of them as one stream; the same loop that runs the
HTTP, socket and WebSocket servers reads that stream and hands each message to a
coroutine of its own. Nothing is polled and nothing is pulled per message — the
next delivery is published as soon as the previous one is taken, so a worker pays
no boundary crossing per message beyond the delivery itself.

The channels behind those consumers belong to the Go side, which is what makes the
stop simple: cancelling the consumers leaves the channels open, so the
acknowledgements of the handlers still running land, and the flow ending closes
them.

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
| `prefetchCount` | `1` | Unacknowledged messages one consumer may hold, unless its queue names its own. It is also what bounds how many handlers run at once: a message stays unacknowledged for as long as its handler runs. |
| `handlerTimeoutMs` | `0` (no limit) | How long one message may spend in the handler. A job that runs past it is cut and refused like any other failure; the worker takes the next message — see [bounding one job](#bounding-one-job). |
| `requeueOnFailure` | `false` | What happens to a message whose handler threw: dead-lettered or dropped by default, put back when true. A policy with attempts and a backoff belongs to the handler — see [retries and delays](#retries-and-delays). |
| `maxMessages` | `0` (no limit) | Drain and exit after this many messages. |
| `maxRuntimeSeconds` | `0` (no limit) | Drain and exit after this long. |
| `maxMemoryBytes` | `0` (no limit) | Drain and exit once the PHP heap passes this. A guard against creep, not against a spike — see [when the worker itself dies](#when-the-worker-itself-dies). |
| `preemptionQuantumMs` | `5` (`0` off) | Automatic-preemption quantum while consuming. It is what lets `handlerTimeoutMs` reach a handler busy with computation, and keeps such a handler from holding off the loop — see [coroutine switching](coroutine-switching.md). |
| `masterPid` | none | Injected by the master; the worker drains as soon as it is orphaned. |

`coroutineCount` is a queue's weight: how many consumers it gets, each on a channel
of its own. The name is what the flag has always been called and the master's
configs carry it; what it counts is consumers, and a handler still runs in a
coroutine of its own per message.

`queues` is a list of objects rather than a delimited string because AMQP allows
almost any UTF-8 in a queue name, colons included — names like `tenant:1:orders`
are ordinary, and any separator inside a name would make the parse ambiguous. The
master JSON-encodes it into the flag; there is no shell on the way.

The list is validated before the first `basic.consume`: a missing or duplicated
name, a weight below one, and the channel budget — a consumer is a channel, and one
connection carries 255 of them, so a worker asking for more is told at startup
instead of meeting `504 channel id space exhausted` under load.

What it does not do: declare anything. Topology belongs to whoever owns it, and a
consumer that redeclared a queue with the wrong flags would take the channel down
with a `406` instead of consuming — so the worker script declares what it owns
before handing over, and `queueSpecs()` tells it what that is. A pool started
before anything published into its queue would otherwise crash-loop on a `404`.

The pool reports itself to the [panel](admin-stats.md) like any server: how many
consumers it has open, what they delivered, acknowledged and refused, and how long
a delivery spends in a handler.

### The channel a handler publishes on

One consumer is one channel, and with a prefetch of N the broker may have N of its
messages out at once, each in a handler of its own — so the channel they arrived on
belongs to none of them, and a publisher confirm is counted per channel rather than
per message. `$delivery->channel()` therefore does not answer with it. It answers
with a channel lent to that handler alone, taken from a pool the consumer keeps and
handed back when the handler ends; a handler that publishes nothing is lent
nothing. Settling is unaffected: `ack()`, `nack()` and `reject()` name their
message by tag on the channel it arrived on, and the runtime keeps that channel to
itself.

The lent channel goes back to the pool when the handler returns, so it must not be
stored past that. A `Delivery` an application kept beyond its handler answers
`null` from `channel()`, exactly as it does for a channel that was closed.

It goes back only if the broker owes it nothing. A publisher confirm, and the return
of a mandatory message, sit on the channel until someone waits for them — and that
wait collects everything the channel has collected, whoever published it. So a
handler that left one unread, because its deadline cut it mid-publish or because it
published mandatory and never looked, hands back a channel the pool gives up instead
of lending on; the next handler opens a fresh one.

The delivery belongs to one coroutine. Asking it for a channel from two at once
raises `ConcurrentDeliveryUseException` rather than lending two, only one of which
could ever be given back. Where a handler fans work out, take a channel per
coroutine from the connection.

### What the lent channels cost

They are opened lazily and reused, so a worker opens at most one per handler
publishing at the same time. A channel nothing has needed for ten minutes is given
up again, one per message settled, so a worker that was busy once does not hold
that burst's channels — and the sockets under them — for the rest of its life.

They live on connections of the pool's own — one more socket per 255 channels — so
they never compete with the consumers for the delivery connection's channel
numbers, and no combination of weight and prefetch is refused for wanting too many:
`prefetchCount: 50` over eight consumers can put 400 handlers in flight, and the
pool answers with two extra sockets instead of a `504 channel id space exhausted`.

The price is those channels and sockets, not throughput. Measured against the
shared channel it replaced — one queue, a prefetch of 10, every handler publishing
with confirms, broker on the same machine — the lent channel is not slower: it is
reused, so a message pays no extra round trip, and a `waitForConfirms()` on a
channel of one's own waits for one message instead of for every publish in flight
on the channel. The difference between the two ran inside the spread of the runs
themselves.

### Stopping

`SIGTERM` (or one of the limits above) cancels every consumer and then waits for
the handlers already running. Nothing is cut: a message in a handler is finished
and settled, and the loop returns once the last one has. The channels stay open
until then, so an acknowledgement in flight is not lost — closing them with the
cancel would hand a finished message back for another worker to do again.

There is no drain deadline of its own. A handler that never ends is what the
master's `shutdownTimeoutMs` is for — past it the worker is killed, and the
messages it was holding go back to the broker unacknowledged, exactly as with the
servers.

A handler that throws is reported through the optional `onError` callback and costs
one message, not the worker: the runtime refuses the delivery and the next message
is handled as usual.

### A consumer the broker takes away

More than a deleted queue ends a consumer: a channel dies over an unrelated `404`, or a
cluster node fails over. The consumer is opened again on a fresh channel a second later,
for as long as reopening can work, and says so each time:

```
amqp: consumer orders (sconcur-ctag-7) was taken away; reopening
```

There is no attempt limit. A queue that was deleted and never recreated is retried for as
long as the worker runs, one line a second per consumer on it — which is deliberate: a
queue that comes back is picked up without anyone intervening, and a queue that does not
is visible in the journal rather than silently unread.

The one failure that is not retried is the **first** open. A queue that is not there when
the worker starts, or credentials that cannot consume it, end the run at once: that is a
configuration error, and a worker would otherwise retry it silently for ever.

What ends a consumer for good is the **connection** going away. That one is shared by
every consumer of the worker, so it is not reopened: the stream ends with the failure,
`consume()` raises it, the worker exits non-zero, and the
[master](worker-master.md) starts a fresh process with a fresh connection.

The message that was in flight is not resettled — it was never acknowledged, so the broker
hands it out again on its own.

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
code the broker named is the exception's own code. The exceptions to that are the
last six rows of the table — configuration bugs, not broker ones:

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
| the broker answered with a code that closes the connection (320, 402, 5xx), or the connection died | `ConnectionException` | the reply code; a connection that dropped mid-frame reports the driver's own `501`, and only a failure nobody put a code on reports 0 |
| the channel is gone — the broker closed it over an earlier failure | `ChannelException` | the reply code that closed it, 0 when the broker named none |
| a publish was nacked, returned, or never confirmed | `PublishNackedException`, `UnroutableMessageException`, `PublishConfirmTimeoutException` | the reply code of a return, 0 otherwise |
| the runtime could not lend a handler a channel of its own | `ChannelLoanException` | 0 |
| a value cannot travel in a field table | `InvalidAmqpValueException` | 0 |
| the queue list of a consumer worker is not one | `InvalidQueueSpecException` | 0 |
| an option is outside the range the protocol allows, or a URI cannot be read | `InvalidConnectionOptionException` | 0 |
| a prefetch limit is outside that range | `InvalidPrefetchException` | 0 |
| a delay is not a positive number of milliseconds, or a list of them repeats one | `InvalidDelayException` | 0 |
| a publish is told to retry a negative number of times, or to wait a negative one | `InvalidRetryException` | 0 |
| one delivery is asked for a channel from two coroutines at once | `ConcurrentDeliveryUseException` | 0 |

The last six are `LogicException`s rather than `AmqpException`s: nothing was sent, the
broker was never asked, and there is no reply code to carry — they are bugs in how the
connection or the worker was described.

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
raises `ConnectionException`, and so does a consumer whose stream it took with it — except
`Delivery::channel()`, which reports a channel it could not lend as `ChannelLoanException`
with the reason as its `previous`.

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
- A prefetch **size** is refused rather than sent. `basic.qos` carries the field,
  but RabbitMQ has never implemented it and the extension's AMQP driver leaves it
  out of the frame altogether, so a size asked for could only be dropped in
  silence. `prefetch(sizeBytes:)` and `channel(prefetchSizeBytes:)` therefore
  raise instead. The prefetch **count** is the limit that works.
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
