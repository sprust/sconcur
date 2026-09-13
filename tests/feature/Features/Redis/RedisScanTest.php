<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Redis;

use SConcur\Features\Redis\Connection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestRedisResolver;

class RedisScanTest extends BaseTestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        TestRedisResolver::flush();

        $this->connection = TestRedisResolver::getConnection();
    }

    public function testScanWalksTheWholeKeyspace(): void
    {
        $expected = [];

        $pipeline = $this->connection->pipeline();

        for ($index = 0; $index < 300; ++$index) {
            $key            = "scan:key:$index";
            $expected[$key] = true;

            $pipeline->command('SET', [$key, '1']);
        }

        $pipeline->execute();

        $seen = [];

        foreach ($this->connection->scan(match: 'scan:key:*', count: 25, batchSize: 10) as $key) {
            // SCAN may hand the same key over twice; the guarantee is that every key that
            // was there the whole time comes at least once.
            $seen[$key] = true;
        }

        self::assertSame(count($expected), count($seen));
        self::assertSame([], array_diff_key($expected, $seen));
    }

    public function testScanIsFilteredByMatch(): void
    {
        $this->connection->mSet([
            'user:1'  => 'a',
            'user:2'  => 'b',
            'order:1' => 'c',
        ]);

        $seen = [];

        foreach ($this->connection->scan(match: 'user:*') as $key) {
            $seen[$key] = true;
        }

        $keys = array_keys($seen);

        // SCAN promises no order, so the assertion cannot depend on one.
        sort($keys);

        self::assertSame(['user:1', 'user:2'], $keys);
    }

    public function testHashScanYieldsFieldsAndValues(): void
    {
        $fields = [];

        // Past hash-max-listpack-entries, which defaults to 128: under it a hash is a
        // flat list and HSCAN happens to answer in insertion order, which is not a
        // promise it makes. Over it the answer comes in bucket order, and an assertion
        // that depended on the coincidence would fail here rather than in production.
        for ($index = 0; $index < 200; ++$index) {
            $fields["field:$index"] = "value:$index";
        }

        $this->connection->hSet('hash', $fields);

        $seen = [];

        foreach ($this->connection->hScan('hash', batchSize: 7) as $field => $value) {
            $seen[$field] = $value;
        }

        ksort($fields);
        ksort($seen);

        self::assertSame($fields, $seen);
    }

    public function testSortedSetScanYieldsMembersAndScores(): void
    {
        $this->connection->zAdd('zset', ['a' => 1.0, 'b' => 2.5]);

        $seen = [];

        foreach ($this->connection->zScan('zset') as $member => $score) {
            $seen[$member] = (float) $score;
        }

        // ZSCAN promises no order either.
        ksort($seen);

        self::assertSame(['a' => 1.0, 'b' => 2.5], $seen);
    }

    public function testIteratingWithoutRewindingDoesNotSpin(): void
    {
        $this->connection->mSet(['a' => '1', 'b' => '2']);

        $result = $this->connection->scan();

        // PHP's iterator contract says nothing about this order, but "undefined"
        // must not mean "spins for ever with no I/O in it": that is a worker nothing
        // can interrupt — no deadline, no coroutine switch, no flow stop.
        self::assertFalse($result->valid());

        $result->next();

        self::assertFalse($result->valid());

        // And it still works when used properly.
        $seen = 0;

        foreach ($result as $ignored) {
            ++$seen;
        }

        self::assertSame(2, $seen);
    }

    public function testAnAbandonedScanLeavesNothingBehind(): void
    {
        $pipeline = $this->connection->pipeline();

        for ($index = 0; $index < 500; ++$index) {
            $pipeline->command('SET', ["abandon:$index", '1']);
        }

        $pipeline->execute();

        foreach ($this->connection->scan(match: 'abandon:*', count: 10, batchSize: 5) as $key) {
            self::assertNotSame('', $key);

            // Walk away with the cursor open. The flow ending has to close it and
            // give its pooled connection back.
            break;
        }

        // The cursor rode a pooled connection, so counting sockets proves nothing.
        // What an unreleased one would cost is that connection: a full scan
        // afterwards has to walk the whole keyspace on the pool that is left.
        $seen = 0;

        foreach ($this->connection->scan(match: 'abandon:*', count: 100) as $ignored) {
            ++$seen;
        }

        self::assertSame(500, $seen, 'the pool did not recover from the abandoned cursor');
    }
}
