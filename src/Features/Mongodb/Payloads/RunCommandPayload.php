<?php

declare(strict_types=1);

namespace SConcur\Features\Mongodb\Payloads;

use SConcur\Features\Mongodb\CommandEnum;
use SConcur\Features\Mongodb\Payloads\Base\BaseMongodbPayload;
use SConcur\Features\Mongodb\Payloads\Dto\Connection;
use SConcur\Features\Mongodb\Payloads\Dto\Parameters;

readonly class RunCommandPayload extends BaseMongodbPayload
{
    /**
     * @param array<string, mixed> $command
     */
    public function __construct(
        public Connection $connection,
        public array $command,
    ) {
    }

    protected function getCommand(): CommandEnum
    {
        return CommandEnum::RunCommand;
    }

    protected function getConnection(): Connection
    {
        return $this->connection;
    }

    protected function getParameters(): Parameters
    {
        return new Parameters(
            data: $this->command,
            isObject: true,
        );
    }
}
