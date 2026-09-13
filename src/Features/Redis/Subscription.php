<?php

declare(strict_types=1);

namespace SConcur\Features\Redis;

use Iterator;
use SConcur\Exceptions\Redis\SubscriptionClosedException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\TaskExecutionException;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\Redis\Dto\Message;
use SConcur\Features\Redis\Support\Arguments;
use SConcur\Features\Redis\Support\RedisFailure;
use SConcur\Features\Redis\Support\ReplyDecoder;
use SConcur\State;

/**
 * A live subscription. Iterating it yields messages as they are published; the iteration
 * ends when the subscription is closed or the connection goes away.
 *
 * Messages are pulled in batches — the extension hands over whatever has arrived, so a
 * lone message crosses immediately and a fast publisher costs one crossing per batch
 * rather than one per message.
 *
 * The subscription holds a connection of its own for as long as it lives. Closing it, or
 * letting the flow that opened it end, gives that connection back.
 *
 * @implements Iterator<int, Message>
 */
class Subscription implements Iterator
{
    /** @var list<Message> */
    protected array $buffer = [];

    protected int $index = 0;

    protected ?Message $current = null;

    protected bool $closed = false;

    protected bool $finished = false;

    protected bool $released = false;

    protected bool $started = false;

    /**
     * Whether the message currently held has already been handed to a read().
     * A loop started after one must go on to the next message rather than serve
     * that one again — a subscriber doing the work twice is the whole cost of a
     * duplicate.
     */
    protected bool $currentConsumed = false;

    public function __construct(
        protected readonly Connection $connection,
        public readonly string $subscriptionId,
        protected readonly string $streamKey,
    ) {
    }

    /**
     * Adds channels or patterns to the running subscription. The change is made on the
     * connection the subscription is on.
     *
     * @param list<string> $channels
     * @param list<string> $patterns
     */
    public function add(array $channels = [], array $patterns = []): void
    {
        $this->update(operation: 'add', channels: $channels, patterns: $patterns);
    }

    /**
     * Removes channels or patterns from the running subscription.
     *
     * @param list<string> $channels
     * @param list<string> $patterns
     */
    public function remove(array $channels = [], array $patterns = []): void
    {
        $this->update(operation: 'rem', channels: $channels, patterns: $patterns);
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Closes the subscription and releases its connection. Calling it twice does nothing
     * the second time — the flow ending closes it too, and either may come first.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed   = true;
        $this->finished = true;

        if (!FeatureExecutor::canAwait()) {
            // An unwound coroutine has nothing to await an answer on. The flow ending
            // releases the subscription anyway, which is what this call would have done.
            return;
        }

        $this->connection->execute(
            command: RedisCommandEnum::SubscriptionClose,
            data: ['sid' => $this->subscriptionId],
        );

        // The core released the subscription; the flow that owned the stream is
        // this side's to release. Nothing else will: the destructor skips a closed
        // subscription, and the core's own cleanup hook waits on a flow that
        // closing this way never ends. A no-op in async mode.
        $this->releaseTask();
    }

    public function current(): mixed
    {
        return $this->current;
    }

    public function key(): mixed
    {
        return $this->index;
    }

    public function valid(): bool
    {
        return $this->started && $this->current !== null;
    }

    public function rewind(): void
    {
        // The stream is already open — the subscribe() that created this object opened it,
        // and a second one would subscribe again. Rewinding pulls the first message, unless
        // a read() has already taken one: handing that message to the loop as well would
        // deliver it twice, and a subscriber doing the work twice is the whole cost of a
        // duplicate.
        $wasStarted    = $this->started;
        $this->started = true;

        if ($this->currentConsumed) {
            $this->currentConsumed = false;

            $this->pull();

            return;
        }

        // A loop entered on a subscription that has already been iterated — one broken
        // out of, typically — holds a message its body has already seen. Serving it to
        // the new loop as well is the same duplicate the read() case above avoids, and
        // costs the same: the subscriber does the work twice.
        if ($wasStarted && $this->current !== null) {
            $this->pull();

            return;
        }

        if ($this->current === null && !$this->finished) {
            $this->pull();
        }
    }

    public function next(): void
    {
        ++$this->index;

        $this->currentConsumed = false;

        $this->pull();
    }

    /**
     * The next message, or null once the subscription is over. The same pull the iterator
     * uses, for a caller that would rather write its own loop.
     */
    public function read(): ?Message
    {
        $this->started = true;

        $this->pull();

        // Marked consumed, so a loop started after this one goes on to the next
        // message instead of serving this one again.
        $this->currentConsumed = true;

        return $this->current;
    }

    protected function pull(): void
    {
        if ($this->buffer !== []) {
            $this->current = array_shift($this->buffer);

            return;
        }

        if ($this->finished) {
            $this->current = null;

            return;
        }

        try {
            $result = FeatureExecutor::next(taskKey: $this->streamKey);
        } catch (TaskErrorException | TaskExecutionException $exception) {
            $this->finished = true;
            $this->closed   = true;

            throw RedisFailure::from($exception);
        }

        $this->finished = !$result->hasNext;

        /** @var list<array<string, mixed>> $messages */
        $messages = ReplyDecoder::decodeList($result);

        foreach ($messages as $message) {
            $this->buffer[] = new Message(
                channel: (string) ($message['c'] ?? ''),
                payload: (string) ($message['d'] ?? ''),
                pattern: (string) ($message['p'] ?? ''),
                // The core says which it is; an empty pattern does not, because
                // psubscribe to an empty pattern is legal however pointless.
                fromPattern: ($message['k'] ?? 'msg') === 'pmsg',
            );
        }

        if ($this->buffer === []) {
            $this->current = null;

            return;
        }

        $this->current = array_shift($this->buffer);
    }

    /**
     * @param list<string> $channels
     * @param list<string> $patterns
     */
    protected function update(string $operation, array $channels, array $patterns): void
    {
        if ($this->closed) {
            throw new SubscriptionClosedException(
                message: 'The subscription is closed.',
            );
        }

        if ($channels === [] && $patterns === []) {
            return;
        }

        $this->connection->execute(
            command: RedisCommandEnum::SubscriptionUpdate,
            data: [
                'sid' => $this->subscriptionId,
                'op'  => $operation,
                'ch'  => Arguments::encode($channels),
                'pt'  => Arguments::encode($patterns),
            ],
        );
    }

    /**
     * Releases the synchronous flow owning the stream. No-op in async mode, and
     * done once: close() releases it too, and whichever comes first wins.
     */
    protected function releaseTask(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;

        State::releaseSyncTaskFlow($this->streamKey);
    }

    /** Catches the subscription that was abandoned without being closed. */
    public function __destruct()
    {
        $this->releaseTask();
    }
}
