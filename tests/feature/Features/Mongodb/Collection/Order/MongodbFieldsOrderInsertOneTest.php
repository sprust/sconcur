<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Order;

use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbOrderTestCase;

class MongodbFieldsOrderInsertOneTest extends BaseMongodbOrderTestCase
{
    protected function getCollectionName(): string
    {
        return 'insertOne';
    }

    protected function insertDocument(array $document): void
    {
        $this->sconcurCollection->insertOne(
            document: $document
        );
    }
}
