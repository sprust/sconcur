<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Update;

use SConcur\Entities\Context;
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
        $this->sconcurCollection->updateOne(
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
        $this->sconcurCollection->insertMany(
            context: Context::create(2),
            documents: array_map(
                fn(int $index) => ["e:$index" => $this->sconcurObjectId],
                range(1, $this->documentsCount)
            )
        );
    }

    protected function caseForKey(Context $context, string $key): void
    {
        $field = uniqid();

        $result = $this->sconcurCollection->updateOne(
            context: $context,
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
                context: $context,
                filter: [$field => $this->sconcurObjectId]
            )
        );
    }

    protected function caseForAll(Context $context): void
    {
        $field = uniqid();

        $result = $this->sconcurCollection->updateOne(
            context: $context,
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
                context: $context,
                filter: [$field => $this->sconcurObjectId]
            )
        );
    }
}
