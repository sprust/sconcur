<?php

declare(strict_types=1);

namespace SConcur\Features\Redis;

use Countable;
use SConcur\Exceptions\Redis\InvalidRedisArgumentException;
use SConcur\Exceptions\Redis\NestedPipelineExecutionException;
use SConcur\Features\Redis\Support\Arguments;
use SConcur\Features\Redis\Support\ReplyDecoder;

/**
 * A batch of commands sent in one go: every command is written before any answer is read,
 * so N commands cost one round trip instead of N.
 *
 * Commands are written raw here, with command() alone — the typed methods on Connection
 * reshape the reply of the command they name, and a pipeline hands back a plain list of
 * replies whose order is the order they were added in. Reading a reply out of that list
 * and shaping it is the caller's, where it is visible.
 *
 * A failed command does not fail the batch: the server ran the others, and its failure
 * takes its own place in the list as a Dto\ErrorReply.
 */
class Pipeline implements Countable
{
    /**
     * @var list<array{n: string, a: list<string>}>
     */
    protected array $commands = [];

    /**
     * Set while transaction() owns this pipeline. The callback it runs is meant
     * to add commands, and an execute() inside it would send them on their own —
     * outside the MULTI/EXEC the caller asked for, with their replies dropped.
     */
    protected bool $ownedByTransaction = false;

    public function __construct(
        protected readonly Connection $connection,
    ) {
    }

    /**
     * Adds one command to the batch.
     *
     * @param list<mixed> $arguments
     */
    public function command(string $name, array $arguments = []): static
    {
        $this->commands[] = [
            'n' => $name,
            'a' => Arguments::encode($arguments),
        ];

        return $this;
    }

    /** How many commands the batch holds. */
    public function count(): int
    {
        return count($this->commands);
    }

    /**
     * Sends the batch and returns one reply per command, in the order they were added.
     *
     * With $atomic the batch runs inside MULTI/EXEC — the server runs it as one unit. The
     * pipeline is emptied once the batch has been sent, so a call that failed on the way
     * out leaves it intact and can simply be made again.
     *
     * @return list<mixed>
     */
    public function execute(bool $atomic = false): array
    {
        if ($this->ownedByTransaction) {
            throw new NestedPipelineExecutionException(
                message: 'Calling execute() inside transaction() would send those commands on '
                    . 'their own, outside the MULTI/EXEC, and throw their replies away. '
                    . 'Add the commands and let transaction() send them.',
            );
        }

        return $this->send(atomic: $atomic);
    }

    /**
     * The send transaction() performs itself, past the guard it set.
     *
     * @return list<mixed>
     */
    public function executeAsTransaction(): array
    {
        $this->ownedByTransaction = false;

        return $this->send(atomic: true);
    }

    /** Marks the pipeline as belonging to a transaction that is being built. */
    public function ownByTransaction(): void
    {
        $this->ownedByTransaction = true;
    }

    /**
     * @return list<mixed>
     */
    protected function send(bool $atomic): array
    {
        if ($this->commands === []) {
            throw new InvalidRedisArgumentException(
                message: 'A pipeline needs at least one command.',
            );
        }

        $result = $this->connection->execute(
            command: RedisCommandEnum::Pipeline,
            data: [
                'c'  => $this->commands,
                'at' => $atomic,
            ],
        );

        // Emptied only once the batch is on its way: a send that failed leaves the
        // pipeline as it was, so retrying it is retrying the same commands rather
        // than raising "a pipeline needs at least one command" on an empty one.
        $this->commands = [];

        return ReplyDecoder::decodePipeline($result);
    }
}
