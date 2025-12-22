<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb;

use SConcur\Entities\Context;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Features\Features;
use SConcur\Features\Mongodb\Parameters\ConnectionParameters;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbUriResolver;

class MongodbSocketTimeoutTest extends BaseTestCase
{
    public function test(): void
    {
        $context = Context::create(2);

        $connectionParameters = new ConnectionParameters(
            uri: TestMongodbUriResolver::get(),
            database: 'u-test',
            collection: 'socketTimeout',
        );

        Features::mongodb($connectionParameters)
            ->bulkWrite(
                context: $context,
                operations: [
                    [
                        'deleteMany' => [
                            [],
                        ],
                    ],
                ]
            );

        Features::mongodb($connectionParameters)
            ->insertOne(
                context: $context,
                document: [
                    uniqid() => true,
                ]
            );

        $connectionParameters = new ConnectionParameters(
            uri: TestMongodbUriResolver::get(),
            database: 'u-test',
            collection: 'socketTimeout',
            socketTimeoutMs: 1
        );

        $feature = Features::mongodb($connectionParameters);

        $exception = null;

        try {
            $feature->aggregate(
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
