<?php

declare(strict_types=1);

namespace SConcur\Features\Redis\Support;

use SConcur\Exceptions\Redis\InvalidRedisArgumentException;

/** The few server commands worth a method. */
trait ServerCommandsTrait
{
    /**
     * @param list<mixed> $arguments
     */
    abstract public function command(
        string $name,
        array $arguments = [],
        ?bool $blocking = null,
        ?int $timeoutMs = null,
    ): mixed;

    public function ping(): bool
    {
        return (string) $this->command('PING') === 'PONG';
    }

    public function dbSize(): int
    {
        return (int) $this->command('DBSIZE');
    }

    /**
     * Empties the current database. The confirmation argument is not decoration: this
     * takes a whole database away, and a method that does it on a bare call is a method
     * somebody eventually calls by mistake.
     */
    public function flushDb(bool $confirm): bool
    {
        if (!$confirm) {
            // Refused rather than answered false: false is also what a flush that did
            // not land would return, so the one value said both "you did not confirm"
            // and "the database may or may not still be there". Every other guard in
            // the feature throws, and this is the one with the most to lose.
            throw new InvalidRedisArgumentException(
                message: 'flushDb() empties the whole database: pass confirm: true to say that is meant.',
            );
        }

        return $this->command('FLUSHDB') !== null;
    }

    /** The INFO text, as the server formats it. */
    public function info(string $section = ''): string
    {
        return (string) $this->command('INFO', $section === '' ? [] : [$section]);
    }
}
