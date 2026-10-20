<?php

declare(strict_types=1);

namespace SConcur\Worker;

use SConcur\Process\ProcessSignalEnum;

/**
 * One group as the master sees it, whichever way its workers start. WorkerGroup runs the
 * workers of an exec group from the master itself; ForkServerGroup runs a fork group through
 * the fork server it starts for each generation, which runs a WorkerGroup of its own. The
 * master asks both the same things once a tick and never needs to know which it has.
 */
interface SupervisedGroup
{
    public function name(): string;

    public function config(): WorkerGroupConfig;

    public function workerCount(): int;

    public function shutdownTimeoutMs(): int;

    public function isReloading(): bool;

    public function isRetiring(): bool;

    public function hasPendingRespawns(): bool;

    /** Whether no process of the group is left. */
    public function allSlotsEmpty(): bool;

    /** Every process of the group still running. */
    public function aliveSlotCount(): int;

    /** Whether the group failed to start, which stops the master (WorkerMaster::EXIT_NEVER_CAME_UP). */
    public function neverCameUp(): bool;

    /** Why the last reload was given up, once; empty when it was not. */
    public function takeReloadFailure(): string;

    public function spawnAll(): void;

    public function signalAll(ProcessSignalEnum $signal): void;

    /** Logs what the group's processes wrote and handles the ones that exited. */
    public function reapAndLog(bool $stopping): void;

    public function respawnDue(): void;

    public function driveDraining(): void;

    public function driveWatchdog(): void;

    public function startReload(): void;

    /** Moves the group onto new settings, rolling its workers one slot at a time. */
    public function reconfigure(WorkerGroupConfig $config): void;

    /** Drives the reload under way; answers whether it is still running. */
    public function driveReload(): bool;

    public function retire(): void;

    public function unretire(): void;
}
