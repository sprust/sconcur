<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use PHPUnit\Framework\TestCase;
use SConcur\Worker\Heartbeat;

/**
 * The worker's half of the liveness channel: what reaches the master's end of the pipe,
 * how often, and what happens when the master opened no pipe at all.
 */
class HeartbeatTest extends TestCase
{
    /** @var resource|null the master's end of a socket pair standing in for the pipe */
    protected mixed $readEnd = null;

    /** @var resource|null the worker's end */
    protected mixed $writeEnd = null;

    protected function setUp(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        self::assertIsArray($pair);

        [$this->readEnd, $this->writeEnd] = $pair;

        stream_set_blocking($this->readEnd, false);
    }

    protected function tearDown(): void
    {
        foreach ([$this->readEnd, $this->writeEnd] as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        putenv(Heartbeat::FD_ENVIRONMENT_NAME);
    }

    public function testTouchPutsAMarkOnTheWire(): void
    {
        $heartbeat = new Heartbeat(stream: $this->writeEnd);

        $heartbeat->touch();

        self::assertSame("\x01", $this->readAvailable(), 'the master should see the worker mark itself alive');
    }

    public function testASecondTouchWithinTheIntervalSendsNothing(): void
    {
        $heartbeat = new Heartbeat(stream: $this->writeEnd);

        $heartbeat->touch();
        $heartbeat->touch();

        self::assertSame("\x01", $this->readAvailable(), 'the throttle should collapse the second call');
    }

    public function testWithoutTheEnvironmentDescriptorThereIsNoHeartbeat(): void
    {
        putenv(Heartbeat::FD_ENVIRONMENT_NAME);

        self::assertNull(Heartbeat::fromEnvironment());
    }

    public function testADescriptorThatIsNotOpenYieldsNoHeartbeat(): void
    {
        // A worker under a master that opened no pipe, or a grandchild that did not
        // inherit it: the variable is set, the descriptor is not there.
        putenv(Heartbeat::FD_ENVIRONMENT_NAME . '=61');

        self::assertNull(Heartbeat::fromEnvironment());
    }

    protected function readAvailable(): string
    {
        $chunk = fread($this->readEnd, 8192);

        return is_string($chunk) ? $chunk : '';
    }
}
