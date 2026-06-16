<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Insert;

use SConcur\Exceptions\CallbackExecutionException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;
use SConcur\WaitGroup;

/**
 * A scalar element in insertMany() used to panic inside the Go extension,
 * aborting the whole PHP process. The panic must surface as a task error
 * (wrapped in a CallbackExecutionException on the async path), and the
 * extension must stay usable afterwards.
 */
class MongodbInsertManyInvalidDocumentTest extends BaseTestCase
{
    public function test(): void
    {
        $collection = TestMongodbResolver::getSconcurTestCollection(
            collectionName: 'insertManyInvalidDocument',
        );

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: static fn() => $collection->insertMany(
                // @phpstan-ignore argument.type (invalid documents shape on purpose)
                documents: [
                    ['title' => 'valid document'],
                    'scalar instead of document',
                ],
            ),
        );

        $exception = null;

        try {
            $waitGroup->waitResults();
        } catch (CallbackExecutionException $caughtException) {
            $exception = $caughtException;
        }

        self::assertNotNull($exception);

        // The async path wraps the task error raised inside the fiber; the
        // original TaskErrorException stays reachable via the previous chain.
        self::assertInstanceOf(TaskErrorException::class, $exception->getPrevious());

        $insertOneResult = $collection->insertOne(
            document: ['title' => 'extension is still alive'],
        );

        self::assertNotNull($insertOneResult->insertedId);
    }
}
