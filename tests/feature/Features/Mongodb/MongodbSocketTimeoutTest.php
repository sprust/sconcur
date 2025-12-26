<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb;

use SConcur\Entities\Context;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Features\Mongodb\Connection\Client;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbUriResolver;

class MongodbSocketTimeoutTest extends BaseTestCase
{
    public function test(): void
    {
        $context = Context::create(2);

        $uri        = TestMongodbUriResolver::get();
        $database   = 'u-test';
        $collection = 'socketTimeout';

        $driverCollection = new \MongoDB\Client($uri)
            ->selectDatabase($database)
            ->selectCollection($collection);

        $sconcurCollection = new Client($uri)
            ->selectDatabase($database)
            ->selectCollection($collection);

        $driverCollection->deleteMany([]);

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

        $sconcurCollection = new Client($uri, socketTimeoutMs: 1)
            ->selectDatabase($database)
            ->selectCollection($collection);

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
