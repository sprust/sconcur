<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\Payloads\Support\IndexName;
use SConcur\Transport\PayloadParametersInterface;

readonly class CreateIndexesPayloadParameters implements PayloadParametersInterface
{
    /**
     * @param array<int, array{keys: array<string, int|string>, name?: string}> $indexes
     */
    public function __construct(
        private array $indexes,
    ) {
    }

    public function getData(): array
    {
        return [
            'ix' => $this->prepareIndexes(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function prepareIndexes(): array
    {
        $preparedIndexes = [];

        foreach ($this->indexes as $index) {
            $keys = $index['keys'];

            $preparedIndexes[] = [
                'k' => $keys,
                'n' => $index['name'] ?? IndexName::fromKeys($keys),
            ];
        }

        return $preparedIndexes;
    }
}
