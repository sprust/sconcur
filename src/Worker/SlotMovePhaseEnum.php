<?php

declare(strict_types=1);

namespace SConcur\Worker;

/** Where the slot move under way is (ForkServerGroup::driveStep). */
enum SlotMovePhaseEnum: string
{
    /** Waiting for the slot to be up in the generation it moves to. */
    case Spawn = 'spawn';

    /** Waiting for the slot to be gone from the generation it moves out of. */
    case Release = 'release';
}
