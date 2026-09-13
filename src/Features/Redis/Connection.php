<?php

declare(strict_types=1);

namespace SConcur\Features\Redis;

use Closure;
use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\TaskExecutionException;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\Redis\Payloads\RedisPayload;
use SConcur\Features\Redis\Results\ScanResult;
use SConcur\Features\Redis\Support\Arguments;
use SConcur\Features\Redis\Support\HashCommandsTrait;
use SConcur\Features\Redis\Support\KeyCommandsTrait;
use SConcur\Features\Redis\Support\ListCommandsTrait;
use SConcur\Features\Redis\Support\RedisFailure;
use SConcur\Features\Redis\Support\ReplyDecoder;
use SConcur\Features\Redis\Support\ScriptCommandsTrait;
use SConcur\Features\Redis\Support\ServerCommandsTrait;
use SConcur\Features\Redis\Support\SetCommandsTrait;
use SConcur\Features\Redis\Support\SortedSetCommandsTrait;
use SConcur\Features\Redis\Support\StringCommandsTrait;

/**
 * A Redis connection on top of the extension's Redis feature.
 *
 * The object owns no socket. Ordinary commands share a small pool of multiplexed
 * connections held in the extension and keyed by this dsn, so many coroutines issuing
 * commands at the same time send them down one socket as one pipeline and get their
 * answers back in one round trip. That is what this feature is for; a single command in
 * isolation is slower than phpredis, because it pays the boundary on top of the same work.
 *
 * Outside a WaitGroup every call here works synchronously.
 *
 * Values are bytes. Nothing is prefixed, serialized or compressed on the way through —
 * see docs/redis.md.
 */
readonly class Connection
{
    use HashCommandsTrait;
    use KeyCommandsTrait;
    use ListCommandsTrait;
    use ScriptCommandsTrait;
    use ServerCommandsTrait;
    use SetCommandsTrait;
    use SortedSetCommandsTrait;
    use StringCommandsTrait;

    protected int $timeoutMs;

    protected int $poolSize;

    protected int $connMaxLifetimeMs;

    /**
     * @param string   $dsn               redis://[user:password@]host:port/db, rediss://… for
     *                                    TLS, unix:///path/to.sock for a socket
     * @param int|null $timeoutMs         deadline for one command, 30000 by default; 0 means
     *                                    no deadline
     * @param int|null $poolSize          multiplexed connections per process for this dsn;
     *                                    4 by default, 64 at most
     * @param int|null $connMaxLifetimeMs how long a pooled connection is kept before it is
     *                                    replaced; 0 keeps it for the life of the process
     */
    public function __construct(
        public string $dsn,
        ?int $timeoutMs = null,
        ?int $poolSize = null,
        ?int $connMaxLifetimeMs = null,
    ) {
        $this->timeoutMs         = $timeoutMs ?? 30000;
        $this->poolSize          = $poolSize ?? 0;
        $this->connMaxLifetimeMs = $connMaxLifetimeMs ?? 0;
    }

    /**
     * Runs one command and returns the reply as the server sent it: a string for a bulk
     * reply, an int for an integer, null for a nil, a list for an array.
     *
     * This is the way to call a command that has no method of its own, and it is not a
     * lesser path — the typed methods are here for the commands whose reply is worth
     * reshaping or whose argument order is easy to get wrong.
     *
     * @param list<mixed> $arguments strings, ints and floats; anything else is refused
     * @param bool|null   $blocking  null lets the core decide by command name, which is
     *                               right for everything except XREAD/XREADGROUP, where
     *                               only the arguments say whether the command waits
     */
    public function command(
        string $name,
        array $arguments = [],
        ?bool $blocking = null,
        ?int $timeoutMs = null,
    ): mixed {
        $result = $this->execute(
            command: RedisCommandEnum::Command,
            data: [
                'n'  => $name,
                'a'  => Arguments::encode($arguments),
                // 0 — the core decides by command name, 1 — blocking, 2 — not blocking.
                'bl' => match ($blocking) {
                    null  => 0,
                    true  => 1,
                    false => 2,
                },
            ],
            timeoutMs: $timeoutMs,
        );

        return ReplyDecoder::decode($result);
    }

    /**
     * Opens a pipeline: commands are collected and sent as one batch, so N commands cost
     * one round trip instead of N. Nothing is sent until execute().
     */
    public function pipeline(): Pipeline
    {
        return new Pipeline(connection: $this);
    }

    /**
     * Runs the commands the callback adds inside MULTI/EXEC: the server runs them as one
     * unit with nothing else in between.
     *
     * The callback cannot read anything as it goes — a transaction's replies all arrive
     * after EXEC. Optimistic locking with WATCH needs a connection pinned across round
     * trips and is not supported.
     *
     * @param Closure(Pipeline): void $build
     *
     * @return list<mixed> one reply per command, in order
     */
    public function transaction(Closure $build): array
    {
        $pipeline = new Pipeline(connection: $this);

        $pipeline->ownByTransaction();

        $build($pipeline);

        return $pipeline->executeAsTransaction();
    }

    /**
     * Walks the keyspace with SCAN, pulling batches from the extension as the iterator is
     * consumed. `count` is a hint to the server about how much work one round trip does;
     * `batchSize` is how many keys cross the boundary at a time. They are different
     * numbers and neither bounds the total.
     *
     * SCAN gives no snapshot: a key that existed throughout is returned at least once, one
     * added or removed along the way may or may not appear, and duplicates are possible.
     * That is Redis, not this feature.
     */
    public function scan(string $match = '', int $count = 0, int $batchSize = 0): ScanResult
    {
        return $this->cursor(name: 'SCAN', head: [], match: $match, count: $count, batchSize: $batchSize);
    }

    /**
     * HSCAN over one hash. Yields field => value.
     */
    public function hScan(string $key, string $match = '', int $count = 0, int $batchSize = 0): ScanResult
    {
        return $this->cursor(
            name: 'HSCAN',
            head: [$key],
            match: $match,
            count: $count,
            batchSize: $batchSize,
            pairs: true,
        );
    }

    /**
     * SSCAN over one set. Yields the members.
     */
    public function sScan(string $key, string $match = '', int $count = 0, int $batchSize = 0): ScanResult
    {
        return $this->cursor(name: 'SSCAN', head: [$key], match: $match, count: $count, batchSize: $batchSize);
    }

    /**
     * ZSCAN over one sorted set. Yields member => score.
     */
    public function zScan(string $key, string $match = '', int $count = 0, int $batchSize = 0): ScanResult
    {
        return $this->cursor(
            name: 'ZSCAN',
            head: [$key],
            match: $match,
            count: $count,
            batchSize: $batchSize,
            pairs: true,
        );
    }

    /**
     * Subscribes to channels, patterns, or both, and returns the live subscription to pull
     * messages from.
     *
     * A subscription owns a connection of its own, because the protocol puts the connection
     * itself into subscriber mode. Grouping channels into one subscription is therefore
     * much cheaper than one subscription per channel.
     *
     * @param list<string> $channels
     * @param list<string> $patterns
     */
    public function subscribe(array $channels = [], array $patterns = [], int $batchSize = 0): Subscription
    {
        $result = $this->execute(
            command: RedisCommandEnum::Subscribe,
            data: [
                'ch' => Arguments::encode($channels),
                'pt' => Arguments::encode($patterns),
                'bs' => $batchSize,
            ],
            // SUBSCRIBE is a command on the wire, so the connection's own deadline covers
            // it: a host that finishes the handshake and then stops answering would
            // otherwise park this call for ever, with nothing but a flow stop able to end
            // it. What the deadline does not cover is the wait for messages — each batch
            // is pulled by a call of its own, and that one deliberately has none.
        );

        /** @var array<string, mixed> $meta */
        $meta = ReplyDecoder::decode($result);

        return new Subscription(
            connection: $this,
            subscriptionId: (string) ($meta['sid'] ?? ''),
            streamKey: $result->key,
        );
    }

    /**
     * Runs a payload of this connection and turns a failed task into the exception the
     * caller expects. Every path through this class goes here.
     *
     * @param array<string, mixed> $data
     */
    public function execute(
        RedisCommandEnum $command,
        array $data,
        ?int $timeoutMs = null,
    ): TaskResultDto {
        try {
            return FeatureExecutor::exec(
                payload: new RedisPayload(
                    command: $command,
                    dsn: $this->dsn,
                    timeoutMs: $timeoutMs ?? $this->timeoutMs,
                    poolSize: $this->poolSize,
                    connMaxLifetimeMs: $this->connMaxLifetimeMs,
                    data: $data,
                ),
            );
        } catch (TaskErrorException | TaskExecutionException $exception) {
            throw RedisFailure::from($exception);
        }
    }

    /**
     * The deadline a blocking command needs: its own wait plus this connection's budget
     * for a command.
     *
     * Neither number is invented here. A connection told to work without a deadline gets
     * none for a blocking call either — the command's own wait is the bound, and the
     * budget is added on top of it rather than floored at anything, so a connection
     * configured tight stays tight instead of being quietly widened to a second.
     */
    public function blockingDeadlineMs(float $timeoutSeconds): int
    {
        if ($this->timeoutMs <= 0) {
            return 0;
        }

        if ($timeoutSeconds <= 0.0) {
            // Waiting forever is only coherent without a deadline; the core refuses the
            // combination rather than cutting the wait short.
            return 0;
        }

        return (int) round($timeoutSeconds * 1000) + $this->timeoutMs;
    }

    /**
     * @param list<string> $head the arguments before the cursor: the key, for the
     *                           cursor commands that take one
     */
    protected function cursor(
        string $name,
        array $head,
        string $match,
        int $count,
        int $batchSize,
        bool $pairs = false,
    ): ScanResult {
        $arguments = $head;

        if ($match !== '') {
            $arguments[] = 'MATCH';
            $arguments[] = $match;
        }

        if ($count > 0) {
            $arguments[] = 'COUNT';
            $arguments[] = (string) $count;
        }

        return new ScanResult(
            connection: $this,
            name: $name,
            arguments: $arguments,
            batchSize: $batchSize,
            pairs: $pairs,
        );
    }
}
