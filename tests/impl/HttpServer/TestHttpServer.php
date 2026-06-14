<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\HttpServer;

use RuntimeException;

/**
 * Spawns the demo HTTP server (tests/servers/http/http-server.php) as its own
 * process on a loopback port, so tests run against a real server they fully
 * control — with the exact launch options they need and the ability to signal it.
 *
 * Launch options are named exactly like the HttpServer constructor parameters and
 * override its defaults, e.g.
 * TestHttpServer::start(['maxRequestBody' => 65536, 'maxConcurrency' => 2]).
 */
final class TestHttpServer
{
    private const string HOST = '127.0.0.1';

    /** @var resource */
    private $process;

    private bool $closed = false;

    /**
     * @param resource $process
     */
    private function __construct(
        $process,
        private readonly int $port,
    ) {
        $this->process = $process;
    }

    /**
     * @param array<string, int> $options launch options overriding the server
     *        defaults, keyed by HttpServer constructor parameter name (e.g.
     *        'maxRequestBody')
     */
    public static function start(array $options = []): self
    {
        $port = self::freePort();

        $root      = dirname(__DIR__, 3);
        $extension = $root . '/ext/build/sconcur.so';
        $script    = $root . '/tests/servers/http/http-server.php';

        $command = ['php', '-d', 'extension=' . $extension, $script, self::HOST . ':' . $port];

        foreach ($options as $name => $value) {
            $command[] = '--' . $name . '=' . $value;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $root);

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to spawn the test HTTP server.');
        }

        // stdin is unused; close it so the child never blocks waiting on it.
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $server = new self($process, $port);

        if (!$server->waitUntilReachable()) {
            $server->stop();

            throw new RuntimeException(
                'The test HTTP server did not become reachable (is ext/build/sconcur.so built?).'
            );
        }

        return $server;
    }

    public function baseUrl(): string
    {
        return 'http://' . self::HOST . ':' . $this->port;
    }

    public function port(): int
    {
        return $this->port;
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
     * Waits for the process to exit and returns its exit code, or null if it is
     * still running after the timeout.
     */
    public function waitForExit(float $timeoutSeconds): ?int
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->process);

            if (!$status['running']) {
                return $status['exitcode'];
            }

            usleep(20_000);
        }

        return null;
    }

    /**
     * Stops the server (SIGKILL if still running) and releases the handle.
     * Idempotent.
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
    }

    private function waitUntilReachable(): bool
    {
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            if (!$this->isRunning()) {
                return false;
            }

            $connection = @fsockopen(self::HOST, $this->port, $errno, $errstr, 0.2);

            if (is_resource($connection)) {
                fclose($connection);

                return true;
            }

            usleep(50_000);
        }

        return false;
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
