<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * Writes the master's lifecycle journal to a single per-day file in $logDir, named
 * "<name>-Y-m-d.log" (e.g. sconcur-http-server-2026-06-18.log). On a day change it
 * opens the new file and deletes files older than $rotateDays (retention rotation,
 * no logrotate needed).
 *
 * Line format follows the project logger convention — bracketed timestamp (with
 * microseconds), level, a bracketed scope tag, the message and a trailing context
 * array:
 *   [Y-m-d H:i:s.uuuuuu] LEVEL [master: pid]: <message> []
 *   [Y-m-d H:i:s.uuuuuu] LEVEL [worker: pid #index]: <message> []
 *
 * Worker stdout/stderr captured by the master is re-emitted here via worker(), so a
 * crash's output lives in the same file and format as the lifecycle events.
 */
class MasterLogger
{
    public const string INFO  = 'INFO';
    public const string WARN  = 'WARN';
    public const string ERROR = 'ERROR';

    /** @var resource|null */
    protected mixed $handle = null;

    protected string $currentDate = '';

    public function __construct(
        protected string $logDir,
        protected string $name,
        protected int $rotateDays,
        protected int $masterPid,
        protected LogTarget $logTo = LogTarget::File,
    ) {
    }

    /**
     * Logs a master-scoped event.
     *
     * @param array<string, mixed> $context rendered as the trailing context array
     */
    public function master(string $level, string $message, array $context = []): void
    {
        $this->writeLine($level, null, null, $message, $context);
    }

    /**
     * Logs a worker-scoped event (lifecycle or captured output line).
     *
     * @param array<string, mixed> $context rendered as the trailing context array
     */
    public function worker(string $level, int $workerPid, int $workerIndex, string $message, array $context = []): void
    {
        $this->writeLine($level, $workerPid, $workerIndex, $message, $context);
    }

    /**
     * Flushes buffered lines to the enabled sinks. Called once per supervision tick so
     * STDOUT (under `docker logs`) and the file stay timely without a syscall per line.
     */
    public function flush(): void
    {
        if ($this->logTo->toFile() && is_resource($this->handle)) {
            fflush($this->handle);
        }

        if ($this->logTo->toStdout()) {
            fflush(STDOUT);
        }
    }

    public function close(): void
    {
        $this->flush();

        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        $this->handle = null;
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function writeLine(string $level, ?int $workerPid, ?int $workerIndex, string $message, array $context): void
    {
        $this->rotateIfNeeded();

        $scope = $workerPid === null
            ? sprintf('master: %d', $this->masterPid)
            : sprintf('worker: %d #%d', $workerPid, (int) $workerIndex);

        $line = sprintf(
            "[%s] %s [%s]: %s %s\n",
            $this->timestamp(),
            $level,
            $scope,
            $message,
            $this->encodeContext($context),
        );

        if ($this->logTo->toFile() && is_resource($this->handle)) {
            fwrite($this->handle, $line);
        }

        if ($this->logTo->toStdout()) {
            fwrite(STDOUT, $line);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function encodeContext(array $context): string
    {
        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '[]' : $encoded;
    }

    protected function rotateIfNeeded(): void
    {
        if (!$this->logTo->toFile()) {
            return;
        }

        $today = date('Y-m-d');

        if ($today === $this->currentDate && is_resource($this->handle)) {
            return;
        }

        $this->close();

        $this->currentDate = $today;

        $path = $this->logDir . '/' . $this->name . '-' . $today . '.log';

        $handle = fopen($path, 'a');

        $this->handle = $handle === false ? null : $handle;

        $this->pruneOldFiles();
    }

    protected function pruneOldFiles(): void
    {
        if ($this->rotateDays <= 0) {
            return;
        }

        $files = glob($this->logDir . '/' . $this->name . '-*.log');

        if ($files === false) {
            return;
        }

        $cutoff = strtotime('-' . ($this->rotateDays - 1) . ' days', strtotime($this->currentDate));

        if ($cutoff === false) {
            return;
        }

        foreach ($files as $file) {
            if (!preg_match('/-(\d{4}-\d{2}-\d{2})\.log$/', $file, $matches)) {
                continue;
            }

            $fileTime = strtotime($matches[1]);

            if ($fileTime !== false && $fileTime < $cutoff) {
                @unlink($file);
            }
        }
    }

    protected function timestamp(): string
    {
        $now          = microtime(true);
        $microseconds = (int) (($now - floor($now)) * 1_000_000);

        return date('Y-m-d H:i:s', (int) $now) . sprintf('.%06d', $microseconds);
    }
}
