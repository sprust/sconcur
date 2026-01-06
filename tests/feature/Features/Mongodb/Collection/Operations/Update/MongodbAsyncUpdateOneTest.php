<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Update;

use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbAsyncTestCase;

class MongodbAsyncUpdateOneTest extends BaseMongodbAsyncTestCase
{
    protected string $fieldName;

    protected int $documentsCount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fieldName = uniqid();

        $this->documentsCount = 10;

        $this->seedDocuments();
    }

    protected function getCollectionName(): string
    {
        return 'updateOne';
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
        $this->sconcurCollection->updateOne(
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
        $this->sconcurCollection->insertMany(
            documents: array_map(
                fn(int $index) => ["e:$index" => $this->sconcurObjectId],
                range(1, $this->documentsCount)
            )
        );
    }

    protected function caseForKey(string $key): void
    {
        $field = uniqid();

        $result = $this->sconcurCollection->updateOne(
            filter: [$key => $this->sconcurObjectId],
            update: ['$set' => [$field => $this->sconcurObjectId]]
        );

        self::assertEquals(
            1,
            $result->modifiedCount
        );

        self::assertEquals(
            1,
            $this->sconcurCollection->countDocuments(
                filter: [$field => $this->sconcurObjectId]
            )
        );
    }

    protected function caseForAll(): void
    {
        $field = uniqid();

        $result = $this->sconcurCollection->updateOne(
            filter: [],
            update: ['$set' => [$field => $this->sconcurObjectId]]
        );

        self::assertEquals(
            1,
            $result->modifiedCount
        );

        self::assertEquals(
            1,
            $this->sconcurCollection->countDocuments(
                filter: [$field => $this->sconcurObjectId]
            )
        );
    }
}
