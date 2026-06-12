<?php

declare(strict_types=1);

namespace SConcur\Features;

use Fiber;
use LogicException;
use SConcur\Connection\Extension;
use SConcur\Dto\RunningTaskDto;
use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Flow\CurrentFlow;
use SConcur\State;
use Throwable;

readonly class FeatureExecutor
{
    public static function exec(MethodEnum $method, string $payload): TaskResultDto
    {
        $currentFlow = State::getCurrentFlow();

        try {
            $runningTask = Extension::get()->push(
                flowKey: $currentFlow->key,
                method: $method,
                payload: $payload
            );
        } catch (Throwable $exception) {
            static::stopFailedCallFlow(currentFlow: $currentFlow);

            throw $exception;
        }

        return static::handle(
            currentFlow: $currentFlow,
            runningTask: $runningTask,
            isNext: false
        );
    }

    public static function next(string $taskKey): TaskResultDto
    {
        $currentFlow = State::getCurrentFlow();

        try {
            $runningTask = Extension::get()->next(
                flowKey: $currentFlow->key,
                taskKey: $taskKey
            );
        } catch (Throwable $exception) {
            static::stopFailedCallFlow(currentFlow: $currentFlow);

            throw $exception;
        }

        return static::handle(
            currentFlow: $currentFlow,
            runningTask: $runningTask,
            isNext: true
        );
    }

    public static function wait(string $flowKey): TaskResultDto
    {
        return Extension::get()->wait(
            flowKey: $flowKey,
        );
    }

    /**
     * A failed push/next leaves no task to wait for. The one-off flow created
     * for a synchronous call must be stopped right away; an async flow belongs
     * to its WaitGroup and is stopped there.
     */
    protected static function stopFailedCallFlow(CurrentFlow $currentFlow): void
    {
        if ($currentFlow->isAsync) {
            return;
        }

        Extension::get()->stopFlow($currentFlow->key);
    }

    protected static function handle(CurrentFlow $currentFlow, RunningTaskDto $runningTask, bool $isNext): TaskResultDto
    {
        if ($currentFlow->isAsync) {
            if ($currentFiber = Fiber::getCurrent()) {
                State::addFiberTask(
                    flowKey: $currentFlow->key,
                    taskKey: $runningTask->key,
                    fiberId: spl_object_id($currentFiber)
                );

                unset($currentFiber);
            } else {
                throw new LogicException(
                    message: 'Can\'t wait outside of fiber.'
                );
            }

            $result = static::suspend($currentFlow);
        } else {
            $result = static::handleSync(
                currentFlow: $currentFlow,
                runningTask: $runningTask,
                isNext: $isNext
            );
        }

        if ($result->key !== $runningTask->key) {
            throw new LogicException(
                message: "Unexpected task key. Expected [$runningTask->key], got [$result->key]."
            );
        }

        return $result;
    }

    /**
     * Outside of a fiber every push creates a one-off flow on the Go side,
     * so the flow must be stopped here as soon as it is no longer needed —
     * otherwise it leaks for the lifetime of the process. The only flow that
     * survives is the one owning an unfinished cursor (hasNext): it is handed
     * over to State and released by the iterator or on the final batch.
     */
    protected static function handleSync(CurrentFlow $currentFlow, RunningTaskDto $runningTask, bool $isNext): TaskResultDto
    {
        try {
            $result = static::wait($currentFlow->key);

            static::checkResult(result: $result);
        } catch (Throwable $exception) {
            Extension::get()->stopFlow($currentFlow->key);

            if ($isNext) {
                State::releaseSyncTaskFlow($runningTask->key);
            }

            throw $exception;
        }

        if ($isNext) {
            Extension::get()->stopFlow($currentFlow->key);

            if (!$result->hasNext) {
                State::releaseSyncTaskFlow($runningTask->key);
            }
        } elseif ($result->hasNext) {
            State::registerSyncTaskFlow(
                taskKey: $runningTask->key,
                flowKey: $currentFlow->key
            );
        } else {
            Extension::get()->stopFlow($currentFlow->key);
        }

        return $result;
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

        $result = Fiber::suspend();

        if ($result instanceof TaskResultDto) {
            static::checkResult($result);
        } else {
            throw new LogicException(
                message: 'Unexpected result type. Expected ' . TaskResultDto::class . '.'
            );
        }

        return $result;
    }
}
