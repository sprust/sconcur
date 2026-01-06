<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Update;

use SConcur\Entities\Context;
use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbAsyncTestCase;

class MongodbAsyncUpdateManyTest extends BaseMongodbAsyncTestCase
{
    protected string $fieldName;

    protected int $documentsCount;
    protected int $expectedDocumentsCount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fieldName = uniqid();

        $this->documentsCount = 10;

        $this->seedDocuments();
    }

    protected function getCollectionName(): string
    {
        return 'updateMany';
    }

    protected function on_1_start(Context $context): void
    {
        $this->caseForKey(context: $context, key: 'e:1');
    }

    protected function on_1_middle(Context $context): void
    {
        $this->caseForAll(context: $context);
    }

    protected function on_2_start(Context $context): void
    {
        $this->caseForKey(context: $context, key: 'e:2');
    }

    protected function on_2_middle(Context $context): void
    {
        $this->caseForAll(context: $context);
    }

    protected function on_iterate(Context $context): void
    {
        $this->caseForKey(context: $context, key: 'e:3');
        $this->caseForAll(context: $context);
    }

    protected function on_exception(Context $context): void
    {
        $this->sconcurCollection->updateMany(
            context: $context,
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
        $context = Context::create(2);

        foreach (range(1, $this->documentsCount) as $index) {
            $this->sconcurCollection->insertMany(
                context: $context,
                documents: array_map(
                    fn() => ["e:$index" => $this->sconcurObjectId],
                    range(1, $this->documentsCount)
                )
            );
        }
    }

    protected function caseForKey(Context $context, string $key): void
    {
        $field = uniqid();

        $result = $this->sconcurCollection->updateMany(
            context: $context,
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
                context: $context,
                filter: [$field => $this->sconcurObjectId]
            )
        );
    }

    protected function caseForAll(Context $context): void
    {
        $field = uniqid();

        $result = $this->sconcurCollection->updateMany(
            context: $context,
            filter: [],
            update: ['$set' => [$field => $this->sconcurObjectId]]
        );

        $totalDocumentsCount = $this->documentsCount * $this->documentsCount;

        self::assertEquals(
            $totalDocumentsCount,
            $result->modifiedCount
        );

        self::assertEquals(
            $totalDocumentsCount,
            $this->sconcurCollection->countDocuments(
                context: $context,
                filter: [$field => $this->sconcurObjectId]
            )
        );
    }
}
