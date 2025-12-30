<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection;

use SConcur\Entities\Context;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;

class MongodbSocketTimeoutTest extends BaseTestCase
{
    public function test(): void
    {
        $context = Context::create(2);

        $collectionName = 'socketTimeout';

        $sconcurCollection = TestMongodbResolver::getSconcurTestCollection($collectionName);

        $sconcurCollection->deleteMany($context, []);

        $sconcurCollection->bulkWrite(
            context: $context,
            operations: [
                [
                    'deleteMany' => [
                        [],
                    ],
                ],
            ]
        );

        $sconcurCollection->insertOne(
            context: $context,
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
                context: $context,
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
