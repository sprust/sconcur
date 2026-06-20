<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\SocketServer;

use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\SocketServer\TestSocketServer;

/**
 * The server writes one access-log line per connection to stdout when it closes:
 * start time, remote address, the number of messages handled, a status and the
 * connection duration. Manages its own server so it can read the captured stdout.
 */
class SocketServerAccessLogTest extends TestCase
{
    public function testConnectionIsLoggedWithMessageCountAndTiming(): void
    {
        $server = TestSocketServer::start();

        try {
            $connection = $server->connect();

            TestSocketServer::sendFrame($connection, 'ping');
            TestSocketServer::receiveFrame($connection);

            TestSocketServer::sendFrame($connection, 'ping');
            TestSocketServer::receiveFrame($connection);

            // The line is logged when the connection closes; close it and give stdout
            // a moment to flush to the captured file.
            fclose($connection);

            usleep(200_000);

            $output = $server->output();

            // "<date>T<hh:mm:ss>.<microseconds> 127.0.0.1:<port> msgs=2 ok <n>ms"
            self::assertMatchesRegularExpression(
                '#\dT\d{2}:\d{2}:\d{2}\.\d{6} 127\.0\.0\.1:\d+ msgs=2 ok [\d.]+ms#',
                $output,
            );
        } finally {
            $server->stop();
        }
    }
}
