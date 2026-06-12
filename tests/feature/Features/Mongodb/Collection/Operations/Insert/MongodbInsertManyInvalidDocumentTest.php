<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Insert;

use SConcur\Exceptions\TaskErrorException;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;
use SConcur\WaitGroup;

/**
 * A scalar element in insertMany() used to panic inside the Go extension,
 * aborting the whole PHP process. The panic must surface as a task error,
 * and the extension must stay usable afterwards.
 */
class MongodbInsertManyInvalidDocumentTest extends BaseTestCase
{
    public function test(): void
    {
        $collection = TestMongodbResolver::getSconcurTestCollection('insertManyInvalidDocument');

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: static fn() => $collection->insertMany(
                // @phpstan-ignore argument.type (invalid documents shape on purpose)
                documents: [
                    ['title' => 'valid document'],
                    'scalar instead of document',
                ]
            )
        );

        $exception = null;

        try {
            $waitGroup->waitResults();
        } catch (TaskErrorException $caughtException) {
            $exception = $caughtException;
        }

        self::assertNotNull($exception);

        $insertOneResult = $collection->insertOne(
            document: ['title' => 'extension is still alive']
        );

        self::assertNotNull($insertOneResult->insertedId);
    }
}
