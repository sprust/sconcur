<?php

declare(strict_types=1);

namespace SConcur\Worker;

use SConcur\Process\HeartbeatMarkEnum;
use SConcur\Process\ProcessControl;
use SConcur\Process\ProcessSignalEnum;

/**
 * One supervised worker process: what the supervisor does with a worker, apart from how
 * it was started (ForkedWorkerProcess). Its stdout and stderr are non-blocking pipes
 * drained line by line into the journal (drainOutput), so a crash's output is kept in one
 * place; the liveness pipe feeds the watchdog and says when the worker is up; signals go
 * by pid.
 */
abstract class WorkerProcess
{
    /**
     * Upper bound on a buffered partial line (no newline yet). A worker spewing a very
     * long line without a newline must not grow the master's memory without limit;
     * once the buffer crosses this, it is flushed as a forcibly split line.
     */
    protected const int MAX_LINE_BYTES = 1_048_576;

    /** @var resource|null */
    protected mixed $stdoutPipe = null;

    /** @var resource|null */
    protected mixed $stderrPipe = null;

    /** @var resource|null the pipe the worker marks itself on (HeartbeatMarkEnum) */
    protected mixed $heartbeatPipe = null;

    /** hrtime of the last byte read from the liveness pipe; null while none has arrived. */
    protected ?int $lastHeartbeatNs = null;

    /** hrtime the worker said it was up (HeartbeatMarkEnum::Ready); null until it does. */
    protected ?int $readyAtNs = null;

    /** The worker said it is stopping because it reached its own limit. */
    protected bool $recycling = false;

    protected int $pid;

    /** Monotonic, so a clock step cannot make a worker look younger or older than it is. */
    protected int $startedAtNs;

    protected bool $running = true;

    // Captured once, on the first running->false transition, and cached: neither
    // proc_get_status nor waitpid reports it a second time.
    protected ?int $exitCode = null;

    protected ?int $termSignal = null;

    protected string $stdoutBuffer = '';

    protected string $stderrBuffer = '';

    /**
     * Brings the running flag and the outcome up to date. The outcome is captured once, on
     * the transition to not running: neither proc_get_status nor waitpid reports it twice.
     */
    abstract protected function refresh(): void;

    /** Gives back what the launch holds once the worker has exited. */
    abstract protected function release(): void;

    public function pid(): int
    {
        return $this->pid;
    }

    public function uptimeSeconds(): float
    {
        return (hrtime(true) - $this->startedAtNs) / 1_000_000_000;
    }

    /**
     * How long the worker kept marking itself alive, or null when it never did.
     *
     * This is the length of its useful life, which is what says whether a worker the
     * watchdog killed had been working or hung from the start — uptime cannot, since a
     * hung worker's uptime keeps growing while it hangs.
     */
    public function markedAliveForSeconds(): ?float
    {
        if ($this->lastHeartbeatNs === null) {
            return null;
        }

        return ($this->lastHeartbeatNs - $this->startedAtNs) / 1_000_000_000;
    }

    public function isRunning(): bool
    {
        $this->refresh();

        return $this->running;
    }

    /**
     * Exit code if the worker terminated normally, or null if it is still running or
     * was killed by a signal (see termSignal).
     */
    public function exitCode(): ?int
    {
        $this->refresh();

        return $this->exitCode;
    }

    public function termSignal(): ?int
    {
        $this->refresh();

        return $this->termSignal;
    }

    /**
     * A clean exit is code 0 and not killed by a signal — what a successful run produces.
     * Used by RestartPolicy; a worker that reached its own limit says so instead
     * (isRecycling), whatever its code.
     */
    public function exitedCleanly(): bool
    {
        $this->refresh();

        return $this->exitCode === 0 && $this->termSignal === null;
    }

    /**
     * Sends a signal to a worker that is still running. Asked first, because a reaped
     * worker's pid may already belong to another process.
     */
    public function signal(ProcessSignalEnum $signal): void
    {
        if ($this->isRunning()) {
            ProcessControl::kill(
                pid: $this->pid,
                signal: $signal,
            );
        }
    }

    /** How the process ended, for the journal: `signal=N` when a signal killed it, `code=N` otherwise. */
    public function exitReason(): string
    {
        $termSignal = $this->termSignal();

        return $termSignal !== null
            ? sprintf('signal=%d', $termSignal)
            : sprintf('code=%d', (int) $this->exitCode());
    }

    /**
     * Reads the liveness pipe dry and, if anything arrived, records when. Every byte is
     * the PHP thread writing, so any of them says it is alive, and the master times it by
     * its own clock — no timestamp has to cross between processes. Two of them say more:
     * that the worker is up, and that it is leaving because it reached its limit.
     */
    public function drainHeartbeat(): void
    {
        if (!is_resource($this->heartbeatPipe)) {
            return;
        }

        $marks = $this->readAvailable($this->heartbeatPipe);

        if ($marks === '') {
            return;
        }

        $nowNs = hrtime(true);

        $this->lastHeartbeatNs = $nowNs;

        if ($this->readyAtNs === null && str_contains($marks, HeartbeatMarkEnum::Ready->byte())) {
            $this->readyAtNs = $nowNs;
        }

        if (str_contains($marks, HeartbeatMarkEnum::Recycling->byte())) {
            $this->recycling = true;
        }
    }

    /** Whether the worker has said it is up (Scheduler::markReady). */
    public function isReady(): bool
    {
        return $this->readyAtNs !== null;
    }

    /** Whether the worker has said it is leaving because it reached its own limit. */
    public function isRecycling(): bool
    {
        return $this->recycling;
    }

    /**
     * How long ago the worker last marked itself alive, or null when it never has: a
     * worker script that drives no scheduler loop writes nothing, and one that has not reached its
     * loop yet has not written yet. Both read as "nothing to judge" — the watchdog acts
     * on evidence of a hang, never on its absence.
     *
     * Monotonic, not wall clock. Both ends of the subtraction are taken in this process,
     * so nothing is given up by it — and a clock step forward larger than the threshold
     * would otherwise age every worker of every group past it at once, and the watchdog
     * would kill the whole fleet on the next tick.
     */
    public function heartbeatAgeSeconds(): ?float
    {
        if ($this->lastHeartbeatNs === null) {
            return null;
        }

        return (hrtime(true) - $this->lastHeartbeatNs) / 1_000_000_000;
    }

    /**
     * Reads whatever is available on stdout/stderr without blocking and returns the
     * complete lines accumulated so far. A partial trailing line is buffered until
     * its newline arrives (or is flushed by drainFinalOutput on exit).
     *
     * @return list<WorkerOutputLine>
     */
    public function drainOutput(): array
    {
        $this->stdoutBuffer .= $this->readAvailable($this->stdoutPipe);
        $this->stderrBuffer .= $this->readAvailable($this->stderrPipe);

        $lines = [];

        $this->extractLines($this->stdoutBuffer, isError: false, lines: $lines);
        $this->extractLines($this->stderrBuffer, isError: true, lines: $lines);

        $this->capBuffer($this->stdoutBuffer, isError: false, lines: $lines);
        $this->capBuffer($this->stderrBuffer, isError: true, lines: $lines);

        return $lines;
    }

    /**
     * Drains the pipes one last time after the worker exited, flushing any buffered
     * partial line (no trailing newline) so the tail of a crash is not lost.
     *
     * @return list<WorkerOutputLine>
     */
    public function drainFinalOutput(): array
    {
        $lines = $this->drainOutput();

        $stdoutTail = rtrim($this->stdoutBuffer, "\r\n");
        $stderrTail = rtrim($this->stderrBuffer, "\r\n");

        $this->stdoutBuffer = '';
        $this->stderrBuffer = '';

        if ($stdoutTail !== '') {
            $lines[] = new WorkerOutputLine(isError: false, line: $stdoutTail);
        }

        if ($stderrTail !== '') {
            $lines[] = new WorkerOutputLine(isError: true, line: $stderrTail);
        }

        return $lines;
    }

    /**
     * Closes the pipes and gives back what the launch holds. Call only once the worker
     * has exited.
     */
    public function close(): void
    {
        $this->refresh();

        foreach ([$this->stdoutPipe, $this->stderrPipe, $this->heartbeatPipe] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->stdoutPipe    = null;
        $this->stderrPipe    = null;
        $this->heartbeatPipe = null;

        $this->release();
    }

    /**
     * @param resource|null $pipe
     */
    protected function readAvailable(mixed $pipe): string
    {
        if (!is_resource($pipe)) {
            return '';
        }

        $data = '';

        while (($chunk = fread($pipe, 65536)) !== false && $chunk !== '') {
            $data .= $chunk;
        }

        return $data;
    }

    /**
     * Bounds the partial-line buffer: while a still-unterminated line is longer than
     * MAX_LINE_BYTES, peel off MAX_LINE_BYTES-sized pieces as forcibly split lines, so
     * a worker that never emits a newline cannot grow the master's memory (or a single
     * log line) without limit.
     *
     * @param list<WorkerOutputLine> $lines
     */
    protected function capBuffer(string &$buffer, bool $isError, array &$lines): void
    {
        while (strlen($buffer) > self::MAX_LINE_BYTES) {
            $lines[] = new WorkerOutputLine(isError: $isError, line: substr($buffer, 0, self::MAX_LINE_BYTES));
            $buffer  = substr($buffer, self::MAX_LINE_BYTES);
        }
    }

    /**
     * Splits complete lines off the front of $buffer (mutated by reference), leaving
     * any partial trailing line behind, and appends them to $lines.
     *
     * @param list<WorkerOutputLine> $lines
     */
    protected function extractLines(string &$buffer, bool $isError, array &$lines): void
    {
        while (($newlinePosition = strpos($buffer, "\n")) !== false) {
            $line   = rtrim(substr($buffer, 0, $newlinePosition), "\r");
            $buffer = substr($buffer, $newlinePosition + 1);

            if ($line !== '') {
                $lines[] = new WorkerOutputLine(isError: $isError, line: $line);
            }
        }
    }
}
