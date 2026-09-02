<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Connection;

use ReflectionMethod;
use SConcur\Connection\Extension;
use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\UnexpectedResponseFormatException;
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

        // microseconds: 0 is rejected by the Go-side sleeper with an error result.
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
     * A frame whose declared field lengths do not fit its boundary must fail
     * loudly instead of silently slicing the neighbour frames' bytes into the
     * payload.
     */
    public function testFrameLengthsPastTheFrameBoundaryFailLoudly(): void
    {
        $method = 'sl';

        $lyingFrame = pack('CCNnnJ', 0, strlen($method), 0, 999, 4, 0) . $method . 'flow' . 'task';
        $batch      = pack('n', 1) . pack('N', strlen($lyingFrame)) . $lyingFrame;

        $this->expectException(UnexpectedResponseFormatException::class);

        self::parseWaitBatchResponse(
            response: $batch,
            errorContext: 'waitAnyBatch',
            start: microtime(true),
        );
    }

    public function testFrameLengthPastTheResponseFailsLoudly(): void
    {
        $frame = self::buildResultFrame(taskKey: 'task');
        $batch = pack('n', 1) . pack('N', strlen($frame) + 50) . $frame;

        $this->expectException(UnexpectedResponseFormatException::class);

        self::parseWaitBatchResponse(
            response: $batch,
            errorContext: 'waitAnyBatch',
            start: microtime(true),
        );
    }

    public function testTrailingBatchBytesFailLoudly(): void
    {
        $frame = self::buildResultFrame(taskKey: 'task');
        $batch = pack('n', 1) . pack('N', strlen($frame)) . $frame . 'junk';

        $this->expectException(UnexpectedResponseFormatException::class);

        self::parseWaitBatchResponse(
            response: $batch,
            errorContext: 'waitAnyBatch',
            start: microtime(true),
        );
    }

    /**
     * The tail results of a batch were already ready when the crossing returned:
     * only the first frame's totalExecutionMs may carry the blocking wait, the
     * rest must not inherit it (they waited for nothing). The multiframe here is
     * hand-built, which also pins the wire format from the PHP side.
     */
    public function testBatchTailResultsDoNotInheritTheFirstResultWait(): void
    {
        $batch = pack('n', 2);

        foreach (['first-task', 'second-task'] as $taskKey) {
            $frame = self::buildResultFrame(taskKey: $taskKey);

            $batch .= pack('N', strlen($frame)) . $frame;
        }

        // Pretend the crossing blocked ten seconds for the first result.
        $results = self::parseWaitBatchResponse(
            response: $batch,
            errorContext: 'waitAnyBatch',
            start: microtime(true) - 10.0,
        );

        self::assertCount(2, $results);
        self::assertSame('first-task', $results[0]->key);
        self::assertSame('second-task', $results[1]->key);

        self::assertGreaterThanOrEqual(9_000, $results[0]->totalExecutionMs);
        self::assertLessThan(1_000, $results[1]->totalExecutionMs);
    }

    public function testBatchErrorResponseCarriesTheCallContext(): void
    {
        $this->expectException(TaskErrorException::class);
        $this->expectExceptionMessage('waitAnyTimeoutBatch: error: boom');

        self::parseWaitBatchResponse(
            response: 'error: boom',
            errorContext: 'waitAnyTimeoutBatch',
            start: microtime(true),
        );
    }

    /**
     * Builds one result frame in the documented layout (flags + methodLen +
     * execMs + flowKeyLen + taskKeyLen + ownerFiberId(uint64), then method,
     * flowKey, taskKey, payload) — must stay in sync with buildResultFrame in
     * ext-go-legacy/main.go.
     */
    protected static function buildResultFrame(string $taskKey): string
    {
        $method  = 'sl';
        $flowKey = 'frame-flow';
        $payload = 'payload-' . $taskKey;

        $header = pack(
            'CCNnnJ',
            0,
            strlen($method),
            0,
            strlen($flowKey),
            strlen($taskKey),
            0,
        );

        return $header . $method . $flowKey . $taskKey . $payload;
    }

    /**
     * @return list<TaskResultDto>
     */
    protected static function parseWaitBatchResponse(string $response, string $errorContext, float $start): array
    {
        $parseMethod = new ReflectionMethod(Extension::class, 'parseWaitBatchResponse');

        return $parseMethod->invoke(null, $response, $errorContext, $start);
    }
}
