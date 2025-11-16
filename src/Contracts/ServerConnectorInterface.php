<?php

namespace SConcur\Contracts;

use SConcur\Dto\RunningTaskDto;
use SConcur\Dto\TaskResultDto;
use SConcur\Entities\Context;
use SConcur\Features\MethodEnum;

interface ServerConnectorInterface
{
    public function clone(Context $context): ServerConnectorInterface;

    public function connect(Context $context, bool $waitHandshake): void;

    public function disconnect(): void;

    public function isConnected(): bool;

    public function write(Context $context, MethodEnum $method, string $payload): RunningTaskDto;

    public function read(Context $context): TaskResultDto;
}
