<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

use SConcur\Features\Amqp\Connection;
use SConcur\Features\Amqp\Consumer\PublishChannelPool;

/**
 * A PublishChannelPool that loses every connection it chooses, the moment it chooses it.
 *
 * Choosing the connection to open the next channel on is made of calls, and automatic
 * preemption parks a coroutine at any of them — so between the choice and the count a
 * handler releasing its last channel can let that very connection go. The window is a
 * couple of opcodes wide and cannot be aimed at from outside; this stands in for it.
 */
class LosingPublishChannelPool extends PublishChannelPool
{
    /** How many choices were taken away before a channel could be counted on them. */
    protected int $lostChoices = 0;

    public function lostChoices(): int
    {
        return $this->lostChoices;
    }

    protected function newestWithRoom(): ?Connection
    {
        $connection = parent::newestWithRoom();

        if ($connection === null) {
            return null;
        }

        ++$this->lostChoices;

        $this->forgetConnection($connection);

        return $connection;
    }
}
