<?php

declare(strict_types=1);

namespace SConcur\Worker;

use SConcur\Exceptions\Worker\WorkerSpawnException;

/**
 * One supervised worker process, spawned via proc_open.
 *
 * The command is passed as an array (no shell), so pid() is the php process itself
 * and signals reach it directly — there is no intermediate `sh -c` to absorb them.
 * stdout/stderr are kept as non-blocking pipes; the master drains them line-by-line
 * (drainOutput) into its log, so a worker crash's output is preserved in one place.
 */
class WorkerProcess
{
    /**
     * Upper bound on a buffered partial line (no newline yet). A worker spewing a very
     * long line without a newline must not grow the master's memory without limit;
     * once the buffer crosses this, it is flushed as a forcibly split line.
     */
    protected const int MAX_LINE_BYTES = 1_048_576;

    /** @var resource */
    protected mixed $process;

    /** @var resource|null */
    protected mixed $stdoutPipe;

    /** @var resource|null */
    protected mixed $stderrPipe;

    protected int $pid;

    protected float $startedAt;

    protected bool $running = true;

    // proc_get_status reports exitcode/termsig only on the first running->false
    // transition, so the outcome is captured once and cached.
    protected ?int $exitCode = null;

    protected ?int $termSignal = null;

    protected string $stdoutBuffer = '';

    protected string $stderrBuffer = '';

    /**
     * @param list<string>          $command full command in array form (no shell)
     * @param string                $cwd     working directory for the worker
     * @param array<string, string> $env     full environment for the worker
     */
    public function __construct(array $command, string $cwd, array $env)
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];

        $process = proc_open(
            command: $command,
            descriptor_spec: $descriptors,
            pipes: $pipes,
            cwd: $cwd,
            env_vars: $env,
        );

        if (!is_resource($process)) {
            throw new WorkerSpawnException(
                message: 'Failed to spawn worker process: ' . implode(' ', $command),
            );
        }

        $status = proc_get_status($process);

        $this->process   = $process;
        $this->pid       = (int) $status['pid'];
        $this->startedAt = microtime(true);

        // stdin is unused; close it so the worker never blocks waiting on it.
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $this->stdoutPipe = $pipes[1] ?? null;
        $this->stderrPipe = $pipes[2] ?? null;

        foreach ([$this->stdoutPipe, $this->stderrPipe] as $pipe) {
            if (is_resource($pipe)) {
                stream_set_blocking($pipe, false);
            }
        }
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function startedAt(): float
    {
        return $this->startedAt;
    }

    public function uptimeSeconds(): float
    {
        return microtime(true) - $this->startedAt;
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
     * A clean exit is code 0 and not killed by a signal — the signal the maxRequests
     * feature and a successful run produce. Used by RestartPolicy.
     */
    public function exitedCleanly(): bool
    {
        $this->refresh();

        return $this->exitCode === 0 && $this->termSignal === null;
    }

    public function signal(int $signal): void
    {
        if ($this->isRunning()) {
            proc_terminate($this->process, $signal);
        }
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
     * Closes the pipes and reaps the process (proc_close), preventing a zombie. Call
     * only once the worker has exited; proc_close blocks until the process is gone.
     */
    public function close(): void
    {
        $this->refresh();

        foreach ([$this->stdoutPipe, $this->stderrPipe] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->stdoutPipe = null;
        $this->stderrPipe = null;

        if (is_resource($this->process)) {
            @proc_close($this->process);
        }
    }

    /**
     * Refreshes the running flag and captures the exit outcome on the first
     * running->false transition (proc_get_status only reports it once).
     */
    protected function refresh(): void
    {
        if (!$this->running) {
            return;
        }

        $status = proc_get_status($this->process);

        if ($status['running']) {
            return;
        }

        $this->running = false;

        if ($status['signaled']) {
            $this->termSignal = (int) $status['termsig'];

            return;
        }

        $this->exitCode = (int) $status['exitcode'];
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
