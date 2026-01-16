<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Order;

use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbOrderTestCase;

// TODO: not sorted for upper level
class MongodbFieldsOrderBulkWriteUpdateOneTest extends BaseMongodbOrderTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::markTestIncomplete();
    }

    protected function getCollectionName(): string
    {
        return 'bulkWrite_UpdateOne';
    }

    protected function insertDocument(array $document): void
    {
        $this->sconcurCollection->bulkWrite(
            [
                [
                    'updateOne' => [
                        [],
                        ['$set'   => $document],
                        ['upsert' => true],
                    ],
                ],
            ]
        );
    }
}
