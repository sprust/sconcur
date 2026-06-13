<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection;

use SConcur\Features\Mongodb\Connection\Client;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;
use Throwable;

class MongodbTimeoutTest extends BaseTestCase
{
    public function testOperationExceedingTimeoutFails(): void
    {
        $collectionName = 'timeout';

        $sconcurCollection = TestMongodbResolver::getSconcurTestCollection($collectionName);

        $sconcurCollection->deleteMany([]);

        $sconcurCollection->bulkWrite(
            operations: [
                [
                    'deleteMany' => [
                        [],
                    ],
                ],
            ]
        );

        $sconcurCollection->insertOne(
            document: [
                uniqid() => true,
            ]
        );

        $sconcurCollection = TestMongodbResolver::getSconcurTestCollection(
            collectionName: $collectionName,
            timeoutMs: 1
        );

        $exception = null;

        try {
            $iterator = $sconcurCollection->aggregate(
                pipeline: [
                    [
                        '$limit' => 1,
                    ],
                    [
                        '$addFields' => [
                            'sleep_result' => [
                                '$function' => [
                                    'body' => 'function() { sleep(1000); return "ok"; }',
                                    'args' => [],
                                    'lang' => 'js',
                                ],
                            ],
                        ],
                    ],
                    [
                        '$project' => [
                            'sleep_result' => 1,
                        ],
                    ],
                ]
            );

            $iterator->rewind();
        } catch (Throwable $exception) {
            //
        }

        self::assertFalse(
            is_null($exception)
        );

        $exceptionMessage = $exception->getMessage();

        self::assertStringContainsString(
            'mongodb:',
            $exceptionMessage
        );

        // The driver enforces the timeout via CSOT, so a too-small timeout surfaces as a
        // context deadline / "timed out" rather than a socket-level "i/o timeout".
        self::assertMatchesRegularExpression(
            '/deadline exceeded|timed out|timeout/i',
            $exceptionMessage
        );
    }

    public function testUnreachableServerFailsFastViaServerSelectionTimeout(): void
    {
        // RFC 5737 TEST-NET-1: guaranteed not routed, so connection attempts hang rather
        // than being refused — exactly the "MongoDB unavailable" case that used to wedge
        // the worker for the full 30s driver default.
        $serverSelectionTimeoutMs = 2000;

        $sconcurCollection = new Client(
            uri: 'mongodb://192.0.2.1:27017/?directConnection=true',
            serverSelectionTimeoutMs: $serverSelectionTimeoutMs,
        )
            ->selectDatabase('sconcur_test')
            ->selectCollection('timeout');

        $startedAt = microtime(true);

        $exception = null;

        try {
            $sconcurCollection->countDocuments([]);
        } catch (Throwable $exception) {
            //
        }

        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        self::assertFalse(
            is_null($exception),
            'An unreachable server must surface an error, not hang.'
        );

        // The operation must fail via serverSelectionTimeout, far below the 30s default —
        // proving the knob bounds the wait. Generous upper bound to stay CI-stable.
        self::assertLessThan(
            10000,
            $elapsedMs,
            'Unreachable server should fail fast via serverSelectionTimeout.'
        );

        $exceptionMessage = $exception->getMessage();

        self::assertStringContainsString(
            'mongodb:',
            $exceptionMessage
        );

        self::assertMatchesRegularExpression(
            '/server selection|deadline exceeded|timed out|timeout/i',
            $exceptionMessage
        );
    }
}
