<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\Worker;

use RuntimeException;

/**
 * Spawns the universal master CLI (bin/sconcur-server) as its own process,
 * supervising the demo HTTP server (tests/servers/http/http-server.php) on a
 * loopback port, so tests drive a real master they fully control (start it, signal
 * it, read its log/state). The master launches workers with the built extension
 * loaded and reusePort on (several workers behind one port).
 */
class TestWorkerMaster
{
    private const string HOST = '127.0.0.1';

    /** @var resource */
    private mixed $process;

    private bool $closed = false;

    /**
     * @param resource $process
     */
    private function __construct(
        mixed $process,
        private readonly int $port,
        private readonly string $runtimeDir,
        private readonly string $name,
        private readonly string $configPath,
        private readonly string $outputFile,
    ) {
        $this->process = $process;
    }

    /**
     * Starts a master supervising $workers demo workers on a loopback port. The
     * master is driven by a JSON config file (--configPath); $options set master-level
     * keys, $server sets the nested server block, $workerArgs are extra raw worker argv.
     *
     * @param array<string, int|string> $options       master-level config keys (override defaults)
     * @param array<string, int|string> $env           extra env for the master (inherited by workers)
     * @param list<string>              $workerArgs    extra worker argv (e.g. ['--maxRequests=3'])
     * @param int|null                  $port          bind this exact port (default: a free one)
     * @param bool                      $waitReachable wait until a worker answers before returning
     */
    public static function start(
        array $options = [],
        array $env = [],
        array $workerArgs = [],
        ?int $port = null,
        bool $waitReachable = true,
    ): self {
        $port ??= self::freePort();

        $runtimeDir = self::makeRuntimeDir();
        $name       = (string) ($options['name'] ?? 'sconcur-http-server');

        // The address lives in the server block; an explicit 'address' option (e.g. an
        // unbindable one for the crash-loop test) overrides the loopback default.
        $address = (string) ($options['address'] ?? self::HOST . ':' . $port);

        unset($options['address']);

        $config = [
            'workerScript' => self::workerScript(),
            'workerCount'  => 2,
            'runtimeDir'   => $runtimeDir,
            'logDir'       => $runtimeDir,
            'name'         => $name,
            // Workers must load the built extension; the master itself does not need it.
            'phpArgs'      => ['-d', 'extension=' . self::extensionPath()],
            // Several workers behind one port: each must enable SO_REUSEPORT.
            'server'       => ['address' => $address, 'reusePort' => true],
            'workerArgs'   => array_values($workerArgs),
            ...$options,
        ];

        $configPath = $runtimeDir . '/config.json';

        file_put_contents($configPath, (string) json_encode($config, JSON_PRETTY_PRINT));

        $outputFile = (string) tempnam(sys_get_temp_dir(), 'sc-master-out-');

        $process = self::open(['start', '--configPath=' . $configPath], $env, $outputFile);

        $server = new self(
            process: $process,
            port: $port,
            runtimeDir: $runtimeDir,
            name: $name,
            configPath: $configPath,
            outputFile: $outputFile,
        );

        if ($waitReachable && !$server->waitUntilReachable()) {
            $diagnostics = $server->masterOutput();

            $server->stop();

            throw new RuntimeException(
                'The test master did not become reachable (is ext/build/sconcur.so built?). Output: ' . $diagnostics,
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
        $config = [
            'workerScript' => self::workerScript(),
            'workerCount'  => 1,
            'runtimeDir'   => sys_get_temp_dir(),
            'phpArgs'      => ['-d', 'extension=' . self::extensionPath()],
            'server'       => ['address' => self::HOST . ':0', 'reusePort' => true],
            ...$overrides,
        ];

        $config['logDir'] ??= $config['runtimeDir'];

        $path = (string) tempnam(sys_get_temp_dir(), 'sc-master-cfg-');

        file_put_contents($path, (string) json_encode($config, JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * Runs a one-shot subcommand (start/status/stop) against the given config file to
     * completion and returns [exitCode, combined-output].
     *
     * @param array<string, string> $env
     *
     * @return array{int, string}
     */
    public static function runCommand(string $subcommand, string $configPath, array $env = []): array
    {
        $argv = [$subcommand, '--configPath=' . $configPath];

        $outputFile = (string) tempnam(sys_get_temp_dir(), 'sc-master-cmd-');

        $process = self::open($argv, $env, $outputFile);

        do {
            $status = proc_get_status($process);

            usleep(20_000);
        } while ($status['running']);

        proc_close($process);

        $output = (string) @file_get_contents($outputFile);

        @unlink($outputFile);

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
     * Stops the master (SIGKILL if still running) and cleans up. Idempotent.
     */
    public function stop(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->isRunning()) {
            proc_terminate($this->process, SIGKILL);
        }

        proc_close($this->process);

        @unlink($this->outputFile);

        self::removeDir($this->runtimeDir);
    }

    private function waitUntilReachable(): bool
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
     * @param list<string>          $argv full argv after the bin path
     * @param array<string, string> $env  extra env merged over the inherited one
     *
     * @return resource
     */
    private static function open(array $argv, array $env, string $outputFile): mixed
    {
        $command = [PHP_BINARY, self::binPath(), ...$argv];

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

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function binPath(): string
    {
        return self::root() . '/bin/sconcur-server';
    }

    private static function workerScript(): string
    {
        return self::root() . '/tests/servers/http/http-server.php';
    }

    private static function extensionPath(): string
    {
        return self::root() . '/ext/build/sconcur.so';
    }

    private static function makeRuntimeDir(): string
    {
        $dir = sys_get_temp_dir() . '/sc-master-' . uniqid('', true);

        if (!mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create runtime dir: ' . $dir);
        }

        return $dir;
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach ((array) glob($dir . '/*') as $entry) {
            if (is_string($entry)) {
                @unlink($entry);
            }
        }

        @rmdir($dir);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://' . self::HOST . ':0', $errno, $errstr);

        if ($socket === false) {
            throw new RuntimeException("Could not allocate a port: $errstr");
        }

        $name = (string) stream_socket_get_name($socket, false);

        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
