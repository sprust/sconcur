<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Update;

use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbAsyncTestCase;

class MongodbAsyncUpdateManyTest extends BaseMongodbAsyncTestCase
{
    protected string $fieldName;

    protected int $documentsCount;
    protected int $insertedDocumentsCount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fieldName = uniqid();

        $this->documentsCount         = 10;
        $this->insertedDocumentsCount = 0;

        $this->seedDocuments();
    }

    protected function getCollectionName(): string
    {
        return 'updateMany';
    }

    protected function on_1_start(): void
    {
        $this->caseForKey(key: 'e:1');
    }

    protected function on_1_middle(): void
    {
        $this->caseForAll();
    }

    protected function on_2_start(): void
    {
        $this->caseForKey(key: 'e:2');
    }

    protected function on_2_middle(): void
    {
        $this->caseForAll();
    }

    protected function on_iterate(): void
    {
        $this->caseForKey(key: 'e:3');
        $this->caseForAll();
    }

    protected function on_exception(): void
    {
        $this->sconcurCollection->updateMany(
            filter: [],
            update: [uniqid('$') => [$this->fieldName => $this->sconcurObjectId]]
        );
    }

    protected function assertResult(array $results): void
    {
        // no action
    }

    protected function seedDocuments(): void
    {
        foreach (range(1, $this->documentsCount) as $index) {
            $this->sconcurCollection->insertMany(
                documents: array_map(
                    function () use ($index) {
                        ++$this->insertedDocumentsCount;

                        return ["e:$index" => $this->sconcurObjectId];
                    },
                    range(1, $this->documentsCount)
                )
            );
        }
    }

    protected function caseForKey(string $key): void
    {
        $field = uniqid();

        $result = $this->sconcurCollection->updateMany(
            filter: [$key => $this->sconcurObjectId],
            update: ['$set' => [$field => $this->sconcurObjectId]]
        );

        self::assertEquals(
            $this->documentsCount,
            $result->modifiedCount
        );

        self::assertEquals(
            $this->documentsCount,
            $this->sconcurCollection->countDocuments(
                filter: [$field => $this->sconcurObjectId]
            )
        );
    }

    protected function caseForAll(): void
    {
        $field = uniqid();

        $result = $this->sconcurCollection->updateMany(
            filter: [],
            update: ['$set' => [$field => $this->sconcurObjectId]]
        );

        self::assertEquals(
            $this->insertedDocumentsCount,
            $result->modifiedCount
        );

        self::assertEquals(
            $this->insertedDocumentsCount,
            $this->sconcurCollection->countDocuments(
                filter: [$field => $this->sconcurObjectId]
            )
        );
    }
}
