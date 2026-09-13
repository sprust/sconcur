<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Redis;

use SConcur\Exceptions\Redis\NestedPipelineExecutionException;
use SConcur\Features\Redis\Connection;
use SConcur\Exceptions\Redis\RedisCommandException;
use SConcur\Exceptions\Redis\RedisException;
use SConcur\Features\Redis\Dto\ErrorReply;
use SConcur\Features\Redis\Pipeline;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestRedisResolver;

class RedisPipelineTest extends BaseTestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        TestRedisResolver::flush();

        $this->connection = TestRedisResolver::getConnection();
    }

    public function testRepliesComeBackInOrder(): void
    {
        $replies = $this->connection->pipeline()
            ->command('SET', ['a', '1'])
            ->command('INCR', ['a'])
            ->command('GET', ['a'])
            ->execute();

        self::assertSame(['OK', 2, '2'], $replies);
    }

    public function testAFailedCommandDoesNotFailTheBatch(): void
    {
        $this->connection->set('string', 'value');

        $replies = $this->connection->pipeline()
            ->command('SET', ['ok', '1'])
            ->command('LPUSH', ['string', 'x'])
            ->command('GET', ['ok'])
            ->execute();

        self::assertSame('OK', $replies[0]);
        self::assertInstanceOf(ErrorReply::class, $replies[1]);
        self::assertSame('WRONGTYPE', $replies[1]->code);
        self::assertSame('1', $replies[2]);
    }

    public function testAPipelineIsEmptiedByExecute(): void
    {
        $pipeline = $this->connection->pipeline();

        $pipeline->command('SET', ['a', '1']);

        self::assertSame(1, $pipeline->count());

        $pipeline->execute();

        self::assertSame(0, $pipeline->count());
    }

    public function testATransactionRunsAsOneUnit(): void
    {
        $replies = $this->connection->transaction(
            static function (Pipeline $transaction): void {
                $transaction->command('SET', ['tx', '1']);
                $transaction->command('INCR', ['tx']);
            },
        );

        self::assertSame(['OK', 2], $replies);
        self::assertSame('2', $this->connection->get('tx'));
    }

    public function testATransactionIsAbandonedWholeWhenACommandIsRefusedAtQueueTime(): void
    {
        // The difference a plain batch cannot show. A command the server refuses
        // while queueing aborts the whole transaction and none of it runs; the same
        // commands in a non-atomic pipeline run around the bad one.
        $this->connection->set('kept', 'original');

        try {
            $this->connection->transaction(
                static function (Pipeline $transaction): void {
                    $transaction->command('SET', ['kept', 'changed']);
                    // Wrong arity: refused when it is queued, not when it runs.
                    $transaction->command('GET', ['a', 'b', 'c']);
                },
            );

            self::fail('a transaction with a command refused at queue time should fail');
        } catch (RedisCommandException $exception) {
            self::assertStringContainsString('EXECABORT', $exception->getMessage());
        }

        self::assertSame('original', $this->connection->get('kept'), 'the transaction was not abandoned');

        // The same pair without atomicity: the good command did run.
        $this->connection->pipeline()
            ->command('SET', ['kept', 'changed'])
            ->command('GET', ['a', 'b', 'c'])
            ->execute();

        self::assertSame('changed', $this->connection->get('kept'));
    }

    public function testExecuteInsideATransactionIsRefused(): void
    {
        // It would send those commands on their own, outside the MULTI/EXEC, and
        // drop their replies — which is what it used to do, silently.
        $this->expectException(NestedPipelineExecutionException::class);

        $this->connection->transaction(
            static function (Pipeline $transaction): void {
                $transaction->command('PING');
                $transaction->execute();
            },
        );
    }

    public function testAFailedSendLeavesThePipelineIntact(): void
    {
        // A send that never reached the server must leave the batch where it was:
        // emptying it first turned a retry into "a pipeline needs at least one
        // command", which reads as a usage mistake rather than a transport failure.
        $unreachable = new Connection(dsn: 'redis://127.0.0.1:6390/0', timeoutMs: 1000);

        $pipeline = $unreachable->pipeline();

        $pipeline->command('SET', ['a', '1']);
        $pipeline->command('SET', ['b', '2']);

        try {
            $pipeline->execute();

            self::fail('a pipeline to an unreachable server should fail');
        } catch (RedisException) {
            //
        }

        self::assertSame(2, $pipeline->count());
    }

    public function testAStoredValueCannotForgeAFailure(): void
    {
        // Failures travel beside the replies now. They used to be a marked map in
        // the reply's own place, so a hash carrying that field came back as an
        // error object chosen by whoever wrote it.
        $this->connection->hSet('forged', [
            '__sconcur_redis_error' => '1',
            'code'                  => 'BOOM',
            'message'               => 'pwn',
        ]);

        $replies = $this->connection->pipeline()
            ->command('HGETALL', ['forged'])
            ->execute();

        self::assertIsArray($replies[0]);
        self::assertNotInstanceOf(ErrorReply::class, $replies[0]);
    }

    public function testATransactionReportsAFailedCommandInItsPlace(): void
    {
        $this->connection->set('string', 'value');

        $replies = $this->connection->transaction(
            static function (Pipeline $transaction): void {
                $transaction->command('SET', ['ok', '1']);
                $transaction->command('LPUSH', ['string', 'x']);
            },
        );

        self::assertSame('OK', $replies[0]);
        self::assertInstanceOf(ErrorReply::class, $replies[1]);
    }

    public function testABlockingCommandIsRefusedInsideAPipeline(): void
    {
        $this->expectExceptionMessageMatches('/cannot run inside a pipeline/');

        $this->connection->pipeline()
            ->command('BLPOP', ['queue', '1'])
            ->execute();
    }
}
