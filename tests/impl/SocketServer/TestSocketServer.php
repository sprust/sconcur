<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\SocketServer;

use RuntimeException;

/**
 * Spawns the demo socket server (tests/servers/socket/socket-server.php) as its own
 * process on a loopback port, so tests run against a real server they fully control
 * — with the exact launch options they need and the ability to signal it.
 *
 * Launch options are named exactly like the SocketServer constructor parameters and
 * override its defaults, e.g.
 * TestSocketServer::start(['maxConcurrency' => 2, 'handlerTimeoutMs' => 200]).
 */
class TestSocketServer
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
        private readonly string $stdoutFile,
    ) {
        $this->process = $process;
    }

    /**
     * @param array<string, int|bool> $options       launch options overriding the server
     *        defaults, keyed by SocketServer constructor parameter name (e.g.
     *        'maxConcurrency'); booleans are passed as 0/1
     * @param bool                     $waitReachable wait until the server accepts a
     *        connection before returning (set false when it is expected to stop early)
     */
    public static function start(array $options = [], ?int $port = null, bool $waitReachable = true): self
    {
        if (isset($options['address'])) {
            throw new RuntimeException('The "address" option is not supported in tests. Use "port" instead.');
        }

        $port ??= self::freePort();
        $options['address'] = self::HOST . ':' . $port;

        $root      = dirname(__DIR__, 3);
        $extension = $root . '/ext/build/sconcur.so';
        $script    = $root . '/tests/servers/socket/socket-server.php';

        $command = ['php', '-d', 'extension=' . $extension, $script];

        foreach ($options as $name => $value) {
            $command[] = '--' . $name . '=' . $value;
        }

        // Capture stdout to a file so tests can read the server's access log.
        $stdoutFile = (string) tempnam(sys_get_temp_dir(), 'sc-sock-out-');

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $root);

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to spawn the test socket server.');
        }

        // stdin is unused; close it so the child never blocks waiting on it.
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $server = new self(
            process: $process,
            port: $port,
            stdoutFile: $stdoutFile,
        );

        if ($waitReachable && !$server->waitUntilReachable()) {
            $server->stop();

            throw new RuntimeException(
                'The test socket server did not become reachable (is ext/build/sconcur.so built?).'
            );
        }

        return $server;
    }

    public function host(): string
    {
        return self::HOST;
    }

    public function port(): int
    {
        return $this->port;
    }

    /**
     * Opens a raw TCP connection to this server, with a read timeout so a caller
     * never hangs forever waiting for a frame.
     *
     * @return resource
     */
    public function connect(float $timeoutSeconds = 5.0): mixed
    {
        $connection = @stream_socket_client(
            'tcp://' . self::HOST . ':' . $this->port,
            $errno,
            $errstr,
            $timeoutSeconds,
        );

        if (!is_resource($connection)) {
            throw new RuntimeException("Could not connect to the test socket server: $errstr");
        }

        stream_set_timeout($connection, (int) $timeoutSeconds);

        return $connection;
    }

    /**
     * Sends one length-prefixed frame (4-byte big-endian length + payload).
     *
     * @param resource $connection
     */
    public static function sendFrame(mixed $connection, string $data): void
    {
        fwrite($connection, pack('N', strlen($data)) . $data);
    }

    /**
     * Reads one length-prefixed frame, or null on a clean connection close (EOF) or
     * a read timeout.
     *
     * @param resource $connection
     */
    public static function receiveFrame(mixed $connection): ?string
    {
        $header = self::readExactly($connection, 4);

        if ($header === null) {
            return null;
        }

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', $header);
        $length   = $unpacked[1];

        if ($length === 0) {
            return '';
        }

        return self::readExactly($connection, $length);
    }

    /**
     * Sends one frame and reads one frame back over a fresh connection.
     */
    public function roundtrip(string $data): ?string
    {
        $connection = $this->connect();

        self::sendFrame($connection, $data);

        $response = self::receiveFrame($connection);

        fclose($connection);

        return $response;
    }

    /**
     * Reads exactly $length bytes, or null if the connection ends (EOF) or times out
     * before that many bytes arrive.
     *
     * @param resource $connection
     */
    private static function readExactly(mixed $connection, int $length): ?string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $chunk = fread($connection, $length - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                $info = stream_get_meta_data($connection);

                if ($info['timed_out'] || feof($connection)) {
                    return null;
                }

                usleep(1_000);

                continue;
            }

            $buffer .= $chunk;
        }

        return $buffer;
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
     * Returns everything the server has written to stdout so far (its access log).
     */
    public function output(): string
    {
        return (string) @file_get_contents($this->stdoutFile);
    }

    /**
     * Waits for the process to exit and returns its exit code, or null if it is still
     * running after the timeout.
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
     * Stops the server (SIGKILL if still running) and releases the handle. Idempotent.
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

        @unlink($this->stdoutFile);
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
