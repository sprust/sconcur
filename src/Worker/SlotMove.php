<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * One step of a fork group's reload: a slot brought up in the generation it moves to, then
 * let go in the one it moves out of. A move with no `to` only lets the slot go (one the new
 * worker count drops); one with no `from` only brings it up.
 */
readonly class SlotMove
{
    public function __construct(
        public int $slot,
        public ?ForkServerGeneration $from,
        public ?ForkServerGeneration $to,
    ) {
    }
}
