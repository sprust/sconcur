<?php

namespace SConcur\Contracts;

use SConcur\Dto\TaskResultDto;
use SConcur\Entities\Context;
use SConcur\Features\MethodEnum;

interface FlowInterface
{
    public function getUuid(): string;

    public function pushTask(Context $context, MethodEnum $method, string $payload): TaskResultDto;

    public function waitResult(Context $context): TaskResultDto;

    public function close(): void;
}
