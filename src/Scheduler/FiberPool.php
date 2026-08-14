<?php

declare(strict_types=1);

namespace SConcur\Scheduler;

use Closure;
use Fiber;
use SConcur\State;
use Throwable;

/**
 * Recycles the fibers running spawned coroutines. Creating and destroying a
 * fiber per request is dominated not by the switching but by the stack
 * lifecycle: minor faults on the first touch of the stack pages plus the
 * munmap TLB shootdown across the Go runtime's threads. A pooled fiber maps
 * and touches its stack once and then serves many jobs.
 *
 * A fiber terminates when its callback returns, and a terminated fiber cannot
 * be restarted — so the pooled worker callback never returns: it is an
 * infinite loop that parks on Fiber::suspend(FiberPoolSignal::Idle), receives
 * the next job as the resume() value, runs it and parks again. That signal is
 * how the Scheduler distinguishes "the job finished" from "the job suspended on
 * a pending task"; it is an enum case, so handler code cannot forge it (see
 * FiberPoolSignal).
 *
 * Sizing: a parked fiber costs ~10 KiB of RSS but keeps its whole stack mapped
 * (~2 MiB of address space each, at PHP's default fiber.stack_size). The cap is
 * therefore about address space and mapping count, not memory: a full pool of
 * 256 holds ~2.5 MiB resident against ~520 MiB of virtual mappings.
 */
class FiberPool
{
    protected const int DEFAULT_MAX_IDLE = 256;

    /**
     * Fibers parked idle, ready to take a job. Live (working) fibers are not
     * tracked here — they are referenced by their Coroutine until released.
     *
     * @var list<Fiber>
     */
    protected array $idleFibers = [];

    /**
     * @param int $maxIdle how many parked fibers release() keeps; an over-cap
     *                     release terminates the fiber so its stack is unmapped
     */
    public function __construct(
        protected int $maxIdle = self::DEFAULT_MAX_IDLE,
    ) {
    }

    public function acquire(): Fiber
    {
        while (($fiber = array_pop($this->idleFibers)) !== null) {
            // Defensive: an idle fiber can only be suspended, but resuming a
            // terminated one would throw into the request path — skip it.
            if ($fiber->isSuspended()) {
                return $fiber;
            }
        }

        return $this->create();
    }

    public function release(Fiber $fiber): void
    {
        if (count($this->idleFibers) >= $this->maxIdle) {
            // Over the cap: a non-Closure job makes the worker loop return, so
            // the fiber terminates and its stack dies with it.
            $fiber->resume(null);

            return;
        }

        $this->idleFibers[] = $fiber;
    }

    public function idleCount(): int
    {
        return count($this->idleFibers);
    }

    protected function create(): Fiber
    {
        $fiber = new Fiber(static function (): void {
            $currentFiber = Fiber::getCurrent();

            $workerFiberId = $currentFiber === null ? 0 : spl_object_id($currentFiber);

            while (true) {
                // The park is a suspend transition like any other: guard it
                // against automatic preemption (see State::markSuspending).
                State::markSuspending($workerFiberId);

                try {
                    $job = Fiber::suspend(FiberPoolSignal::Idle);
                } finally {
                    State::clearSuspending();
                }

                // Eviction: release() over the cap resumes with null.
                if (!$job instanceof Closure) {
                    return;
                }

                try {
                    $job();
                } catch (Throwable) {
                    // Must not escape: a throw here would terminate the fiber
                    // and silently drain the pool under load. Parity with the
                    // pre-pool behavior — a spawned coroutine's failure is
                    // dropped (see Scheduler::failCoroutine).
                }
            }
        });

        // Run to the first suspend: the stack is mapped and its pages are first
        // touched here, once per fiber instead of once per request.
        $fiber->start();

        return $fiber;
    }
}
