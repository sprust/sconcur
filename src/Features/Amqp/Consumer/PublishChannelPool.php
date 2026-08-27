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
     * otherwise. Comfortably under the half hour after which the Go side sweeps a channel
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

    /** @var list<int> how many channels each of those connections has open, in the same order */
    protected array $openCounts = [];

    /** @var list<Channel> the channels nobody holds right now */
    protected array $free = [];

    /** @var array<int, float> when a free channel was handed back, by object id */
    protected array $idleSince = [];

    /**
     * Which connection a channel was opened on, by object id — so giving one up costs a
     * lookup rather than a walk over the connections.
     *
     * @var array<int, int>
     */
    protected array $connectionOf = [];

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
     */
    public function lease(): Channel
    {
        while ($this->free !== []) {
            $channel = array_pop($this->free);

            $objectId  = spl_object_id($channel);
            $idleSince = $this->idleSince[$objectId] ?? 0.0;

            unset($this->idleSince[$objectId]);

            if ($channel->isOpen() && (microtime(true) - $idleSince) < $this->maxIdleSeconds) {
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
     * Go side, so they need no closing of their own.
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
            // the Go side and clears their handles, so the destructors that follow have
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
        if ($this->free === []) {
            return;
        }

        $oldest   = $this->free[0];
        $objectId = spl_object_id($oldest);

        if ((microtime(true) - ($this->idleSince[$objectId] ?? 0.0)) < $this->maxIdleSeconds) {
            return;
        }

        array_shift($this->free);

        unset($this->idleSince[$objectId]);

        $this->discard($oldest);
    }

    /** Opens one channel on a connection that still has room, opening that connection first if need be. */
    protected function open(): Channel
    {
        $index = $this->connectionWithRoom();

        // Counted before the channel is opened rather than after: opening waits for the
        // broker, and several handlers may be doing it at once. A count that only rose on
        // success would leave them all reading the same connection as empty and crowding
        // past its last channel number.
        ++$this->openCounts[$index];

        try {
            $channel = $this->connections[$index]->channel(prefetchCount: self::PREFETCH_COUNT);
        } catch (Throwable $exception) {
            --$this->openCounts[$index];

            throw $exception;
        }

        $this->connectionOf[spl_object_id($channel)] = $index;

        return $channel;
    }

    /**
     * The connection the next channel is opened on: the newest one while it has channel
     * numbers left, a fresh one once it does not.
     */
    protected function connectionWithRoom(): int
    {
        $index = count($this->connections) - 1;

        if (
            $index >= 0
            && $this->openCounts[$index] < ConnectionOptions::usableChannels($this->connections[$index]->maxChannels())
        ) {
            return $index;
        }

        return $this->openConnection();
    }

    /** @return int the index of the connection that was opened */
    protected function openConnection(): int
    {
        $index = count($this->connections);

        $connection = new Connection(
            $this->options->withConnectionName(
                sprintf(
                    '%s publish %d (pid %d)',
                    $this->options->connectionName ?? self::DEFAULT_CONNECTION_NAME,
                    $index + 1,
                    getmypid(),
                ),
            ),
        );

        $this->connections[] = $connection;
        $this->openCounts[]  = 0;

        if ($this->log !== null) {
            ($this->log)(sprintf(
                'consumer: opened publish connection %d for the handlers that publish',
                $index + 1,
            ));
        }

        return $index;
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
        $objectId = spl_object_id($channel);
        $index    = $this->connectionOf[$objectId] ?? null;

        unset($this->connectionOf[$objectId]);

        if ($index === null) {
            return;
        }

        if ($this->openCounts[$index] > 0) {
            --$this->openCounts[$index];
        }

        // All but the newest, which the next channel is opened on: letting that one go would
        // make a worker whose handlers keep giving channels back dirty — a publish nobody
        // waited for — dial a connection per message.
        if ($this->openCounts[$index] === 0 && $index !== count($this->connections) - 1) {
            $this->forgetConnection($index);
        }
    }

    /**
     * Lets go of a connection with no channels left on it, so that a burst gives back its
     * sockets and not only its channels.
     *
     * Dropped rather than closed: this runs on the path a handler returns through, and
     * closing waits for the broker — which an unwound coroutine cannot do at all. The
     * destructor hands the handle back detached, without the waiting.
     */
    protected function forgetConnection(int $index): void
    {
        unset($this->connections[$index], $this->openCounts[$index]);

        $this->connections = array_values($this->connections);
        $this->openCounts  = array_values($this->openCounts);

        // Closing the gap moved every later connection down a place, and the channels still
        // out are counted against those by index. Without this they would decrement the
        // wrong connection when they come back, and a connection that never empties would
        // hold its socket for the life of the run.
        foreach ($this->connectionOf as $objectId => $connectionIndex) {
            if ($connectionIndex > $index) {
                $this->connectionOf[$objectId] = $connectionIndex - 1;
            }
        }
    }
}
