<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Rename;

use SConcur\Features\Mongodb\Connection\Collection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;

class MongodbRenameTest extends BaseTestCase
{
    private Collection $source;
    private Collection $target;
    private string $targetName = 'renameTarget';

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = TestMongodbResolver::getSconcurTestCollection('renameSource');
        $this->target = $this->source->database->selectCollection($this->targetName);

        $this->source->drop();
        $this->target->drop();
    }

    public function test(): void
    {
        $this->source->insertOne(['k' => 'v']);

        $this->source->rename($this->targetName, dropTarget: true);

        // The source collection no longer exists; the target holds the document.
        self::assertSame(0, $this->source->countDocuments([]));
        self::assertSame(1, $this->target->countDocuments(['k' => 'v']));
    }
}
