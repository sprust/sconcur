<?php

declare(strict_types=1);

namespace SConcur\Features;

use Fiber;
use LogicException;
use RuntimeException;
use SConcur\Connection\Extension;
use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\FiberStopException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Flow\CurrentFlow;
use SConcur\State;
use Throwable;

readonly class FeatureExecutor
{
    public static function exec(MethodEnum $method, string $payload): TaskResultDto
    {
        $currentFlow = State::getCurrentFlow();

        $runningTask = Extension::get()->push(
            flowKey: $currentFlow->key,
            method: $method,
            payload: $payload
        );

        if ($currentFlow->isAsync) {
            if ($currentFiber = Fiber::getCurrent()) {
                State::addFiberTask(
                    flowKey: $currentFlow->key,
                    taskKey: $runningTask->key,
                    fiber: $currentFiber
                );
            } else {
                throw new LogicException(
                    message: "Can't wait outside of fiber."
                );
            }

            $result = static::suspend($currentFlow);
        } else {
            $result = static::wait($currentFlow->key);

            static::checkResult(result: $result);
        }

        if ($result->key !== $runningTask->key) {
            throw new LogicException(
                message: "Unexpected task key. Expected [$runningTask->key], got [$result->key]."
            );
        }

        return $result;
    }

    public static function wait(string $flowKey): TaskResultDto
    {
        return Extension::get()->wait(
            flowKey: $flowKey,
        );
    }

    protected static function checkResult(TaskResultDto $result): TaskResultDto
    {
        if ($result->isError) {
            throw new TaskErrorException(
                $result->payload ?: 'Unknown error'
            );
        }

        return $result;
    }

    protected static function suspend(CurrentFlow $currentFlow): TaskResultDto
    {
        if (!$currentFlow->isAsync) {
            throw new LogicException(
                message: 'Can\'t suspend outside of fiber.'
            );
        }

        try {
            $result = Fiber::suspend();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                message: $exception->getMessage(),
                previous: $exception
            );
        }

        if ($result instanceof FiberStopException) {
            throw $result;
        }

        if ($result instanceof TaskResultDto) {
            static::checkResult($result);
        } else {
            throw new LogicException(
                message: 'Unexpected result type.'
            );
        }

        return $result;
    }
}
