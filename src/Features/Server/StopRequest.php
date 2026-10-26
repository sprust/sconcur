<?php

declare(strict_types=1);

namespace SConcur\Features\Server;

use SConcur\Process\ProcessControl;
use SConcur\Process\ProcessSignalEnum;
use SConcur\Worker\WorkerContext;

/**
 * Whether a long-lived worker has been asked to stop: by SIGTERM or SIGINT, or by its
 * master going away. Asked by the serve loop after every result it takes, so the answer
 * is the loop's stop check.
 *
 * Both questions go to the extension rather than to ext-pcntl and ext-posix. A signal is
 * recorded by a handler that only sets a bit, and no PHP code runs inside it. The master
 * is gone once it is no longer this process's parent: the kernel hands an orphan to init
 * or a subreaper, so the parent pid changes, and a recycled pid cannot fake it back.
 *
 * The extension is asked at most once per CHECK_INTERVAL_NS. The loop asks after every
 * result, and a stop that is noticed a tenth of a second later costs nothing, while a
 * call into the extension per request would cost every request.
 */
class StopRequest
{
    protected const int CHECK_INTERVAL_NS = 100_000_000;

    protected bool $requested = false;

    protected int $checkedAtNs = 0;

    /**
     * @param null|int $masterPid the pid of the master that started this worker; null
     *                            when it runs without one, which leaves only the signals
     */
    public function __construct(protected ?int $masterPid)
    {
    }

    /**
     * Starts watching SIGTERM and SIGINT. Called before the listener opens, so a signal
     * that arrives while it does is not lost. Without a master pid of its own, a worker a
     * master forked watches that master (WorkerContext).
     */
    public static function watch(?int $masterPid): self
    {
        ProcessControl::watchSignals([
            ProcessSignalEnum::Terminate,
            ProcessSignalEnum::Interrupt,
        ]);

        return new self($masterPid ?? WorkerContext::current()?->masterPid);
    }

    public function isRequested(): bool
    {
        if ($this->requested) {
            return true;
        }

        $nowNs = hrtime(true);

        if ($this->checkedAtNs !== 0 && ($nowNs - $this->checkedAtNs) < self::CHECK_INTERVAL_NS) {
            return false;
        }

        $this->checkedAtNs = $nowNs;

        $this->requested = ProcessControl::takeSignals() !== []
            || ($this->masterPid !== null && ProcessControl::parentPid() !== $this->masterPid);

        return $this->requested;
    }

    /**
     * Puts back what handled SIGTERM and SIGINT before. Called once the loop is over.
     */
    public function release(): void
    {
        ProcessControl::restoreSignals();
    }
}
