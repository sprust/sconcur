<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Collection\Operations\BulkWrite;

use SConcur\Tests\Feature\Features\Mongodb\Collection\BaseMongodbAsyncTestCase;
use SConcur\Tests\Impl\TestMongodbResolver;

class MongodbAsyncBulkWriteTest extends BaseMongodbAsyncTestCase
{
    protected function getCollectionName(): string
    {
        return 'bulkWrite';
    }

    protected function on_1_start(): void
    {
        $document = [
            uniqid('InsertOne_') => $this->sconcurObjectId,
        ];

        $this->sconcurCollection->bulkWrite(
            operations: [
                [
                    'insertOne' => [
                        $document,
                    ],
                ],
            ]
        );

        self::assertEquals(
            1,
            $this->sconcurCollection->countDocuments(
                filter: $document
            )
        );
    }

    protected function on_1_middle(): void
    {
        foreach (['updateOne', 'updateMany'] as $operation) {
            $filter = [
                uniqid(ucfirst($operation) . '_') => $this->sconcurObjectId,
            ];

            $this->sconcurCollection->bulkWrite(
                operations: [
                    [
                        $operation => [
                            $filter,
                            [
                                '$set' => [
                                    'value' => 'primary',
                                ],
                            ],
                        ],
                    ],
                ]
            );

            self::assertEquals(
                0,
                $this->sconcurCollection->countDocuments(
                    filter: $filter
                )
            );

            $this->sconcurCollection->bulkWrite(
                operations: [
                    [
                        $operation => [
                            $filter,
                            [
                                '$set' => [
                                    'value' => 'primary',
                                ],
                            ],
                            [
                                'upsert' => true,
                            ],
                        ],
                    ],
                ]
            );

            self::assertEquals(
                1,
                $this->sconcurCollection->countDocuments(
                    filter: [
                        ...$filter,
                        'value' => 'primary',
                    ]
                )
            );

            $this->sconcurCollection->bulkWrite(
                operations: [
                    [
                        $operation => [
                            $filter,
                            [
                                '$set' => [
                                    'value' => 'updated',
                                ],
                            ],
                        ],
                    ],
                ]
            );

            self::assertEquals(
                1,
                $this->sconcurCollection->countDocuments(
                    filter: [
                        ...$filter,
                        'value' => 'updated',
                    ]
                )
            );
        }
    }

    protected function on_2_start(): void
    {
        $filter = [
            uniqid('DeleteOneMany_') => $this->sconcurObjectId,
        ];

        $this->sconcurCollection->insertMany(
            documents: [
                $filter,
                $filter,
                $filter,
            ]
        );

        self::assertEquals(
            3,
            $this->sconcurCollection->countDocuments(
                filter: $filter
            )
        );

        $this->sconcurCollection->bulkWrite(
            operations: [
                [
                    'deleteOne' => [
                        $filter,
                    ],
                ],
            ]
        );

        self::assertEquals(
            2,
            $this->sconcurCollection->countDocuments(
                filter: $filter
            )
        );

        $this->sconcurCollection->bulkWrite(
            operations: [
                [
                    'deleteMany' => [
                        $filter,
                    ],
                ],
            ]
        );

        self::assertEquals(
            0,
            $this->sconcurCollection->countDocuments(
                filter: $filter
            )
        );
    }

    protected function on_2_middle(): void
    {
        $datetime = TestMongodbResolver::getSconcurDateTime();

        $filter = [
            uniqid('All_') => $this->sconcurObjectId,
            'createdAt'    => $datetime,
        ];

        $this->sconcurCollection->bulkWrite(
            operations: [
                [
                    'insertOne' => [
                        $filter,
                    ],
                ],
                [
                    'updateOne' => [
                        $filter,
                        [
                            '$set' => [
                                'updatedAt' => $datetime,
                            ],
                        ],
                    ],
                ],
                [
                    'updateMany' => [
                        $filter,
                        [
                            '$set' => [
                                'valueMany' => 'primary',
                            ],
                        ],
                    ],
                ],
            ]
        );

        $primaryFilter = [
            ...$filter,
            'valueMany' => 'primary',
            'updatedAt' => $datetime,
        ];

        self::assertEquals(
            1,
            $this->sconcurCollection->countDocuments(
                filter: $primaryFilter
            )
        );

        $this->sconcurCollection->bulkWrite(
            operations: [
                [
                    'replaceOne' => [
                        $filter,
                        [
                            ...$filter,
                            'replaced' => true,
                        ],
                    ],
                ],
            ]
        );

        self::assertEquals(
            0,
            $this->sconcurCollection->countDocuments(
                filter: $primaryFilter
            )
        );

        self::assertEquals(
            1,
            $this->sconcurCollection->countDocuments(
                filter: [
                    ...$filter,
                    'replaced' => true,
                ]
            )
        );
    }

    protected function on_iterate(): void
    {
        $document = [
            uniqid('Iterate_InsertOne_') => $this->sconcurObjectId,
        ];

        $this->sconcurCollection->bulkWrite(
            operations: [
                [
                    'insertOne' => [
                        $document,
                    ],
                ],
            ]
        );

        self::assertEquals(
            1,
            $this->sconcurCollection->countDocuments(
                filter: $document
            )
        );
    }

    protected function on_exception(): void
    {
        $this->sconcurCollection->bulkWrite(
            operations: [
                [
                    'updateOne' => [
                        [],
                        [
                            '$unknownOperation' => true,
                        ],
                    ],
                ],
            ]
        );
    }

    protected function assertResult(array $results): void
    {
        // no result
    }
}
