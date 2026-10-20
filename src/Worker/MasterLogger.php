<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * Writes the master's lifecycle journal to a single per-day file in $logDir, named
 * "<name>-Y-m-d.log" (e.g. sconcur-server-2026-06-18.log). On a day change it
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
 *
 * The fork server of a fork group writes the same journal through a logger of its own, whose
 * own lines are scoped `fork-server: pid group`. Each line is one write, to a file opened for
 * appending and to stdout, so lines of the two processes never cut into each other.
 */
class MasterLogger
{
    public const string INFO  = 'INFO';
    public const string WARN  = 'WARN';
    public const string ERROR = 'ERROR';

    /** @var resource|null */
    protected mixed $handle = null;

    protected string $currentDate = '';

    /**
     * @param string $scope what this logger's own lines are scoped as instead of
     *                      `master: pid` — the fork server of a fork group writes its lines as
     *                      `fork-server: pid group` into the same journal
     */
    public function __construct(
        protected string $logDir,
        protected string $name,
        protected int $rotateDays,
        protected int $masterPid,
        protected LogTarget $logTo = LogTarget::File,
        protected string $scope = '',
    ) {
    }

    /**
     * Where the access lines of workers with `accessLog: file` go: this prefix with
     * "-YYYY-MM-DD.log" after it, a file of the day the extension's logger opens in each
     * worker. Beside the journal and named after it, so pruneOldFiles() takes the old
     * ones with the journal's.
     */
    public static function accessLogPrefix(string $logDir, string $name): string
    {
        return $logDir . '/' . $name . '-access';
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
     * Logs a worker-scoped event (lifecycle or captured output line). The group is part
     * of the scope because a slot index only identifies a worker together with the pool
     * it belongs to — every group numbers its slots from zero.
     *
     * @param array<string, mixed> $context rendered as the trailing context array
     */
    public function worker(
        string $level,
        int $workerPid,
        int $workerIndex,
        string $message,
        string $group = '',
        array $context = [],
    ): void {
        $this->writeLine($level, $workerPid, $workerIndex, $message, $context, $group);
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
    protected function writeLine(
        string $level,
        ?int $workerPid,
        ?int $workerIndex,
        string $message,
        array $context,
        string $group = '',
    ): void {
        $this->rotateIfNeeded();

        $scope = $workerPid === null
            ? ($this->scope === '' ? sprintf('master: %d', $this->masterPid) : $this->scope)
            : sprintf(
                'worker: %d %s#%d',
                $workerPid,
                $group === '' ? '' : $group . ' ',
                (int) $workerIndex,
            );

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
