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

    /** Every worker of the group still running; a fork group's fork servers are not counted. */
    public function aliveSlotCount(): int;

    /** Whether the group failed to start, which stops the master (WorkerMaster::EXIT_NEVER_CAME_UP). */
    public function neverCameUp(): bool;

    /** What failed, for the journal line that stops the master; read once neverCameUp() says so. */
    public function neverCameUpReason(): string;

    /** Why the last reload was given up, once; empty when it was not. */
    public function takeReloadFailure(): string;

    public function spawnAll(): void;

    public function signalAll(ProcessSignalEnum $signal): void;

    /** Logs what the group's processes wrote and handles the ones that exited. */
    public function reapAndLog(bool $stopping): void;

    /**
     * What the group does once a tick besides reaping and the reload: restarts what is due,
     * and for an exec group also ends the drains that overstayed and runs the watchdog.
     */
    public function tick(): void;

    /**
     * Moves the group onto new settings, rolling its workers one slot at a time. Never
     * called while the group is reloading: the master reads no reload request until every
     * reload has ended (WorkerMaster::checkReloadSignal).
     */
    public function reconfigure(WorkerGroupConfig $config): void;

    /** Drives the reload under way; answers whether it is still running. */
    public function driveReload(): bool;

    /** Drains the group for good. Never called while it is reloading, as reconfigure(). */
    public function retire(): void;

    public function unretire(): void;
}
