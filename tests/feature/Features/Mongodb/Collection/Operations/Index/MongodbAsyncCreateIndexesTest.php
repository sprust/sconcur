<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Index;

use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbAsyncTestCase;

class MongodbAsyncCreateIndexesTest extends BaseMongodbAsyncTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // deleteMany() clears documents, not indexes: without this the collection
        // keeps the indexes of the previous run and the assertions below pass on
        // leftovers instead of on what this run created.
        if (iterator_count($this->driverCollection->listIndexes()) > 0) {
            $this->driverCollection->dropIndexes();
        }

        $this->sconcurCollection->insertOne(['field_a' => 1, 'field_b' => 2]);
    }

    protected function getCollectionName(): string
    {
        return 'createIndexes';
    }

    protected function on_1_start(): void
    {
        $names = $this->sconcurCollection->createIndexes([
            ['keys' => ['field_a' => 1]],
            ['keys' => ['field_b' => -1]],
        ]);

        self::assertCount(2, $names);
    }

    protected function on_1_middle(): void
    {
        $indexes = $this->sconcurCollection->listIndexes();
        // _id + field_a + field_b = 3
        self::assertGreaterThanOrEqual(3, count($indexes));
    }

    protected function on_2_start(): void
    {
        $names = $this->sconcurCollection->createIndexes([
            ['keys' => ['field_a' => 1, 'field_b' => 1], 'name' => 'compound_ab'],
        ]);

        self::assertCount(1, $names);
        self::assertEquals('compound_ab', $names[0]);
    }

    protected function on_2_middle(): void
    {
        // Only what this flow created is guaranteed to be here: flow 1 runs
        // concurrently and its createIndexes() may still be in flight.
        // _id + compound_ab = 2
        $indexes = $this->sconcurCollection->listIndexes();
        self::assertGreaterThanOrEqual(2, count($indexes));

        $names = array_map(
            static fn(array $index): string => $index['name'] ?? '',
            $indexes,
        );

        self::assertContains('compound_ab', $names);
    }

    protected function on_iterate(): void
    {
        $indexes = $this->sconcurCollection->listIndexes();
        self::assertGreaterThanOrEqual(1, count($indexes));
    }

    protected function on_exception(): void
    {
        // Invalid index spec
        $this->sconcurCollection->createIndexes([
            ['keys' => ['$invalid' => 'bad_type']],
        ]);
    }

    protected function assertResult(array $results): void
    {
        // Both flows are done here, so the whole set is deterministic — the
        // creation order is not, hence the sort.
        $existIndexNames = [];

        foreach ($this->driverCollection->listIndexes() as $index) {
            $existIndexNames[] = $index->getName();
        }

        sort($existIndexNames);

        self::assertSame(
            [
                '_id_',
                'compound_ab',
                'field_a_1',
                'field_b_-1',
            ],
            $existIndexNames,
        );
    }
}
