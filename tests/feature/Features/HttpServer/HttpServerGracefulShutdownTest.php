<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

use PHPUnit\Framework\TestCase;

/**
 * Drives a real, separately-spawned server process (so it can be signalled) to
 * prove graceful shutdown: a SIGTERM arriving while a request is in flight must
 * let that request finish, and the process must then exit cleanly on its own.
 *
 * Self-contained (does not use the shared http-server container): it launches its
 * own `php tests/servers/http/http-server.php` on a loopback port.
 */
class HttpServerGracefulShutdownTest extends TestCase
{
    private const string HOST = '127.0.0.1';

    public function testInFlightRequestDrainsOnSigtermAndProcessExits(): void
    {
        $port = $this->freePort();

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $root      = dirname(__DIR__, 4);
        $extension = $root . '/ext/build/sconcur.so';
        $script    = $root . '/tests/servers/http/http-server.php';

        $process = proc_open(
            ['php', '-d', 'extension=' . $extension, $script, self::HOST . ':' . $port],
            $descriptors,
            $pipes,
            $root,
        );

        if (!is_resource($process)) {
            self::markTestSkipped('could not spawn the server process');
        }

        try {
            $pid = (int) proc_get_status($process)['pid'];

            if (!$this->waitUntilReachable($port)) {
                self::fail('spawned server never became reachable');
            }

            // Start a slow request and let it actually reach the handler (it sleeps
            // 500ms), so it is genuinely in flight when the signal lands.
            $multi = curl_multi_init();
            $curl  = curl_init('http://' . self::HOST . ':' . $port . '/sleep');

            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
            ]);

            curl_multi_add_handle($multi, $curl);

            $running   = null;
            $pumpUntil = microtime(true) + 0.2;

            do {
                curl_multi_exec($multi, $running);
                usleep(10_000);
            } while (microtime(true) < $pumpUntil && $running > 0);

            // Request is mid-flight: ask the server to stop.
            posix_kill($pid, SIGTERM);

            // Finish the in-flight request — it must complete, not be dropped.
            do {
                curl_multi_exec($multi, $running);

                if ($running > 0) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($running > 0);

            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $body   = (string) curl_multi_getcontent($curl);

            curl_multi_remove_handle($multi, $curl);
            curl_close($curl);
            curl_multi_close($multi);

            self::assertSame(200, $status, 'the in-flight request must be drained, not dropped');
            self::assertSame('slept', $body);

            // After draining, the server must shut itself down (it is not killed).
            $exitCode = $this->waitForExit($process);

            self::assertNotNull($exitCode, 'server did not exit after draining the in-flight request');
            self::assertSame(0, $exitCode, 'server should exit cleanly after a graceful shutdown');
        } finally {
            $status = proc_get_status($process);

            if ($status['running']) {
                proc_terminate($process, SIGKILL);
            }

            proc_close($process);
        }
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://' . self::HOST . ':0', $errno, $errstr);

        if ($socket === false) {
            self::markTestSkipped("could not allocate a port: $errstr");
        }

        $name = (string) stream_socket_get_name($socket, false);

        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private function waitUntilReachable(int $port): bool
    {
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $connection = @fsockopen(self::HOST, $port, $errno, $errstr, 0.2);

            if (is_resource($connection)) {
                fclose($connection);

                return true;
            }

            usleep(50_000);
        }

        return false;
    }

    /**
     * @param resource $process
     */
    private function waitForExit($process): ?int
    {
        $deadline = microtime(true) + 3.0;

        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);

            if (!$status['running']) {
                return $status['exitcode'];
            }

            usleep(50_000);
        }

        return null;
    }
}
