<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Consumer;

use Closure;
use SConcur\Exceptions\FlowStoppedException;
use SConcur\Features\Amqp\Channel;
use SConcur\Features\Amqp\Connection;
use SConcur\Features\Amqp\ConnectionOptions;
use SConcur\Features\FeatureExecutor;
use Throwable;

/**
 * The channels a QueueConsumer lends its handlers, one at a time each.
 *
 * A consumer's own channel carries the deliveries of every handler running on it, so a
 * handler that published through it would share the channel's state with its neighbours —
 * and publisher confirms, unlike an acknowledgement, are channel-wide: two handlers waiting
 * for their confirmations on one channel read each other's. This pool is what keeps that
 * from being possible: a handler never sees the channel its message arrived on, it gets one
 * of these, and no other handler holds it at the same time.
 *
 * The pool grows to whatever a configuration needs and refuses nothing. Channels are opened
 * on connections of its own, so they never compete with the consumers for the delivery
 * connection's channel numbers, and a connection that runs out of them is followed by
 * another. That costs a socket per 255 handlers publishing at once — the price of the
 * guarantee, and the reason the pool opens a channel only when a handler actually asks for
 * one (see docs/amqp.md).
 */
class PublishChannelPool
{
    /**
     * How long a channel may sit unused before the pool gives it up, unless the caller says
     * otherwise. Comfortably under the half hour after which the extension side sweeps a channel
     * that has run no command: a swept channel would surface as a failed publish on the
     * handler unlucky enough to lease it.
     */
    protected const float DEFAULT_MAX_IDLE_SECONDS = 600.0;

    /** A channel that consumes nothing has nothing to prefetch. */
    protected const int PREFETCH_COUNT = 1;

    /** The name of a connection this pool opens, when the delivery connection carries none. */
    protected const string DEFAULT_CONNECTION_NAME = 'sconcur consumer';

    /** @var list<Connection> in the order they were opened; the last one is the one being filled */
    protected array $connections = [];

    /**
     * How many channels each of those connections has open, by the connection's object id.
     *
     * By the object and never by a place in the list: asking a connection anything is a
     * call, automatic preemption parks a coroutine at one, and a connection let go
     * meanwhile shifts every later place down — so a place read on one side of a call
     * names a different connection on the other, or none at all.
     *
     * @var array<int, int>
     */
    protected array $openCounts = [];

    /** @var list<Channel> the channels nobody holds right now */
    protected array $free = [];

    /** @var array<int, float> when a free channel was handed back, by object id */
    protected array $idleSince = [];

    /**
     * Which connection a channel was opened on, by the channel's object id.
     *
     * The connection itself and not its place in the list: opening a channel waits for the
     * broker, and a connection let go meanwhile shifts every later place down, so an index
     * remembered across that wait names the wrong connection — or none.
     *
     * @var array<int, Connection>
     */
    protected array $connectionOf = [];

    /** How many connections the pool has opened, ever. Names are drawn from it. */
    protected int $connectionsOpened = 0;

    /**
     * @param ConnectionOptions          $options        what the delivery connection was opened with; the
     *                                                   pool reuses everything but the connection name
     * @param null|Closure(string): void $log            where a lifecycle line goes, when the worker
     *                                                   wants one
     * @param float                      $maxIdleSeconds how long a channel nothing has needed is kept before the
     *                                                   pool gives it up
     */
    public function __construct(
        protected ConnectionOptions $options,
        protected ?Closure $log = null,
        protected float $maxIdleSeconds = self::DEFAULT_MAX_IDLE_SECONDS,
    ) {
    }

    /**
     * A channel of the caller's own, for as long as it holds it. Opens one when the pool has
     * none free, which is why a worker whose handlers never publish opens none at all.
     *
     * A channel on a connection already known to have failed is never handed out. That
     * knowledge only arrives when a command fails, though — a socket the broker closed looks
     * open from here until something is asked of it — so the first holder after a connection
     * dies still meets the failure, and it is the next that gets a working channel.
     */
    public function lease(): Channel
    {
        // Taken first and judged after, never the other way around: automatic preemption
        // switches coroutines between opcodes, so a list that was not empty when it was
        // checked can be empty by the time it is read.
        while (($channel = array_pop($this->free)) !== null) {
            $objectId  = spl_object_id($channel);
            $idleSince = $this->idleSince[$objectId] ?? 0.0;

            unset($this->idleSince[$objectId]);

            if (
                $channel->isOpen()
                && !$channel->connection()->isFailed()
                && (microtime(true) - $idleSince) < $this->maxIdleSeconds
            ) {
                return $channel;
            }

            $this->discard($channel);
        }

        return $this->open();
    }

    /**
     * Takes a lent channel back, and keeps it only if the next handler can have it clean.
     *
     * Two things disqualify one. A channel that died on the handler is dropped: the failure
     * that holder already saw is enough, and the next deserves a working channel. And a
     * channel the broker still owes an answer — a publisher confirm, or the return of a
     * mandatory message — is dropped as well, because whoever waits next collects everything
     * the channel has collected, whoever published it. Lending that on would tell one
     * handler about another's message: the very misattribution a channel of one's own
     * exists to prevent, delayed rather than concurrent.
     */
    public function release(Channel $channel): void
    {
        if ($channel->isOpen() && !$channel->hasUnreadPublishAnswers()) {
            $this->idleSince[spl_object_id($channel)] = microtime(true);

            $this->free[] = $channel;
        } else {
            $this->discard($channel);
        }

        $this->trimIdle();
    }

    /** How many connections the pool had to open — what a worker reports about its cost. */
    public function connectionCount(): int
    {
        return count($this->connections);
    }

    /** How many channels the pool holds open, lent out or not. */
    public function channelCount(): int
    {
        return array_sum($this->openCounts);
    }

    /**
     * Gives up everything the pool holds. Releasing a connection closes its channels on the
     * extension side, so they need no closing of their own.
     */
    public function close(): void
    {
        $this->idleSince    = [];
        $this->connectionOf = [];

        try {
            // An unwound coroutine has nothing to await an answer on, and asking would park
            // it for good. Dropping the objects is enough there: a Connection hands its
            // handle back from its destructor, detached — the same release without the wait.
            if (!FeatureExecutor::canAwait()) {
                return;
            }

            foreach ($this->connections as $connection) {
                try {
                    $connection->close();
                } catch (FlowStoppedException $exception) {
                    // A deliberate unwind reached the teardown; it is not a failure to
                    // swallow, and the arrays are cleared on the way out regardless.
                    throw $exception;
                } catch (Throwable) {
                    // A teardown is no place to fail: the process is going away with
                    // whatever the broker still holds open, and the connection is pooled
                    // behind a five-minute idle timeout either way.
                }
            }
        } finally {
            // Let go after the connections, not before: releasing one closes its channels on
            // the extension side and clears their handles, so the destructors that follow have
            // nothing left to send.
            $this->free        = [];
            $this->connections = [];
            $this->openCounts  = [];
        }
    }

    /**
     * Lets go of one channel a past burst opened and nothing has needed since. The free list
     * is used as a stack, so its front is the least recently used and one look is enough; a
     * burst's worth of channels is given up over the releases that follow it, which keeps
     * the work per message flat.
     *
     * Without this a worker that was busy once would hold that many channels, and the
     * sockets under them, for as long as it lived.
     */
    protected function trimIdle(): void
    {
        // Taken off the front before it is judged, and put back when it turns out to be
        // wanted: between the check and the read another coroutine may have taken it, and
        // preemption puts that window between any two opcodes.
        $oldest = array_shift($this->free);

        if ($oldest === null) {
            return;
        }

        $objectId = spl_object_id($oldest);

        if ((microtime(true) - ($this->idleSince[$objectId] ?? 0.0)) < $this->maxIdleSeconds) {
            array_unshift($this->free, $oldest);

            return;
        }

        unset($this->idleSince[$objectId]);

        $this->discard($oldest);
    }

    /** Opens one channel on a connection that still has room, opening that connection first if need be. */
    protected function open(): Channel
    {
        // Counted before the channel is opened rather than after: opening waits for the
        // broker, and several handlers may be doing it at once. A count that only rose on
        // success would leave them all reading the same connection as empty and crowding
        // past its last channel number.
        $connection = $this->reserve();

        try {
            $channel = $connection->channel(prefetchCount: self::PREFETCH_COUNT);
        } catch (Throwable $exception) {
            // The connection reserved above, whatever the list looks like now: the wait
            // just ended may have let another coroutine give a connection up.
            $this->uncount($connection);

            throw $exception;
        }

        $this->connectionOf[spl_object_id($channel)] = $connection;

        return $channel;
    }

    /**
     * The connection the next channel is opened on, with that channel already counted on it:
     * the newest one while it has channel numbers left, a fresh one once it does not.
     *
     * Choosing and counting are one step because they cannot be two. Choosing waits for
     * nothing, but it is made of calls, and a coroutine can be parked at any of them — so a
     * connection chosen in one step and counted in the next may have been let go in between.
     */
    protected function reserve(): Connection
    {
        $newest = $this->newestWithRoom();

        if ($newest !== null && $this->countOn($newest)) {
            return $newest;
        }

        // Either nothing had room, or what did was let go while it was being chosen. A
        // connection nobody holds a channel on is nobody's to let go, so this one is
        // counted on for certain.
        $connection = $this->openConnection();

        $this->countOn($connection);

        return $connection;
    }

    /** The connection the pool is filling right now — the last one opened, or none at all. */
    protected function newest(): ?Connection
    {
        return $this->connections[count($this->connections) - 1] ?? null;
    }

    /**
     * The newest connection when the next channel can be opened on it, null when a fresh one
     * is needed.
     *
     * A connection that failed has no room, whatever its count says. It is never redialled —
     * Connection::connect() refuses one that still holds a handle, on purpose — so a pool
     * that went on offering it would answer every later lease with "No connection
     * available" for the life of the worker, and under a supervised consumer that failure
     * refuses a message rather than reporting itself.
     */
    protected function newestWithRoom(): ?Connection
    {
        $newest = $this->newest();

        if ($newest === null || $newest->isFailed()) {
            return null;
        }

        $usableChannels = ConnectionOptions::usableChannels($newest->maxChannels());

        // The count is read after the capacity and not before: reading the capacity is a
        // call, and the list can be a different list on the other side of one. Keyed by the
        // connection itself, what comes back is this one's however the list moved.
        return ($this->openCounts[spl_object_id($newest)] ?? 0) < $usableChannels ? $newest : null;
    }

    /**
     * Counts one more channel on a connection, and says whether it could.
     *
     * @return bool false when the connection was let go while it was being chosen — nothing
     *              is counted then, and the caller opens one of its own instead
     */
    protected function countOn(Connection $connection): bool
    {
        $objectId = spl_object_id($connection);

        // The check and the increment with nothing between them: neither is a call, so no
        // other coroutine runs there and the entry cannot go away under them.
        if (!isset($this->openCounts[$objectId])) {
            return false;
        }

        ++$this->openCounts[$objectId];

        return true;
    }

    /** @return Connection the connection that was opened, registered and ready to be counted on */
    protected function openConnection(): Connection
    {
        // Drawn from a counter and never from a place in the list: a connection given up
        // closes the gap behind it, so a name taken from the place would be one already in
        // use — and the extension side pools a socket by its options, the name among them, so two
        // of these objects would share one socket while the pool budgeted channels to each
        // of them separately, straight into a 504.
        ++$this->connectionsOpened;

        $connection = new Connection(
            $this->options->withConnectionName(
                sprintf(
                    '%s publish %d (pid %d)',
                    $this->options->connectionName ?? self::DEFAULT_CONNECTION_NAME,
                    $this->connectionsOpened,
                    getmypid(),
                ),
            ),
        );

        // Both before the line is logged: logging is a call, and a connection the pool has
        // not finished registering must not be visible to whoever runs at one.
        $this->connections[]                          = $connection;
        $this->openCounts[spl_object_id($connection)] = 0;

        if ($this->log !== null) {
            ($this->log)(sprintf(
                'consumer: opened publish connection %d for the handlers that publish',
                $this->connectionsOpened,
            ));
        }

        return $connection;
    }

    /**
     * Stops counting a channel the pool will not lend again and lets it go.
     *
     * Its handle is given back by the destructor, which does it detached: the pool never
     * waits on a channel it is throwing away, and neither does the handler whose release
     * happened to trim it.
     */
    protected function discard(Channel $channel): void
    {
        $objectId   = spl_object_id($channel);
        $connection = $this->connectionOf[$objectId] ?? null;

        unset($this->connectionOf[$objectId]);

        if ($connection !== null) {
            $this->uncount($connection);
        }
    }

    /**
     * Takes one channel off a connection's count, and lets the connection go once nothing
     * is left on it.
     *
     * The newest is kept even when it empties — the next channel is opened on it, and
     * letting it go would make a worker whose handlers keep giving channels back dirty dial
     * a connection per message. A failed one is not kept: it can open nothing.
     */
    protected function uncount(Connection $connection): void
    {
        $objectId = spl_object_id($connection);
        $count    = $this->openCounts[$objectId] ?? null;

        if ($count === null) {
            return;
        }

        if ($count > 0) {
            $this->openCounts[$objectId] = --$count;
        }

        if ($count !== 0) {
            return;
        }

        // Asked in this order so that nothing runs between the answer and the decision:
        // whether it failed is a question about the connection alone, whether it is the
        // newest is a question about a list another coroutine can change.
        if (!$connection->isFailed() && $this->newest() === $connection) {
            return;
        }

        $this->forgetConnection($connection);
    }

    /**
     * Lets go of a connection with no channels left on it, so that a burst gives back its
     * sockets and not only its channels.
     *
     * Dropped rather than closed: this runs on the path a handler returns through, and
     * closing waits for the broker — which an unwound coroutine cannot do at all. The
     * destructor hands the handle back detached, without the waiting.
     */
    protected function forgetConnection(Connection $connection): void
    {
        $objectId = spl_object_id($connection);
        $index    = array_search($connection, $this->connections, true);

        if ($index === false) {
            unset($this->openCounts[$objectId]);

            return;
        }

        // The place is used in the same breath it was found in: no call stands between the
        // search and the unset, so no other coroutine runs there to move it.
        unset($this->connections[$index], $this->openCounts[$objectId]);

        $this->connections = array_values($this->connections);
    }
}
