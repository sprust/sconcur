<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Connection;

use SConcur\Dto\TaskResultDto;
use SConcur\Features\MethodEnum;
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

        foreach ($results as $result) {
            self::assertFalse($result->isError, "task {$result->key} must succeed, got: {$result->payload}");
        }

        $resultFlowKeys = array_map(static fn($result): string => $result->flowKey, $results);

        sort($resultFlowKeys);

        self::assertSame($flowKeys, $resultFlowKeys);

        foreach ($flowKeys as $flowKey) {
            $this->extension->stopFlow($flowKey);
        }

        $this->assertNoTasksCount();
    }

    /**
     * The core builds the result object itself, writing the DTO's properties in
     * place: every field must arrive typed as the class declares it, the method
     * as the enum case rather than its wire value.
     */
    public function testResultArrivesAsATypedTaskResultDto(): void
    {
        $this->extension->push(
            flowKey: 'batch-typed',
            payload: new SleeperPayload(microseconds: 1),
        );

        usleep(100_000);

        $results = $this->extension->waitAnyBatch(64);

        self::assertCount(1, $results);

        $result = $results[0];

        self::assertInstanceOf(TaskResultDto::class, $result);
        self::assertSame('batch-typed', $result->flowKey);
        self::assertSame(MethodEnum::Sleep, $result->method);
        self::assertStringStartsWith('batch-typed:', $result->key);
        self::assertFalse($result->isError);
        self::assertSame('', $result->payload);
        self::assertFalse($result->hasNext);
        self::assertGreaterThanOrEqual(0, $result->executionMs);
        self::assertSame(0, $result->ownerFiberId);

        $this->extension->stopFlow('batch-typed');

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

    public function testTimeoutReturnsNullWhenNothingIsReady(): void
    {
        $start = microtime(true);

        $result = $this->extension->waitAnyTimeout(timeoutMs: 50);

        $elapsed = microtime(true) - $start;

        self::assertNull($result, 'an idle extension must time out, not block or return a result');
        self::assertGreaterThanOrEqual(0.045, $elapsed);
        self::assertLessThan(1.0, $elapsed);
    }

    public function testTimeoutBatchDrainsReadyResults(): void
    {
        $flowKeys = [];

        foreach (range(1, 3) as $index) {
            $flowKey    = 'timeout-batch-' . $index;
            $flowKeys[] = $flowKey;

            $this->extension->push(
                flowKey: $flowKey,
                payload: new SleeperPayload(microseconds: 1),
            );
        }

        usleep(100_000);

        $results = $this->extension->waitAnyTimeoutBatch(
            timeoutMs: 1_000,
            maxResults: 64,
        );

        self::assertNotNull($results, 'ready results must arrive through the timeout variant too');
        self::assertCount(3, $results);

        foreach ($results as $result) {
            self::assertFalse($result->isError, "task {$result->key} must succeed, got: {$result->payload}");
        }

        foreach ($flowKeys as $flowKey) {
            $this->extension->stopFlow($flowKey);
        }

        $this->assertNoTasksCount();
    }

    public function testErrorResultTravelsInsideABatch(): void
    {
        $this->extension->push(
            flowKey: 'batch-ok',
            payload: new SleeperPayload(microseconds: 1),
        );

        // microseconds: 0 is rejected by the extension-side sleeper with an error result.
        $this->extension->push(
            flowKey: 'batch-bad',
            payload: new SleeperPayload(microseconds: 0),
        );

        usleep(100_000);

        $results = $this->extension->waitAnyBatch(64);

        self::assertCount(2, $results);

        $resultsByFlow = [];

        foreach ($results as $result) {
            $resultsByFlow[$result->flowKey] = $result;
        }

        self::assertFalse($resultsByFlow['batch-ok']->isError);
        self::assertTrue($resultsByFlow['batch-bad']->isError);
        self::assertStringContainsString(
            'microseconds must be greater than zero',
            $resultsByFlow['batch-bad']->payload,
        );

        $this->extension->stopFlow('batch-ok');
        $this->extension->stopFlow('batch-bad');

        $this->assertNoTasksCount();
    }

    /**
     * Only the first result of a batch waited for anything: the tail was already
     * ready when the crossing returned, so its totalExecutionMs must not inherit
     * the blocking wait of the first. Three equal sleeps finish together, so the
     * batch usually carries all three; whatever tail it does carry is checked.
     */
    public function testBatchTailResultsDoNotInheritTheFirstResultWait(): void
    {
        $flowKeys = [];

        foreach (range(1, 3) as $index) {
            $flowKey    = 'batch-tail-' . $index;
            $flowKeys[] = $flowKey;

            $this->extension->push(
                flowKey: $flowKey,
                payload: new SleeperPayload(microseconds: 150_000),
            );
        }

        $results = $this->extension->waitAnyBatch(64);

        self::assertGreaterThanOrEqual(
            100,
            $results[0]->totalExecutionMs,
            'the first result of the batch is the one the call blocked for',
        );

        foreach (array_slice($results, 1) as $tailResult) {
            self::assertLessThan(
                50,
                $tailResult->totalExecutionMs,
                "tail result {$tailResult->key} was already ready and waited for nothing",
            );
        }

        while (count($results) < 3) {
            $results = [...$results, ...$this->extension->waitAnyBatch(64)];
        }

        foreach ($flowKeys as $flowKey) {
            $this->extension->stopFlow($flowKey);
        }

        $this->assertNoTasksCount();
    }

    /**
     * A result that was already ready when the call was made has nothing to wait
     * for, so its totalExecutionMs says so; the executionMs beside it is the
     * task's own run time inside the core.
     */
    public function testAReadyResultCarriesNoWait(): void
    {
        $this->extension->push(
            flowKey: 'batch-ready',
            payload: new SleeperPayload(microseconds: 20_000),
        );

        usleep(100_000);

        $result = $this->extension->waitAny();

        self::assertLessThan(50, $result->totalExecutionMs);
        self::assertGreaterThanOrEqual(20, $result->executionMs);

        $this->extension->stopFlow('batch-ready');

        $this->assertNoTasksCount();
    }
}
