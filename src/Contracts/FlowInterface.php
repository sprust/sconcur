<?php

namespace SConcur\Contracts;

use Fiber;
use SConcur\Dto\TaskResultDto;
use SConcur\Entities\Context;
use SConcur\Features\MethodEnum;

interface FlowInterface
{
    public function getUuid(): string;

    public function pushTask(Context $context, MethodEnum $method, string $payload): TaskResultDto;

    public function getFiberByTaskUuid(string $taskUuid): ?Fiber;

    public function deleteFiberByTaskUuid(string $taskUuid): void;

    public function waitResult(Context $context): TaskResultDto;

    public function close(): void;
}
