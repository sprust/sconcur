<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Find;

use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbAsyncTestCase;

class MongodbAsyncFindOneAndReplaceTest extends BaseMongodbAsyncTestCase
{
    protected string $fieldName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fieldName = uniqid();

        $this->sconcurCollection->insertOne([
            $this->fieldName => 'original',
            'extra'          => 'keep',
        ]);
    }

    protected function getCollectionName(): string
    {
        return 'findOneAndReplace';
    }

    protected function on_1_start(): void
    {
        $result = $this->sconcurCollection->findOneAndReplace(
            filter: [$this->fieldName => 'original'],
            replacement: [$this->fieldName => 'replaced'],
        );

        self::assertNotNull($result);
        self::assertEquals('replaced', $result[$this->fieldName]);
        self::assertArrayNotHasKey('extra', $result);
    }

    protected function on_1_middle(): void
    {
        $result = $this->sconcurCollection->findOneAndReplace(
            filter: [$this->fieldName => 'replaced'],
            replacement: [$this->fieldName => 'replaced2'],
            returnDocument: false,
        );

        self::assertNotNull($result);
        self::assertEquals('replaced', $result[$this->fieldName]);
    }

    protected function on_2_start(): void
    {
        $result = $this->sconcurCollection->findOneAndReplace(
            filter: [$this->fieldName => 'nonexistent'],
            replacement: [$this->fieldName => 'upserted'],
            upsert: true,
        );

        self::assertNotNull($result);
        self::assertEquals('upserted', $result[$this->fieldName]);
    }

    protected function on_2_middle(): void
    {
        $result = $this->sconcurCollection->findOneAndReplace(
            filter: [$this->fieldName => 'notfound_ever'],
            replacement: [$this->fieldName => 'x'],
        );

        self::assertNull($result);
    }

    protected function on_iterate(): void
    {
        $count = $this->sconcurCollection->countDocuments([]);
        self::assertGreaterThan(0, $count);
    }

    protected function on_exception(): void
    {
        $this->sconcurCollection->findOneAndReplace(
            filter: [],
            replacement: ['$set' => [$this->fieldName => 'x']],
        );
    }

    protected function assertResult(array $results): void
    {
        // no action
    }
}
