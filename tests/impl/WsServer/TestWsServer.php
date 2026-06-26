<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\WsServer;

use RuntimeException;

/**
 * Spawns the demo WebSocket server (tests/servers/ws/ws-server.php) as its own process
 * on a loopback port, so tests run against a real server they fully control — with the
 * exact launch options they need and the ability to signal it.
 *
 * It also carries a minimal raw WebSocket client (the upgrade handshake plus masked
 * client frames / unmasked server frames) so the server can be exercised at the wire
 * level without depending on WsClient. The richer test suites dogfood WsClient instead;
 * this raw path keeps one protocol-level test honest.
 *
 * Launch options are named exactly like the WsServer constructor parameters and override
 * its defaults, e.g. TestWsServer::start(['maxConcurrency' => 2]).
 */
class TestWsServer
{
    private const string HOST = '127.0.0.1';

    /** The fixed GUID appended to the client key to form Sec-WebSocket-Accept (RFC 6455). */
    private const string ACCEPT_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    private const int OPCODE_TEXT   = 0x1;
    private const int OPCODE_BINARY = 0x2;
    private const int OPCODE_CLOSE  = 0x8;

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
     * @param array<string, int|bool|string> $options       launch options overriding the server
     *        defaults, keyed by WsServer constructor parameter name; booleans are passed as 0/1
     * @param bool                            $waitReachable wait until the server accepts a
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
        $script    = $root . '/tests/servers/ws/ws-server.php';

        $command = ['php', '-d', 'extension=' . $extension, $script];

        foreach ($options as $name => $value) {
            $command[] = '--' . $name . '=' . $value;
        }

        // Capture stdout to a file so tests can read the server's access log.
        $stdoutFile = (string) tempnam(sys_get_temp_dir(), 'sc-ws-out-');

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $root);

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to spawn the test ws server.');
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
                'The test ws server did not become reachable (is ext/build/sconcur.so built?).'
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
     * Opens a raw TCP connection and performs the WebSocket upgrade handshake on the
     * given path, with a read timeout so a caller never hangs forever.
     *
     * @return resource
     */
    public function connect(string $path = '/', float $timeoutSeconds = 5.0): mixed
    {
        $connection = @stream_socket_client(
            'tcp://' . self::HOST . ':' . $this->port,
            $errno,
            $errstr,
            $timeoutSeconds,
        );

        if (!is_resource($connection)) {
            throw new RuntimeException("Could not connect to the test ws server: $errstr");
        }

        stream_set_timeout($connection, (int) $timeoutSeconds);

        $this->handshake($connection, $path);

        return $connection;
    }

    /**
     * Sends one WebSocket message as a single masked client frame (text by default).
     *
     * @param resource $connection
     */
    public static function sendMessage(mixed $connection, string $data, bool $binary = false): void
    {
        $opcode = $binary ? self::OPCODE_BINARY : self::OPCODE_TEXT;

        fwrite($connection, self::encodeClientFrame($opcode, $data));
    }

    /**
     * Reads one WebSocket message from the server, or null on a close frame / EOF.
     * Returns ['data' => string, 'binary' => bool].
     *
     * @param resource $connection
     *
     * @return array{data: string, binary: bool}|null
     */
    public static function receiveMessage(mixed $connection): ?array
    {
        $header = self::readExactly($connection, 2);

        if ($header === null) {
            return null;
        }

        $firstByte  = ord($header[0]);
        $secondByte = ord($header[1]);

        $opcode = $firstByte & 0x0F;
        $masked = ($secondByte & 0x80) !== 0;
        $length = $secondByte & 0x7F;

        if ($length === 126) {
            $extended = self::readExactly($connection, 2);

            if ($extended === null) {
                return null;
            }

            /** @var array{1: int} $unpacked */
            $unpacked = unpack('n', $extended);
            $length   = $unpacked[1];
        } elseif ($length === 127) {
            $extended = self::readExactly($connection, 8);

            if ($extended === null) {
                return null;
            }

            /** @var array{1: int} $unpacked */
            $unpacked = unpack('J', $extended);
            $length   = $unpacked[1];
        }

        // The server never masks; if it ever did, read and ignore the key.
        $maskKey = '';

        if ($masked) {
            $maskKey = (string) self::readExactly($connection, 4);
        }

        $payload = $length === 0 ? '' : self::readExactly($connection, $length);

        if ($payload === null) {
            return null;
        }

        if ($masked && $maskKey !== '') {
            $payload = self::applyMask($payload, $maskKey);
        }

        if ($opcode === self::OPCODE_CLOSE) {
            return null;
        }

        return [
            'data'   => $payload,
            'binary' => $opcode === self::OPCODE_BINARY,
        ];
    }

    /**
     * Performs the upgrade, sends one message and reads one message back over a fresh
     * connection. Returns the response payload, or null on close/EOF.
     */
    public function roundtrip(string $data, bool $binary = false): ?string
    {
        $connection = $this->connect();

        self::sendMessage($connection, $data, $binary);

        $message = self::receiveMessage($connection);

        fclose($connection);

        return $message['data'] ?? null;
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

    /**
     * Sends the upgrade request and validates the 101 response (including the computed
     * Sec-WebSocket-Accept).
     *
     * @param resource $connection
     */
    private function handshake(mixed $connection, string $path): void
    {
        $key = base64_encode(random_bytes(16));

        $request = "GET $path HTTP/1.1\r\n"
            . 'Host: ' . self::HOST . ':' . $this->port . "\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . $key . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n";

        fwrite($connection, $request);

        $response = self::readUntil($connection, "\r\n\r\n");

        if ($response === null || !str_contains($response, ' 101 ')) {
            throw new RuntimeException('WebSocket handshake failed: ' . (string) $response);
        }

        $expectedAccept = base64_encode(sha1($key . self::ACCEPT_GUID, true));

        // The header name is matched case-insensitively (Go canonicalizes it to
        // "Sec-Websocket-Accept"); the base64 accept value itself is exact.
        if (!str_contains(strtolower($response), strtolower('Sec-WebSocket-Accept: ') . strtolower($expectedAccept))) {
            throw new RuntimeException('WebSocket handshake returned an invalid accept key.');
        }
    }

    /**
     * Encodes one masked client frame (FIN set, single frame) for the given opcode.
     */
    private static function encodeClientFrame(int $opcode, string $data): string
    {
        $frame  = chr(0x80 | $opcode);
        $length = strlen($data);

        if ($length < 126) {
            $frame .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $frame .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $length);
        }

        $maskKey = random_bytes(4);

        $frame .= $maskKey . self::applyMask($data, $maskKey);

        return $frame;
    }

    /**
     * XORs the payload with the 4-byte masking key (its own inverse, used both ways).
     */
    private static function applyMask(string $data, string $maskKey): string
    {
        $masked = '';

        for ($index = 0, $length = strlen($data); $index < $length; $index++) {
            $masked .= $data[$index] ^ $maskKey[$index % 4];
        }

        return $masked;
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

    /**
     * Reads until the marker is seen (used to read HTTP handshake headers), or null on
     * EOF/timeout.
     *
     * @param resource $connection
     */
    private static function readUntil(mixed $connection, string $marker): ?string
    {
        $buffer = '';

        while (!str_contains($buffer, $marker)) {
            $chunk = fread($connection, 1);

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
