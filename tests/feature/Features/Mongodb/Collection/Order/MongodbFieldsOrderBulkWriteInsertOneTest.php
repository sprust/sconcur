<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Order;

use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbOrderTestCase;

class MongodbFieldsOrderBulkWriteInsertOneTest extends BaseMongodbOrderTestCase
{
    protected function getCollectionName(): string
    {
        return 'bulkWrite_InsertOne';
    }

    protected function insertDocument(array $document): void
    {
        $this->sconcurCollection->bulkWrite(
            [
                [
                    'insertOne' => [
                        $document,
                    ],
                ],
            ]
        );
    }
}
