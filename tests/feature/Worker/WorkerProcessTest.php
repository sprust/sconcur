<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use PHPUnit\Framework\TestCase;
use SConcur\Worker\WorkerProcess;

/**
 * Unit coverage of WorkerProcess: it spawns a short `php -r` script (no extension
 * needed), so a real child process exercises liveness, the cached exit outcome,
 * signal delivery (the pid is php itself, not a shell) and line-buffered output
 * draining including the no-newline tail flushed on exit.
 */
class WorkerProcessTest extends TestCase
{
    public function testReportsPidAndRunningThenExit(): void
    {
        $process = $this->spawn('usleep(200000);');

        try {
            self::assertGreaterThan(0, $process->pid());
            self::assertTrue($process->isRunning(), 'a freshly spawned worker must be running');

            self::assertTrue($this->waitForExit($process), 'the worker should exit on its own');
            self::assertFalse($process->isRunning());
        } finally {
            $process->close();
        }
    }

    public function testCapturesNonZeroExitCode(): void
    {
        $process = $this->spawn('exit(7);');

        try {
            self::assertTrue($this->waitForExit($process));

            self::assertSame(7, $process->exitCode());
            self::assertNull($process->termSignal());
            self::assertFalse($process->exitedCleanly(), 'a non-zero exit is not a clean exit');
        } finally {
            $process->close();
        }
    }

    public function testCleanExitIsReportedAsClean(): void
    {
        $process = $this->spawn('exit(0);');

        try {
            self::assertTrue($this->waitForExit($process));

            self::assertSame(0, $process->exitCode());
            self::assertNull($process->termSignal());
            self::assertTrue($process->exitedCleanly());
        } finally {
            $process->close();
        }
    }

    public function testSignalReachesThePhpProcessDirectly(): void
    {
        // Command is an array (no shell), so the pid is php itself and the signal is
        // not absorbed by an intermediate `sh -c`.
        $process = $this->spawn('sleep(30);');

        try {
            self::assertTrue($process->isRunning());

            $process->signal(SIGTERM);

            self::assertTrue($this->waitForExit($process), 'the signalled worker should terminate');

            self::assertSame(SIGTERM, $process->termSignal(), 'a signalled worker reports its term signal');
            self::assertNull($process->exitCode());
            self::assertFalse($process->exitedCleanly());
        } finally {
            $process->close();
        }
    }

    public function testDrainsCompleteLinesAndFlushesTailOnExit(): void
    {
        // Two stdout lines, one stderr line, and a trailing partial line with no
        // newline — the tail must surface via drainFinalOutput after the exit.
        $process = $this->spawn(
            'fwrite(STDOUT, "out1\nout2\n"); fwrite(STDERR, "err1\n"); fwrite(STDOUT, "tail-no-newline");',
        );

        try {
            self::assertTrue($this->waitForExit($process));

            $lines = $process->drainFinalOutput();

            $stdout = [];
            $stderr = [];

            foreach ($lines as $line) {
                if ($line->isError) {
                    $stderr[] = $line->line;
                } else {
                    $stdout[] = $line->line;
                }
            }

            self::assertSame(['out1', 'out2', 'tail-no-newline'], $stdout, 'stdout lines incl. the no-newline tail');
            self::assertSame(['err1'], $stderr, 'stderr line is flagged as an error line');
        } finally {
            $process->close();
        }
    }

    public function testCapsAnOverlongLineWithoutNewline(): void
    {
        // Emit 2 MiB with no newline, then a real newline + "end". The master must not
        // buffer the whole 2 MiB as one growing line: it force-splits past its cap. We
        // drain continuously (the OS pipe is ~64 KiB, so the writer blocks until read).
        $totalBytes = 2 * 1024 * 1024;

        // The big write blocks until the master drains it (pipe is full), so the cap
        // fires before the newline arrives; the short pause keeps the newline strictly
        // after that, making the split deterministic.
        $process = $this->spawn(
            sprintf('fwrite(STDOUT, str_repeat("x", %d)); fflush(STDOUT); usleep(100000); fwrite(STDOUT, "\nend\n");', $totalBytes),
        );

        try {
            $stdoutLines = [];

            $deadline = microtime(true) + 5.0;

            while (microtime(true) < $deadline) {
                foreach ($process->drainOutput() as $line) {
                    if (!$line->isError) {
                        $stdoutLines[] = $line->line;
                    }
                }

                if (!$process->isRunning()) {
                    break;
                }

                usleep(5_000);
            }

            foreach ($process->drainFinalOutput() as $line) {
                if (!$line->isError) {
                    $stdoutLines[] = $line->line;
                }
            }

            $xLines = array_values(array_filter($stdoutLines, static fn(string $line): bool => trim($line, 'x') === ''));

            self::assertGreaterThanOrEqual(2, count($xLines), 'the overlong line must be force-split into >= 2 pieces');
            self::assertSame($totalBytes, array_sum(array_map('strlen', $xLines)), 'every x byte is preserved across the split');
            self::assertContains('end', $stdoutLines, 'the trailing real line still surfaces');
        } finally {
            $process->close();
        }
    }

    private function spawn(string $code): WorkerProcess
    {
        return new WorkerProcess(
            command: [PHP_BINARY, '-r', $code],
            cwd: sys_get_temp_dir(),
            env: getenv(),
        );
    }

    private function waitForExit(WorkerProcess $process, float $timeoutSeconds = 5.0): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if (!$process->isRunning()) {
                return true;
            }

            usleep(20_000);
        }

        return !$process->isRunning();
    }
}
