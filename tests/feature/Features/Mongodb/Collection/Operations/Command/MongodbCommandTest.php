<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\Command;

use SConcur\Features\Mongodb\Connection\Database;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;
use SConcur\WaitGroup;
use Throwable;

class MongodbCommandTest extends BaseTestCase
{
    private Database $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = TestMongodbResolver::getSconcurTestDatabase();
    }

    public function testPing(): void
    {
        $result = $this->database->command(['ping' => 1]);

        self::assertSame(1.0, $result['ok']);
    }

    public function testCommandAsync(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(callback: fn(): array => $this->database->command(['ping' => 1]));

        $results = $waitGroup->waitResults();

        self::assertCount(1, $results);

        $result = array_values($results)[0];

        self::assertSame(1.0, $result['ok']);
    }

    public function testInvalidCommandThrows(): void
    {
        $this->expectException(Throwable::class);

        $this->database->command(['thisIsNotARealCommand' => 1]);
    }
}
