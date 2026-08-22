<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use PHPUnit\Framework\TestCase;
use SConcur\Exceptions\Amqp\InvalidQueueSpecException;
use SConcur\Features\Amqp\Consumer\QueueSpecParser;
use SConcur\Features\Amqp\ConnectionOptions;

/**
 * The queue list a consumer worker is launched with. It arrives as one argv flag, so a
 * mistake in it is a mistake in a config file — it has to be reported at startup, in a
 * sentence, rather than as a broker error minutes into a run.
 */
class QueueSpecParserTest extends TestCase
{
    public function testAQueueListIsReadWithItsWeights(): void
    {
        $specs = QueueSpecParser::parse(
            '[{"name":"orders","coroutineCount":8},{"name":"invoices","coroutineCount":2,"prefetchCount":5}]',
        );

        self::assertCount(2, $specs);

        self::assertSame('orders', $specs[0]->name);
        self::assertSame(8, $specs[0]->coroutineCount);
        self::assertNull($specs[0]->prefetchCount);

        self::assertSame('invoices', $specs[1]->name);
        self::assertSame(2, $specs[1]->coroutineCount);
        self::assertSame(5, $specs[1]->prefetchCount);
    }

    public function testAQueueWithNoWeightGetsOneCoroutine(): void
    {
        $specs = QueueSpecParser::parse('[{"name":"emails"}]');

        self::assertSame(1, $specs[0]->coroutineCount);
    }

    /**
     * The reason the list is objects and not a delimited string: AMQP allows a colon in
     * a queue name, and names shaped like this are ordinary.
     */
    public function testAColonInAQueueNameIsJustPartOfTheName(): void
    {
        $specs = QueueSpecParser::parse('[{"name":"tenant:1:orders","coroutineCount":2}]');

        self::assertSame('tenant:1:orders', $specs[0]->name);
        self::assertSame(2, $specs[0]->coroutineCount);
    }

    public function testTheChannelCountIsTheSumOfTheWeights(): void
    {
        $specs = QueueSpecParser::parse('[{"name":"a","coroutineCount":3},{"name":"b","coroutineCount":4}]');

        self::assertSame(7, QueueSpecParser::channelCount($specs));
    }

    public function testMalformedJsonIsRefused(): void
    {
        $this->expectException(InvalidQueueSpecException::class);

        QueueSpecParser::parse('[{"name":"orders"');
    }

    public function testAnEmptyListIsRefused(): void
    {
        $this->expectException(InvalidQueueSpecException::class);
        $this->expectExceptionMessage('at least one queue is required');

        QueueSpecParser::parse('[]');
    }

    public function testSomethingOtherThanAListIsRefused(): void
    {
        $this->expectException(InvalidQueueSpecException::class);
        $this->expectExceptionMessage('expected a JSON list of objects');

        QueueSpecParser::parse('{"name":"orders"}');
    }

    public function testAQueueWithNoNameIsRefused(): void
    {
        $this->expectException(InvalidQueueSpecException::class);
        $this->expectExceptionMessage('"name" is required');

        QueueSpecParser::parse('[{"coroutineCount":2}]');
    }

    public function testTheSameQueueTwiceIsRefused(): void
    {
        $this->expectException(InvalidQueueSpecException::class);
        $this->expectExceptionMessage('listed twice');

        QueueSpecParser::parse('[{"name":"orders"},{"name":"orders","coroutineCount":2}]');
    }

    public function testAWeightBelowOneIsRefused(): void
    {
        $this->expectException(InvalidQueueSpecException::class);
        $this->expectExceptionMessage('"coroutineCount" must be at least 1');

        QueueSpecParser::parse('[{"name":"orders","coroutineCount":0}]');
    }

    public function testAnUnknownKeyIsRefused(): void
    {
        // A typo in a config would otherwise be a setting that silently does nothing.
        $this->expectException(InvalidQueueSpecException::class);
        $this->expectExceptionMessage('unknown key(s) coroutinesNum');

        QueueSpecParser::parse('[{"name":"orders","coroutinesNum":4}]');
    }

    public function testMoreCoroutinesThanOneConnectionHasChannelsIsRefused(): void
    {
        // A coroutine is a channel, and the 257th on a connection is refused by the
        // broker. Diagnosed here rather than as "504 channel id space exhausted" on
        // whichever coroutine happened to start last.
        $this->expectException(InvalidQueueSpecException::class);
        $this->expectExceptionMessage('one connection carries');

        QueueSpecParser::parse('[{"name":"orders","coroutineCount":' . ConnectionOptions::MAX_CHANNELS . '}]');
    }

    public function testTheChannelBudgetIsSpentToTheLastChannel(): void
    {
        $specs = QueueSpecParser::parse(
            '[{"name":"orders","coroutineCount":' . (ConnectionOptions::MAX_CHANNELS - 1) . '}]',
        );

        self::assertSame(ConnectionOptions::MAX_CHANNELS - 1, QueueSpecParser::channelCount($specs));
    }
}
