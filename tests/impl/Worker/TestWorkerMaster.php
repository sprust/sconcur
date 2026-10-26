<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\Worker;

use RuntimeException;
use SConcur\Tests\Impl\FreePort;

/**
 * Spawns a worker master as its own process (tests/servers/worker/master.php, built from a
 * JSON config the test writes), supervising the demo HTTP server
 * (tests/servers/http/http-server.php) on a loopback port, so tests drive a real master
 * they fully control (start it, signal it, read its log/state). The master runs with the
 * built extension loaded, and its workers — forks of it — serve with reusePort on (several
 * workers behind one port).
 */
class TestWorkerMaster
{
    /**
     * How long stop() waits for the master to leave on SIGTERM before it kills it: the
     * workers' shutdownTimeoutMs plus the grace the master gives a SIGKILL plus its own
     * tick, with room to spare.
     */
    protected const float STOP_TIMEOUT_SECONDS = 6.0;

    protected const string HOST = '127.0.0.1';

    /** The flat option keys that describe a pool rather than the master. */
    protected const array GROUP_KEYS = ['workerScript', 'workerCount', 'workerArgs', 'server'];

    /** @var resource */
    protected mixed $process;

    protected bool $closed = false;

    /**
     * @param resource $process
     */
    protected function __construct(
        mixed $process,
        protected readonly int $port,
        protected readonly string $runtimeDir,
        protected readonly string $name,
        protected readonly string $configPath,
        protected readonly string $outputFile,
    ) {
        $this->process = $process;
    }

    /**
     * Starts a master supervising $workers demo workers on a loopback port. The
     * master is driven by a JSON config file; $options set master-level keys, $server
     * sets the nested server block, $workerArgs are extra raw worker argv.
     *
     * @param array<string, mixed>      $options       master-level config keys (override defaults)
     * @param array<string, int|string>               $env           extra env for the master (inherited by workers)
     * @param list<string>              $workerArgs    extra worker argv (e.g. ['--maxRequests=3'])
     * @param int|null                  $port          bind this exact port (default: a free one)
     * @param bool                      $waitReachable wait until a worker answers before returning
     * @param string|null               $masterScript  the script the master runs as, in place of
     *                                                 tests/servers/worker/master.php: one that
     *                                                 loads an application before it starts the
     *                                                 master, say; it is given the config path
     */
    public static function start(
        array $options = [],
        array $env = [],
        array $workerArgs = [],
        ?int $port = null,
        bool $waitReachable = true,
        ?string $masterScript = null,
    ): self {
        $port ??= self::freePort();

        $runtimeDir = self::makeRuntimeDir();
        $name       = (string) ($options['name'] ?? 'sconcur-http-server');

        // The address lives in the server block; an explicit 'address' option (e.g. an
        // unbindable one for the crash-loop test) overrides the loopback default.
        $address = (string) ($options['address'] ?? self::HOST . ':' . $port);

        unset($options['address']);

        $configPath = $runtimeDir . '/config.json';

        file_put_contents($configPath, (string) json_encode(
            self::buildConfig([
                'workerScript' => self::workerScript(),
                'workerCount'  => 2,
                // The production default is 10 s per worker, which the CLI turns
                // into a stop bound of workers × (drain + 2 s) + 5 s — 29 s for
                // the two here. Nothing in these tests drains for more than
                // milliseconds, so the budget is cut to keep both a legitimate
                // stop and the hang detector below in the seconds.
                'shutdownTimeoutMs' => 1_500,
                'runtimeDir'   => $runtimeDir,
                'logDir'       => $runtimeDir,
                'name'         => $name,
                // Several workers behind one port: each must enable SO_REUSEPORT.
                'server'       => ['address' => $address, 'reusePort' => true],
                'workerArgs'   => array_values($workerArgs),
                ...$options,
            ]),
            JSON_PRETTY_PRINT,
        ));

        $outputFile = (string) tempnam(sys_get_temp_dir(), 'sc-master-out-');

        $process = self::open([$configPath], $env, $outputFile, $masterScript ?? self::masterScript());

        $server = new self(
            process: $process,
            port: $port,
            runtimeDir: $runtimeDir,
            name: $name,
            configPath: $configPath,
            outputFile: $outputFile,
        );

        if ($waitReachable && !$server->waitUntilReachable()) {
            // Both, because with logTo=file the master's own output is empty and the reason
            // — a worker that died on startup, a refused config — is in the journal.
            $diagnostics = $server->masterOutput();
            $journal     = $server->logText();

            $server->stop();

            throw new RuntimeException(
                'The test master did not become reachable (is the extension built?). Output: ' . $diagnostics
                . ' Journal: ' . $journal,
            );
        }

        return $server;
    }

    /**
     * Writes a JSON master config (filling sensible defaults) to a temp file and
     * returns its path. For tests that drive a command against a hand-built config
     * (validation, second-instance) rather than a running master.
     *
     * @param array<string, mixed> $overrides
     */
    public static function writeConfig(array $overrides): string
    {
        $flat = [
            'workerScript' => self::workerScript(),
            'workerCount'  => 1,
            'runtimeDir'   => sys_get_temp_dir(),
            'server'       => ['address' => self::HOST . ':0', 'reusePort' => true],
            ...$overrides,
        ];

        $flat['logDir'] ??= $flat['runtimeDir'];

        $path = (string) tempnam(sys_get_temp_dir(), 'sc-master-cfg-');

        file_put_contents($path, (string) json_encode(self::buildConfig($flat), JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * Turns a flat option list into the grouped config the master reads: the keys that
     * describe a pool go into a single group, the rest stay master-wide. Tests keep
     * naming settings flatly, which is what most of them care about.
     *
     * @param array<string, mixed> $flat
     *
     * @return array<string, mixed>
     */
    protected static function buildConfig(array $flat): array
    {
        // A test that needs several pools writes "groups" itself; the flat keys are the
        // shorthand for the single-pool case, which is most of them.
        if (isset($flat['groups'])) {
            foreach (self::GROUP_KEYS as $key) {
                unset($flat[$key]);
            }

            return $flat;
        }

        $group = ['name' => (string) ($flat['groupName'] ?? 'default')];

        unset($flat['groupName']);

        foreach (self::GROUP_KEYS as $key) {
            if (array_key_exists($key, $flat)) {
                $group[$key] = $flat[$key];

                unset($flat[$key]);
            }
        }

        $flat['groups'] = [$group];

        return $flat;
    }

    /**
     * How long a one-shot command may take before the wait gives up.
     *
     * Sized above what MasterControl allows a stop to take (15 s), so a
     * slow-but-legitimate stop is never killed. Everything measured is far under it.
     * The number is a hang detector, and a hang costs 20 s rather than a run that
     * never ends.
     */
    protected const float COMMAND_TIMEOUT_SECONDS = 20.0;

    /**
     * Runs a one-shot command against the given config file to completion and returns
     * [exitCode, combined-output]: `start` runs the master (one that fails to start, or
     * refuses), `status` and `stop` go through MasterControl (tests/servers/worker/control.php).
     *
     * @param array<string, string> $env
     *
     * @return array{int, string}
     */
    public static function runCommand(
        string $subcommand,
        string $configPath,
        array $env = [],
    ): array {
        $outputFile = (string) tempnam(sys_get_temp_dir(), 'sc-master-cmd-');

        $process = $subcommand === 'start'
            ? self::open([$configPath], $env, $outputFile, self::masterScript())
            : self::open([$subcommand, $configPath], $env, $outputFile, self::controlScript());

        // Bounded, because these are one-shot subcommands: start, status, stop.
        // A wait without a deadline turns a subcommand that hangs — a master that
        // never releases the lock, a stop that never returns — into a suite that
        // hangs with it, with no output and no failing test to point at. The
        // process is killed and the caller gets what it printed, so the assertion
        // that follows fails on the evidence instead of never running.
        $deadline = microtime(true) + self::COMMAND_TIMEOUT_SECONDS;

        do {
            $status = proc_get_status($process);

            if (!$status['running']) {
                break;
            }

            if (microtime(true) >= $deadline) {
                proc_terminate($process, SIGKILL);

                $timedOut = true;

                break;
            }

            usleep(20_000);
        } while (true);

        proc_close($process);

        $output = (string) @file_get_contents($outputFile);

        @unlink($outputFile);

        if (isset($timedOut)) {
            throw new RuntimeException(sprintf(
                'the test master command %s did not finish in %.1fs; killed. Output so far: %s',
                $subcommand,
                self::COMMAND_TIMEOUT_SECONDS,
                $output === '' ? '<empty>' : trim($output),
            ));
        }

        return [(int) $status['exitcode'], $output];
    }

    public function baseUrl(): string
    {
        return 'http://' . self::HOST . ':' . $this->port;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function runtimeDir(): string
    {
        return $this->runtimeDir;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function configPath(): string
    {
        return $this->configPath;
    }

    public function pid(): int
    {
        return (int) proc_get_status($this->process)['pid'];
    }

    public function signal(int $signal): void
    {
        posix_kill($this->pid(), $signal);
    }

    public function isRunning(): bool
    {
        return (bool) proc_get_status($this->process)['running'];
    }

    /**
     * The master's own stdout/stderr (CLI errors); the lifecycle journal is the log
     * file, see logText().
     */
    public function masterOutput(): string
    {
        return (string) @file_get_contents($this->outputFile);
    }

    /**
     * Today's master log file contents (the supervision journal).
     */
    public function logText(): string
    {
        $path = $this->runtimeDir . '/' . $this->name . '-' . date('Y-m-d') . '.log';

        return (string) @file_get_contents($path);
    }

    public function stateFilePath(): string
    {
        return $this->runtimeDir . '/' . $this->name . '-state.json';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readState(): ?array
    {
        if (!is_file($this->stateFilePath())) {
            return null;
        }

        $data = json_decode((string) file_get_contents($this->stateFilePath()), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Performs a GET and returns [status, body]; [0, ''] if the connection failed.
     *
     * @return array{int, string}
     */
    public function get(string $path): array
    {
        $curl = curl_init($this->baseUrl() . $path);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FORBID_REUSE   => true,
            CURLOPT_FRESH_CONNECT   => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT        => 3,
        ]);

        $body   = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        return [$status, is_string($body) ? $body : ''];
    }

    /**
     * The pid of whichever worker served a /pid request, or 0 if unreachable.
     */
    public function workerPid(): int
    {
        [$status, $body] = $this->get('/pid');

        return $status === 200 ? (int) $body : 0;
    }

    /**
     * Collects the distinct worker pids seen across many fresh connections (the
     * kernel spreads SO_REUSEPORT connections by 4-tuple, so short connections land
     * on different workers).
     *
     * @return list<int>
     */
    public function distinctWorkerPids(int $attempts = 80): array
    {
        $seen = [];

        for ($i = 0; $i < $attempts; $i++) {
            $pid = $this->workerPid();

            if ($pid > 0) {
                $seen[$pid] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * Waits for the master process to exit; returns its exit code, or null on timeout.
     */
    public function waitForExit(float $timeoutSeconds): ?int
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->process);

            if (!$status['running']) {
                return (int) $status['exitcode'];
            }

            usleep(20_000);
        }

        return null;
    }

    /**
     * Stops the master and cleans up. Idempotent.
     *
     * Asked to stop first, and only killed when it does not: a master killed outright leaves
     * its workers orphaned, and while a server notices its parent changed and leaves, a
     * worker that drives a WaitGroup of its own has no such check and would run on past the
     * test, for as long as the container lives.
     */
    public function stop(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->isRunning()) {
            proc_terminate($this->process, SIGTERM);

            if ($this->waitForExit(timeoutSeconds: self::STOP_TIMEOUT_SECONDS) === null) {
                proc_terminate($this->process, SIGKILL);
            }
        }

        proc_close($this->process);

        @unlink($this->outputFile);

        self::removeDir($this->runtimeDir);
    }

    protected function waitUntilReachable(): bool
    {
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            if (!$this->isRunning()) {
                return false;
            }

            if ($this->workerPid() > 0) {
                return true;
            }

            usleep(50_000);
        }

        return false;
    }

    /**
     * @param list<string>          $argv   argv after the script
     * @param array<string, string> $env    extra env merged over the inherited one
     * @param string                $script the script php runs
     *
     * @return resource
     */
    protected static function open(array $argv, array $env, string $outputFile, string $script): mixed
    {
        // The master takes and sends its signals through the extension, and its workers are
        // forks of it, so it loads the build under test.
        $command = [PHP_BINARY, '-d', 'extension=' . self::extensionPath(), $script, ...$argv];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $outputFile, 'w'],
            2 => ['file', $outputFile, 'a'],
        ];

        $environment = [...getenv(), ...$env];

        $process = proc_open($command, $descriptors, $pipes, self::root(), $environment);

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to spawn the test master.');
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        return $process;
    }

    protected static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    protected static function masterScript(): string
    {
        return self::root() . '/tests/servers/worker/master.php';
    }

    protected static function controlScript(): string
    {
        return self::root() . '/tests/servers/worker/control.php';
    }

    protected static function workerScript(): string
    {
        return self::root() . '/tests/servers/http/http-server.php';
    }

    protected static function extensionPath(): string
    {
        // The build under test, so a run that spawns its own worker loads the same
        // extension the suite does. Without it a run would answer for one core while
        // measuring another.
        return getenv('SCONCUR_EXT') ?: self::root() . '/ext/build/sconcur.so';
    }

    protected static function makeRuntimeDir(): string
    {
        $dir = sys_get_temp_dir() . '/sc-master-' . uniqid('', true);

        if (!mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create runtime dir: ' . $dir);
        }

        return $dir;
    }

    protected static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        // Recurse: workers write their snapshots into a <runtimeDir>/stats subdir,
        // so a flat unlink would leave it (and the runtime dir) behind.
        foreach ((array) glob($dir . '/*') as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            if (is_dir($entry)) {
                self::removeDir($entry);

                continue;
            }

            @unlink($entry);
        }

        @rmdir($dir);
    }

    public static function freePort(): int
    {
        return FreePort::find();
    }
}
