<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Redis;

use SConcur\Exceptions\Redis\RedisException;
use SConcur\Features\Redis\Connection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestRedisResolver;
use SConcur\WaitGroup;
use Throwable;

/**
 * The reason blocking commands get a connection of their own.
 *
 * Redis serves one connection strictly in order, so a BLPOP sharing a socket with ordinary
 * commands would hold every one of them up for as long as it waits. These tests measure
 * exactly that.
 */
class RedisBlockingTest extends BaseTestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        TestRedisResolver::flush();

        $this->connection = TestRedisResolver::getConnection();
    }

    public function testABlockingCommandDoesNotHoldUpOtherCommands(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function (): void {
                $this->connection->blPop(['idle:queue'], timeoutSeconds: 1.0);
            },
        );

        $elapsedMs = 0.0;

        $waitGroup->add(
            callback: function () use (&$elapsedMs): void {
                $startTime = microtime(true);

                for ($index = 0; $index < 20; ++$index) {
                    $this->connection->set("fast:$index", '1');
                }

                $elapsedMs = (microtime(true) - $startTime) * 1000;
            },
        );

        $waitGroup->waitAll();

        self::assertTrue(
            $elapsedMs < 500,
            "Twenty ordinary commands took {$elapsedMs}ms while a BLPOP was waiting — they were queued behind it",
        );
    }

    public function testAStreamReadWithBlockDoesNotHoldUpOtherCommands(): void
    {
        // XREAD is not blocking by name — only its BLOCK option says so, and the
        // caller is not asked. A core that trusted the name put this on the shared
        // pool and stalled every command behind it.
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function (): void {
                $this->connection->command(
                    'XREAD',
                    ['BLOCK', 1000, 'STREAMS', 'idle:stream', '$'],
                );
            },
        );

        $elapsedMs = 0.0;

        $waitGroup->add(
            callback: function () use (&$elapsedMs): void {
                $startTime = microtime(true);

                for ($index = 0; $index < 20; ++$index) {
                    $this->connection->set("fast:$index", '1');
                }

                $elapsedMs = (microtime(true) - $startTime) * 1000;
            },
        );

        $waitGroup->waitAll();

        self::assertTrue(
            $elapsedMs < 500,
            "Twenty ordinary commands took {$elapsedMs}ms while an XREAD BLOCK was waiting",
        );
    }

    public function testBlockingCommandsReuseTheirConnections(): void
    {
        // Measured by what the server counts as connections it has ever accepted,
        // not by how many are open right now: opening and closing one per command
        // leaves that number flat too, which is what made the first version of
        // this test unable to fail.
        $before = TestRedisResolver::countAcceptedConnections();

        for ($index = 0; $index < 20; ++$index) {
            $this->connection->blPop(['reused:queue'], timeoutSeconds: 0.05);
        }

        $accepted = TestRedisResolver::countAcceptedConnections() - $before;

        self::assertLessThanOrEqual(
            3,
            $accepted,
            "20 sequential blocking commands made the server accept $accepted connections",
        );
    }

    public function testABlockingCommandCutOffByAStopDoesNotPoisonTheNextOnes(): void
    {
        // The connection a stopped command was using is still owed a reply. Parking
        // it for the next command hands that reply to somebody else, whose own
        // command then times out and is parked in turn — one stop, and every
        // blocking command on the dsn fails for the life of the process.
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function (): void {
                try {
                    // Waits for ever, so the stop is what ends it.
                    $this->connection->blPop(['poison:never'], timeoutSeconds: 0.0);
                } catch (Throwable) {
                    //
                }
            },
        );

        $waitGroup->add(
            callback: function () use ($waitGroup): void {
                $this->connection->ping();

                $waitGroup->stop();
            },
        );

        try {
            $waitGroup->waitAll();
        } catch (Throwable) {
            //
        }

        // Every one of these has to answer on its own merits.
        for ($index = 0; $index < 4; ++$index) {
            $this->connection->rPush("poison:queue:$index", 'value');

            self::assertSame(
                ["poison:queue:$index", 'value'],
                $this->connection->blPop(["poison:queue:$index"], timeoutSeconds: 1.0),
                "blocking command #$index read somebody else's answer",
            );
        }
    }

    public function testTheDedicatedConnectionCeilingIsEnforced(): void
    {
        // 64 per dsn, and the sixty-fifth is refused rather than opened. Nothing
        // below the ceiling can show that the ceiling exists.
        $waitGroup = WaitGroup::create();

        $refused = 0;
        $served  = 0;

        for ($index = 0; $index < 80; ++$index) {
            $waitGroup->add(
                callback: function () use (&$refused, &$served): void {
                    try {
                        $this->connection->blPop(['ceiling:queue'], timeoutSeconds: 1.0);

                        ++$served;
                    } catch (RedisException $exception) {
                        self::assertStringContainsString('blocking commands', $exception->getMessage());

                        ++$refused;
                    }
                },
            );
        }

        $waitGroup->waitAll();

        self::assertSame(80, $served + $refused);
        self::assertGreaterThan(0, $refused, 'the ceiling did not refuse anything');
        self::assertLessThanOrEqual(64, $served);

        // And the dsn works again once they are done.
        $this->connection->rPush('ceiling:after', 'value');

        self::assertSame(
            ['ceiling:after', 'value'],
            $this->connection->blPop(['ceiling:after'], timeoutSeconds: 1.0),
        );
    }

    public function testASlotIsGivenBackWhenTheDialIsCutOffByTheDeadline(): void
    {
        // The slot is counted before the dial, so a deadline that fires while
        // dialling has to give it back. Seventy of these used to kill the dsn with
        // no socket ever opening.
        $unreachable = TestRedisResolver::getUnreachableConnection(timeoutMs: 100);

        $startTime = microtime(true);

        for ($index = 0; $index < 70; ++$index) {
            try {
                $unreachable->blPop(['slot:queue'], timeoutSeconds: 0.01);
            } catch (RedisException $exception) {
                self::assertStringNotContainsString(
                    'blocking commands',
                    $exception->getMessage(),
                    "the ceiling was reached on attempt #$index without a connection ever opening",
                );
            }
        }

        $elapsedMs = (microtime(true) - $startTime) * 1000;

        // Seventy attempts of 110 ms, which is the 10 ms wait plus the 100 ms this
        // connection was given. The deadline used to be floored at a second whatever
        // the caller asked for, and this loop took a minute and a half for want of a
        // number it had already been handed.
        self::assertTrue(
            $elapsedMs < 30_000,
            "70 cut-off dials took {$elapsedMs}ms: the connection's own timeoutMs is not what bounds them",
        );
    }

    public function testTheDeadlineOfABlockingCallIsTheWaitPlusTheConnectionsBudget(): void
    {
        // Neither number is invented here. A connection configured tight stays tight —
        // the floor of one second that used to be applied turned timeoutMs: 100 into
        // 1010 — and one told to work without a deadline gets none for a blocking call
        // either, where that same floor gave it the strictest deadline of all.
        $tight = new Connection(dsn: TestRedisResolver::getDsn(), timeoutMs: 100);

        self::assertSame(110, $tight->blockingDeadlineMs(0.01));
        self::assertSame(5100, $tight->blockingDeadlineMs(5.0));

        $unbounded = new Connection(dsn: TestRedisResolver::getDsn(), timeoutMs: 0);

        self::assertSame(0, $unbounded->blockingDeadlineMs(5.0));

        // Waiting forever stays deadline-free on any connection.
        self::assertSame(0, $tight->blockingDeadlineMs(0.0));
    }

    public function testBlockingPopReturnsTheKeyThatAnswered(): void
    {
        $this->connection->rPush('answered', 'value');

        $popped = $this->connection->blPop(['empty', 'answered'], timeoutSeconds: 1.0);

        self::assertSame(['answered', 'value'], $popped);
    }

    public function testBlockingPopReturnsNullWhenNothingArrives(): void
    {
        $popped = $this->connection->blPop(['nothing'], timeoutSeconds: 0.3);

        self::assertNull($popped);
    }

    public function testBlockingPopSeesAValuePushedWhileItWaits(): void
    {
        $waitGroup = WaitGroup::create();

        $popped = null;

        $waitGroup->add(
            callback: function () use (&$popped): void {
                $popped = $this->connection->blPop(['late'], timeoutSeconds: 2.0);
            },
        );

        $waitGroup->add(
            callback: function (): void {
                $this->connection->command('SET', ['marker', '1']);
                $this->connection->rPush('late', 'arrived');
            },
        );

        $waitGroup->waitAll();

        self::assertSame(['late', 'arrived'], $popped);
    }

    public function testADeadlineShorterThanTheWaitIsRefused(): void
    {
        $connection = TestRedisResolver::getConnection(timeoutMs: 200);

        $this->expectExceptionMessageMatches('/raise timeoutMs/');

        // The raw path, where the caller sets both numbers: the facade raises the deadline
        // itself, which is what makes blPop above work.
        $connection->command('BLPOP', ['queue', 5], blocking: true);
    }

    public function testWaitingForeverNeedsNoDeadline(): void
    {
        $connection = TestRedisResolver::getConnection(timeoutMs: 1000);

        $this->expectExceptionMessageMatches('/wait forever/');

        $connection->command('BLPOP', ['queue', 0], blocking: true);
    }
}
