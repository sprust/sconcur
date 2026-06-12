<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection;

use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;
use Throwable;

class MongodbSocketTimeoutTest extends BaseTestCase
{
    public function test(): void
    {
        $collectionName = 'socketTimeout';

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
            socketTimeoutMs: 1
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
}
