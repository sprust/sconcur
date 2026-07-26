<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Connection;

use SConcur\Features\Sleeper\Payloads\SleeperPayload;
use SConcur\Tests\Feature\BaseTestCase;

class WaitAnyBatchTest extends BaseTestCase
{
    public function testDrainsEveryReadyResultInOneCall(): void
    {
        $flowKeys = [];

        // Five near-instant tasks: after the pause below all five results sit in
        // the buffered results channel, so one batch call must drain them all.
        foreach (range(1, 5) as $index) {
            $flowKey    = 'batch-' . $index;
            $flowKeys[] = $flowKey;

            $this->extension->push(
                flowKey: $flowKey,
                payload: new SleeperPayload(microseconds: 1),
            );
        }

        usleep(100_000);

        $results = $this->extension->waitAnyBatch(64);

        self::assertCount(5, $results, 'every already-ready result must arrive in the single batch');

        $resultFlowKeys = array_map(static fn($result): string => $result->flowKey, $results);

        sort($resultFlowKeys);

        self::assertSame($flowKeys, $resultFlowKeys);

        foreach ($flowKeys as $flowKey) {
            $this->extension->stopFlow($flowKey);
        }

        $this->assertNoTasksCount();
    }

    public function testMaxResultsCapsTheBatch(): void
    {
        $flowKeys = [];

        foreach (range(1, 4) as $index) {
            $flowKey    = 'batch-cap-' . $index;
            $flowKeys[] = $flowKey;

            $this->extension->push(
                flowKey: $flowKey,
                payload: new SleeperPayload(microseconds: 1),
            );
        }

        usleep(100_000);

        $firstBatch  = $this->extension->waitAnyBatch(3);
        $secondBatch = $this->extension->waitAnyBatch(3);

        self::assertCount(3, $firstBatch, 'the batch must stop at maxResults');
        self::assertCount(1, $secondBatch, 'the leftover result must arrive with the next call');

        foreach ($flowKeys as $flowKey) {
            $this->extension->stopFlow($flowKey);
        }

        $this->assertNoTasksCount();
    }

    public function testTimeoutBatchReturnsNullWhenNothingIsReady(): void
    {
        $start = microtime(true);

        $results = $this->extension->waitAnyTimeoutBatch(
            timeoutMs: 50,
            maxResults: 64,
        );

        $elapsed = microtime(true) - $start;

        self::assertNull($results, 'an idle extension must time out, not block or return results');
        self::assertGreaterThanOrEqual(0.045, $elapsed);
        self::assertLessThan(1.0, $elapsed);
    }
}
