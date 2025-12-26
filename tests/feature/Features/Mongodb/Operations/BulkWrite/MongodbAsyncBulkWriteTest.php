<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Mongodb\Operations\BulkWrite;

use SConcur\Entities\Context;
use SConcur\Features\Mongodb\Types\ObjectId;
use SConcur\Features\Mongodb\Types\UTCDateTime;
use SConcur\Tests\Feature\Features\Mongodb\BaseMongodbAsyncTestCase;

class MongodbAsyncBulkWriteTest extends BaseMongodbAsyncTestCase
{
    protected ObjectId $fieldValue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fieldValue = new ObjectId('693a7119e9d4885085366c80');
    }

    protected function getCollectionName(): string
    {
        return 'bulkWrite';
    }

    protected function on_1_start(Context $context): void
    {
        $document = [
            uniqid('InsertOne_') => $this->fieldValue,
        ];

        $this->sconcurCollection->bulkWrite(
            context: $context,
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
                context: $context,
                filter: $document
            )
        );
    }

    protected function on_1_middle(Context $context): void
    {
        foreach (['updateOne', 'updateMany'] as $operation) {
            $filter = [
                uniqid(ucfirst($operation) . '_') => $this->fieldValue,
            ];

            $this->sconcurCollection->bulkWrite(
                context: $context,
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
                    context: $context,
                    filter: $filter
                )
            );

            $this->sconcurCollection->bulkWrite(
                context: $context,
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
                    context: $context,
                    filter: [
                        ...$filter,
                        'value' => 'primary',
                    ]
                )
            );

            $this->sconcurCollection->bulkWrite(
                context: $context,
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
                    context: $context,
                    filter: [
                        ...$filter,
                        'value' => 'updated',
                    ]
                )
            );
        }
    }

    protected function on_2_start(Context $context): void
    {
        $filter = [
            uniqid('DeleteOneMany_') => $this->fieldValue,
        ];

        $this->sconcurCollection->insertMany(
            context: $context,
            documents: [
                $filter,
                $filter,
                $filter,
            ]
        );

        self::assertEquals(
            3,
            $this->sconcurCollection->countDocuments(
                context: $context,
                filter: $filter
            )
        );

        $this->sconcurCollection->bulkWrite(
            context: $context,
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
                context: $context,
                filter: $filter
            )
        );

        $this->sconcurCollection->bulkWrite(
            context: $context,
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
                context: $context,
                filter: $filter
            )
        );
    }

    protected function on_2_middle(Context $context): void
    {
        $datetime = new UTCDateTime();

        $filter = [
            uniqid('All_') => $this->fieldValue,
            'createdAt'    => $datetime,
        ];

        $this->sconcurCollection->bulkWrite(
            context: $context,
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
                context: $context,
                filter: $primaryFilter
            )
        );

        $this->sconcurCollection->bulkWrite(
            context: $context,
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
                context: $context,
                filter: $primaryFilter
            )
        );

        self::assertEquals(
            1,
            $this->sconcurCollection->countDocuments(
                context: $context,
                filter: [
                    ...$filter,
                    'replaced' => true,
                ]
            )
        );
    }

    protected function on_iterate(Context $context): void
    {
        $document = [
            uniqid('Iterate_InsertOne_') => $this->fieldValue,
        ];

        $this->sconcurCollection->bulkWrite(
            context: $context,
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
                context: $context,
                filter: $document
            )
        );
    }

    protected function on_exception(Context $context): void
    {
        $this->sconcurCollection->bulkWrite(
            context: $context,
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
