<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Redis;

use Redis as NativeRedis;
use SConcur\Features\Redis\Connection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestRedisResolver;

/**
 * The typed facade against the native extension: the replies must be the same values, not
 * merely plausible ones. Written against phpredis rather than against literals so a change
 * in what the server answers shows up here rather than in an application.
 */
class RedisParityTest extends BaseTestCase
{
    protected Connection $connection;

    protected NativeRedis $native;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('redis')) {
            self::markTestSkipped('phpredis is not installed, there is nothing to compare against.');
        }

        TestRedisResolver::flush();

        $this->connection = TestRedisResolver::getConnection();
        $this->native     = TestRedisResolver::getNativeRedis();
    }

    public function testStringCommands(): void
    {
        $this->connection->set('key', 'value');

        self::assertSame($this->native->get('key'), $this->connection->get('key'));
        self::assertSame($this->native->strlen('key'), $this->connection->strLen('key'));

        $this->connection->mSet(['a' => '1', 'b' => '2']);

        self::assertSame(
            ['a' => '1', 'b' => '2', 'missing' => null],
            $this->connection->mGet(['a', 'b', 'missing']),
        );
    }

    public function testKeyCommands(): void
    {
        $this->connection->set('key', 'value');
        $this->connection->expire('key', 100);

        self::assertSame('string', $this->connection->type('key'));
        self::assertSame(1, $this->connection->exists('key'));
        self::assertSame($this->native->ttl('key'), $this->connection->ttl('key'));

        $this->connection->persist('key');

        self::assertNull($this->connection->ttl('key'));
        self::assertFalse($this->connection->ttl('missing'));

        self::assertSame(1, $this->connection->del('key'));
    }

    public function testHashCommands(): void
    {
        $fields = ['one' => '1', 'two' => '2'];

        $this->connection->hSet('hash', $fields);

        self::assertSame($this->native->hGetAll('hash'), $this->connection->hGetAll('hash'));
        self::assertSame($fields, $this->connection->hGetAll('hash'));
        self::assertSame('1', $this->connection->hGet('hash', 'one'));
        self::assertSame(['one' => '1', 'missing' => null], $this->connection->hMGet('hash', ['one', 'missing']));
        self::assertTrue($this->connection->hExists('hash', 'two'));
        self::assertSame(2, $this->connection->hLen('hash'));
    }

    public function testListCommands(): void
    {
        $this->connection->rPush('list', 'a', 'b', 'c');

        self::assertSame($this->native->lRange('list', 0, -1), $this->connection->lRange('list', 0, -1));
        self::assertSame(3, $this->connection->lLen('list'));
        self::assertSame('a', $this->connection->lPop('list'));
        self::assertSame('c', $this->connection->rPop('list'));
    }

    public function testSetCommands(): void
    {
        $this->connection->sAdd('set', 'a', 'b');

        $members = $this->connection->sMembers('set');

        sort($members);

        self::assertSame(['a', 'b'], $members);
        self::assertTrue($this->connection->sIsMember('set', 'a'));
        self::assertSame(2, $this->connection->sCard('set'));
    }

    public function testSortedSetCommands(): void
    {
        $this->connection->zAdd('zset', ['a' => 1.0, 'b' => 2.0]);

        self::assertSame(['a', 'b'], $this->connection->zRange('zset', 0, -1));
        self::assertSame(['a' => 1.0, 'b' => 2.0], $this->connection->zRange('zset', 0, -1, withScores: true));
        self::assertSame(2.0, $this->connection->zScore('zset', 'b'));
        self::assertNull($this->connection->zScore('zset', 'missing'));
        self::assertSame(2, $this->connection->zCard('zset'));
        self::assertSame(['b'], $this->connection->zRangeByScore('zset', '(1', '+inf'));
    }

    public function testScriptCommands(): void
    {
        $script = "return redis.call('SET', KEYS[1], ARGV[1])";

        $sha1 = $this->connection->scriptLoad($script);

        self::assertSame(sha1($script), $sha1);

        $this->connection->evalSha($sha1, $script, keys: ['scripted'], arguments: ['value']);

        self::assertSame('value', $this->connection->get('scripted'));
    }

    public function testEvalShaFallsBackWhenTheScriptIsNotCached(): void
    {
        // A script this run invents, so the server cannot be holding it — rather than
        // SCRIPT FLUSH, which empties the script cache of the whole server. That cache
        // is not per-database, so flushing it here reaches outside the database the
        // tests are kept in and takes the scripts of anything else on the same server
        // with it, this suite's own testScriptCommands included.
        $script = "return redis.call('SET', KEYS[1], ARGV[1]) -- " . uniqid('sconcur', true);

        $this->connection->evalSha(sha1($script), $script, keys: ['fallback'], arguments: ['value']);

        self::assertSame('value', $this->connection->get('fallback'));
    }
}
