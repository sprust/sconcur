<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;

readonly class FindOneAndReplacePayload extends BaseMongodbPayload
{
    /**
     * @param array<string, mixed>      $filter
     * @param array<string, mixed>      $replacement
     * @param array<string, mixed>|null $projection
     */
    public function __construct(
        public Connection $connection,
        public array $filter,
        public array $replacement,
        public ?array $projection = null,
        public bool $upsert = false,
        public bool $returnDocument = true,
    ) {
    }

    protected function getCommand(): CommandEnum
    {
        return CommandEnum::FindOneAndReplace;
    }

    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function getParameters(): Parameters
    {
        $data = [
            'f'  => $this->filter,
            'r'  => $this->replacement,
            'ou' => $this->upsert,
            'rd' => $this->returnDocument,
        ];

        if ($this->projection !== null) {
            $data['op'] = $this->projection;
        }

        return new Parameters(
            data: $data,
            isObject: true,
        );
    }
}
