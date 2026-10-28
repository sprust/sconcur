<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Serialization;

use PHPUnit\Framework\Attributes\DataProvider;
use SConcur\Features\Mongodb\Connection\Collection;
use SConcur\Features\Mongodb\Connection\Database;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;

/**
 * A document key the numeric check of a PHP array cannot read safely: the empty one,
 * which crashed the process (serverStatus reports one under metrics.apiVersions for a
 * client that sent no app name), and a lone '-', after which the check reads one byte
 * more. Both come back as the string keys they are, at any depth.
 */
class MongodbEmptyKeyTest extends BaseTestCase
{
    protected Database $database;

    protected Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database   = TestMongodbResolver::getSconcurTestDatabase();
        $this->collection = TestMongodbResolver::getSconcurTestCollection(
            collectionName: 'empty_key',
        );

        $this->collection->deleteMany(
            filter: [],
        );
    }

    /**
     * @return array<string, array{array<int|string, mixed>}>
     */
    public static function documents(): array
    {
        return [
            'empty key'                   => [['' => 1]],
            'nested empty key'            => [['a' => ['' => 1]]],
            'empty key inside a list'     => [['x' => [['' => 1], ['' => 2]]]],
            'empty key beside others'     => [['a' => 1, '' => 'empty', 'b' => ['' => null]]],
            'lone minus key'              => [['-' => 1, 'a' => ['-' => 2]]],
            'numeric keys stay integers'  => [['12' => 1, '-5' => 2, '-' => 3, '' => 4]],
        ];
    }

    /**
     * @param array<int|string, mixed> $value
     */
    #[DataProvider('documents')]
    public function testACommandAnswerKeepsTheKey(array $value): void
    {
        $result = $this->database->command([
            'aggregate' => 1,
            'pipeline'  => [['$documents' => [['v' => ['$literal' => $value]]]]],
            'cursor'    => (object) [],
        ]);

        self::assertSame($value, $result['cursor']['firstBatch'][0]['v']);
    }

    /**
     * @param array<int|string, mixed> $value
     */
    #[DataProvider('documents')]
    public function testAStoredDocumentKeepsTheKey(array $value): void
    {
        $this->collection->insertOne(
            document: [
                'name' => 'empty-key',
                'v'    => $value,
            ],
        );

        $document = $this->collection->findOne(
            filter: ['name' => 'empty-key'],
        );

        self::assertNotNull($document);
        self::assertSame($value, $document['v']);
    }
}
