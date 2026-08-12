<?php

declare(strict_types=1);

namespace SConcur\Features;

use Fiber;
use SConcur\Connection\Extension;
use SConcur\Dto\PendingNextDto;
use SConcur\Dto\PendingPushDto;
use SConcur\Dto\RunningTaskDto;
use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\OutsideFiberException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\TaskExecutionException;
use SConcur\Exceptions\UnexpectedResultTypeException;
use SConcur\Exceptions\UnexpectedTaskKeyException;
use SConcur\Flow\CurrentFlow;
use SConcur\State;
use SConcur\Transport\PayloadInterface;
use Throwable;

readonly class FeatureExecutor
{
    public static function exec(PayloadInterface $payload): TaskResultDto
    {
        $currentFlow = State::getCurrentFlow();

        // Async path: no cgo from this fiber's stack. The pending task is handed
        // to the resumer (Scheduler::dispatchPendingTask), which performs the push
        // and parks this coroutine until the result arrives via waitAny.
        if ($currentFlow->isAsync) {
            return static::suspend(
                pendingTask: new PendingPushDto(
                    flowKey: $currentFlow->key,
                    payload: $payload,
                ),
            );
        }

        try {
            $runningTask = Extension::get()->push(
                flowKey: $currentFlow->key,
                payload: $payload,
            );
        } catch (Throwable $exception) {
            // A failed push leaves no task to wait for; the one-off flow created
            // for this synchronous call must be stopped right away.
            Extension::get()->stopFlow($currentFlow->key);

            throw new TaskExecutionException(
                message: $exception->getMessage(),
                previous: $exception,
            );
        }

        return static::handleSync(
            currentFlow: $currentFlow,
            runningTask: $runningTask,
            isNext: false,
        );
    }

    /**
     * Fire-and-forget variant of exec(): the task is pushed and the coroutine
     * continues immediately — no result is awaited, and the Go side (told by the
     * payload itself, e.g. RespondPayload::full) publishes none. Only for
     * operations whose outcome the caller cannot observe anyway: the final write
     * of a full HTTP response already fails silently today (the coroutine dies
     * after it, and a groupless spawn drops the failure). Outside a fiber the
     * task is pushed detached directly — falling back to the awaiting exec()
     * would wait forever for a result the payload told Go not to publish.
     */
    public static function execNoResult(PayloadInterface $payload): void
    {
        $currentFlow = State::getCurrentFlow();

        if (!$currentFlow->isAsync) {
            try {
                // Detached push (empty flow key): no flow is created on the Go
                // side and no result will ever come — same contract as the
                // async path below, minus the fiber.
                Extension::get()->push(
                    flowKey: '',
                    payload: $payload,
                );
            } catch (Throwable $exception) {
                throw new TaskExecutionException(
                    message: $exception->getMessage(),
                    previous: $exception,
                );
            }

            return;
        }

        $currentFiber = Fiber::getCurrent();

        if ($currentFiber === null) {
            throw new OutsideFiberException(
                message: 'Can\'t wait outside of fiber.',
            );
        }

        State::markSuspending(spl_object_id($currentFiber));

        try {
            // The dispatcher performs the push off this fiber's stack and resumes
            // the fiber right away (no result mapping is registered). A push
            // failure is thrown back into this suspend, like in suspend().
            Fiber::suspend(new PendingPushDto(
                flowKey: $currentFlow->key,
                payload: $payload,
                awaitResult: false,
            ));
        } catch (Throwable $exception) {
            throw new TaskExecutionException(
                message: $exception->getMessage(),
                previous: $exception,
            );
        } finally {
            State::clearSuspending();
        }
    }

    public static function next(string $taskKey): TaskResultDto
    {
        $currentFlow = State::getCurrentFlow();

        if ($currentFlow->isAsync) {
            return static::suspend(
                pendingTask: new PendingNextDto(
                    flowKey: $currentFlow->key,
                    taskKey: $taskKey,
                ),
            );
        }

        try {
            $runningTask = Extension::get()->next(
                flowKey: $currentFlow->key,
                taskKey: $taskKey,
            );
        } catch (Throwable $exception) {
            Extension::get()->stopFlow($currentFlow->key);

            throw new TaskExecutionException(
                message: $exception->getMessage(),
                previous: $exception,
            );
        }

        return static::handleSync(
            currentFlow: $currentFlow,
            runningTask: $runningTask,
            isNext: true,
        );
    }

    public static function wait(string $flowKey): TaskResultDto
    {
        return Extension::get()->wait(
            flowKey: $flowKey,
        );
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

            throw new TaskExecutionException(
                message: $exception->getMessage(),
                previous: $exception,
            );
        }

        if ($isNext) {
            Extension::get()->stopFlow($currentFlow->key);

            if (!$result->hasNext) {
                State::releaseSyncTaskFlow($runningTask->key);
            }
        } elseif ($result->hasNext) {
            State::registerSyncTaskFlow(
                taskKey: $runningTask->key,
                flowKey: $currentFlow->key,
            );
        } else {
            Extension::get()->stopFlow($currentFlow->key);
        }

        if ($result->key !== $runningTask->key) {
            throw new UnexpectedTaskKeyException(
                message: "Unexpected task key. Expected [$runningTask->key], got [$result->key].",
            );
        }

        return $result;
    }

    protected static function checkResult(TaskResultDto $result): TaskResultDto
    {
        if ($result->isError) {
            throw new TaskErrorException(
                message: $result->payload ?: 'Unknown error',
            );
        }

        return $result;
    }

    /**
     * Async path: hands the pending task to the resumer through Fiber::suspend and
     * parks the coroutine until the scheduler resumes it with the task's result.
     * A push failure is thrown back into this suspend by the dispatcher and
     * surfaces as TaskExecutionException, exactly like any resume-time failure.
     * The task key is unknown here (the push happens on the resuming side); result
     * routing is guaranteed by the owner id the dispatcher sends with the push —
     * the Go side carries it back in the result frame.
     */
    protected static function suspend(PendingPushDto|PendingNextDto $pendingTask): TaskResultDto
    {
        $currentFiber = Fiber::getCurrent();

        if ($currentFiber === null) {
            throw new OutsideFiberException(
                message: 'Can\'t wait outside of fiber.',
            );
        }

        // Guard the suspend transition against automatic preemption: parked
        // between here and the Fiber::suspend below, the coroutine would re-enter
        // this path out of order (see State::markSuspending).
        State::markSuspending(spl_object_id($currentFiber));

        try {
            $result = Fiber::suspend($pendingTask);
        } catch (Throwable $exception) {
            throw new TaskExecutionException(
                message: $exception->getMessage(),
                previous: $exception,
            );
        } finally {
            State::clearSuspending();
        }

        if ($result instanceof TaskResultDto) {
            static::checkResult($result);
        } else {
            throw new UnexpectedResultTypeException(
                message: 'Unexpected result type. Expected ' . TaskResultDto::class . '.',
            );
        }

        return $result;
    }
}
