<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\SocketServer;

/**
 * `handlerTimeoutMs` on a socket server: a connection handler that runs too long is unwound
 * where it stands instead of holding its coroutine for the life of the process.
 *
 * There is no Go-side timer here answering for it, as there is for HTTP — the deadline is
 * the whole mechanism, and what the client sees is the connection ending without the reply
 * the handler never got to write.
 */
class SocketServerHandlerTimeoutTest extends BaseSocketServerTestCase
{
    public function testASlowHandlerIsCutAndItsConnectionEnds(): void
    {
        $startedAt = microtime(true);

        // The handler would sleep for ten seconds before writing "slept".
        $reply = $this->roundtrip('msleep:10000');

        $elapsed = microtime(true) - $startedAt;

        self::assertNull($reply, 'the handler must not get to answer');
        self::assertLessThan(3.0, $elapsed, sprintf('the deadline took %.3fs to fire', $elapsed));
    }

    public function testAHandlerWithinTheDeadlineAnswersAsBefore(): void
    {
        self::assertSame('slept', $this->roundtrip('msleep:50'));
    }

    public function testTheServerKeepsServingAfterCuttingAHandler(): void
    {
        $this->roundtrip('msleep:10000');

        self::assertSame('HELLO', $this->roundtrip('upper:hello'));
    }

    /**
     * @return array<string, int>
     */
    protected static function serverOptions(): array
    {
        return ['handlerTimeoutMs' => 300];
    }
}
