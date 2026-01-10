<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection;

use SConcur\Exceptions\TaskErrorException;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;

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
        } catch (TaskErrorException $exception) {
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

        self::assertStringEndsWith(
            'i/o timeout',
            $exceptionMessage
        );
    }
}
