<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection;

use SConcur\Features\Mongodb\Connection\Collection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;

abstract class BaseMongodbOrderTestCase extends BaseTestCase
{
    protected Collection $sconcurCollection;
    protected int $keysCount = 100;

    /**
     * @var array<string, array<string, true>>
     */
    protected array $document;

    /**
     * @var string[]
     */
    private array $keys;

    abstract protected function getCollectionName(): string;

    /**
     * @param array<string, array<string, true>> $document
     */
    abstract protected function insertDocument(array $document): void;

    protected function setUp(): void
    {
        parent::setUp();

        $collectionName = 'order_' . ucfirst($this->getCollectionName());

        $this->sconcurCollection = TestMongodbResolver::getSconcurTestCollection($collectionName);

        $this->sconcurCollection->deleteMany([]);

        $this->keys = array_map(
            fn(int $index) => '_' . $index,
            range(1, $this->keysCount)
        );

        $document       = [];
        $nestedDocument = [];

        foreach ($this->keys as $key) {
            $document[$key]       = true;
            $nestedDocument[$key] = true;
        }

        foreach ($this->keys as $key) {
            $document[$key] = $nestedDocument;
        }

        $this->document = $document;
    }

    public function test(): void
    {
        $this->insertDocument($this->document);

        $document = $this->sconcurCollection->findOne(
            filter: [],
            projection: [
                '_id' => 0,
            ]
        );

        self::assertNotNull($document);

        self::assertCount(
            $this->keysCount,
            $document
        );

        $documentKeys = array_keys($document);

        foreach ($this->keys as $rootIndex => $rootKey) {
            self::assertSame(
                $rootKey,
                $documentKeys[$rootIndex],
                "Failed asserting key [$rootKey] at index [$rootIndex]"
            );

            $nestedDocumentKeys = array_keys($document[$rootKey]);

            foreach ($this->keys as $nestedIndex => $nestedKey) {
                self::assertSame(
                    $nestedKey,
                    $nestedDocumentKeys[$nestedIndex],
                    "Failed asserting nested key [$rootKey.$nestedKey] at index [$nestedKey]"
                );
            }
        }
    }
}
