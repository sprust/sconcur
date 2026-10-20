<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use PHPUnit\Framework\TestCase;
use SConcur\Worker\Heartbeat;

/**
 * The worker's half of the channel to its master: what reaches the master's end of the
 * pipe, how often, and what happens when the master opened no pipe at all.
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

        // What this process, which no master started, would have found on its own.
        Heartbeat::adopt(null);
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
        // A worker under a master that opened no pipe: the variable is set, the descriptor
        // is not there. The number is past any descriptor limit a process is given, so the
        // test does not depend on which descriptors this run happens to hold open.
        putenv(Heartbeat::FD_ENVIRONMENT_NAME . '=1000000');

        self::assertNull(Heartbeat::fromEnvironment());
    }

    public function testANonNumericDescriptorYieldsNoHeartbeat(): void
    {
        putenv(Heartbeat::FD_ENVIRONMENT_NAME . '=three');

        self::assertNull(Heartbeat::fromEnvironment());
    }

    /**
     * What a forked worker is handed is what is written to, whatever the process it was
     * forked from had found before: its scheduler asks at every mark rather than once.
     */
    public function testTheHeartbeatAForkHandsOverIsTheOneWritten(): void
    {
        Heartbeat::adopt(null);

        self::assertNull(Heartbeat::current());

        $adopted = Heartbeat::adopt($this->writeEnd);

        self::assertNotNull($adopted);
        self::assertSame($adopted, Heartbeat::current());

        $adopted->touch();

        self::assertSame("\x01", $this->readAvailable());
    }

    public function testAForkHandedNoneHasNoHeartbeat(): void
    {
        Heartbeat::adopt($this->writeEnd);
        Heartbeat::adopt(null);

        self::assertNull(Heartbeat::current());
    }

    public function testTheThrottleExpires(): void
    {
        $heartbeat = new Heartbeat(stream: $this->writeEnd);

        $heartbeat->touch();

        usleep(600_000);

        $heartbeat->touch();

        self::assertSame("\x01\x01", $this->readAvailable(), 'a mark is due again once the interval has passed');
    }

    /**
     * Ready is said once: a script that starts a second server after the first ended was
     * up the first time, and a reload waiting on it has nothing more to learn.
     */
    public function testReadyIsSaidOnce(): void
    {
        $heartbeat = new Heartbeat(stream: $this->writeEnd);

        $heartbeat->ready();
        $heartbeat->ready();

        self::assertSame(Heartbeat::MARK_READY, $this->readAvailable());
    }

    public function testRecyclingPutsItsOwnMarkOnTheWire(): void
    {
        $heartbeat = new Heartbeat(stream: $this->writeEnd);

        $heartbeat->touch();
        $heartbeat->recycling();

        self::assertSame(Heartbeat::MARK_ALIVE . Heartbeat::MARK_RECYCLING, $this->readAvailable());
    }

    protected function readAvailable(): string
    {
        $chunk = fread($this->readEnd, 8192);

        return is_string($chunk) ? $chunk : '';
    }
}
