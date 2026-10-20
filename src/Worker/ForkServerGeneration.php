<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * One generation of a fork group: the fork server the master started for it, the settings it
 * was started with, and what its events have said so far.
 */
class ForkServerGeneration
{
    public bool $loaded = false;

    /** Set when every worker finished and nothing was left to restart: the fork server left on its own. */
    public bool $finished = false;

    /** Why the fork server refused to fork, when it said so before exiting. */
    public string $refusedReason = '';

    /** @var array<int, true> the slots this generation keeps or is bringing up */
    public array $slots = [];

    /** @var array<int, int> slot => pid of its worker that said it is up */
    public array $ready = [];

    /** @var array<int, string> slot => why the worker the master asked for did not come up */
    public array $failed = [];

    /** @var array<int, true> slots released and now gone */
    public array $gone = [];

    /** @var list<int> the pids of its workers, as last reported */
    public array $pids = [];

    public function __construct(
        public readonly int $number,
        public readonly WorkerGroupConfig $config,
        public readonly ForkServerProcess $process,
    ) {
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    /** Asks the fork server to bring a slot up, and counts the slot as this generation's. */
    public function spawn(int $slot): void
    {
        $this->slots[$slot] = true;

        unset($this->ready[$slot], $this->failed[$slot], $this->gone[$slot]);

        $this->process->send([
            'c'    => ForkServer::COMMAND_SPAWN,
            'slot' => $slot,
        ]);
    }

    /** Asks the fork server to let a slot go; it says when the worker is gone. */
    public function release(int $slot, string $reason): void
    {
        unset($this->slots[$slot], $this->ready[$slot], $this->gone[$slot]);

        $this->process->send([
            'c'      => ForkServer::COMMAND_RELEASE,
            'slot'   => $slot,
            'reason' => $reason,
        ]);
    }
}
