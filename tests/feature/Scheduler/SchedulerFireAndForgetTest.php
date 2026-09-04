<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Scheduler;

use Fiber;
use SConcur\Exceptions\FlowStoppedException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\TaskExecutionException;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\HttpServer\Payloads\RespondPayload;
use SConcur\Features\Sleeper\Payloads\SleeperPayload;
use SConcur\Flow\CurrentFlow;
use SConcur\Scheduler\Scheduler;
use SConcur\State;
use SConcur\Tests\Feature\BaseTestCase;
use Throwable;

/**
 * The fire-and-forget push (FeatureExecutor::execNoResult): the coroutine hands a
 * task to Go and continues without awaiting a result — none is ever published
 * for it. Used for the final write of a full HTTP response.
 */
class SchedulerFireAndForgetTest extends BaseTestCase
{
    /** Captured by the fiber driven in parkedOnFireAndForget(). */
    protected ?Throwable $caught = null;

    protected bool $finallyRan = false;

    public function testCoroutineContinuesAndFinishesWithoutAwaitingAResult(): void
    {
        $reachedTheEnd = false;

        Scheduler::get()->spawn(static function () use (&$reachedTheEnd): void {
            FeatureExecutor::execNoResult(payload: self::unroutableRespond());

            $reachedTheEnd = true;
        });

        // No scheduler pumping needed: the dispatcher resumes the coroutine inline
        // right after the push, so the handler is already done when spawn() returns.
        self::assertTrue($reachedTheEnd);
    }

    public function testSeveralFireAndForgetPushesInOneCoroutine(): void
    {
        $pushes = 0;

        Scheduler::get()->spawn(static function () use (&$pushes): void {
            for ($index = 0; $index < 5; ++$index) {
                FeatureExecutor::execNoResult(payload: self::unroutableRespond());

                ++$pushes;
            }
        });

        self::assertSame(5, $pushes);
    }

    /**
     * A detached task runs synchronously on the PHP thread inside the push crossing
     * call, so a blocking handler would freeze the whole worker. Only the HTTP
     * respond is allow-listed on the extension; anything else must be refused
     * loudly rather than silently accepted.
     */
    public function testANonDetachableMethodIsRejected(): void
    {
        $caught = null;

        Scheduler::get()->spawn(static function () use (&$caught): void {
            try {
                FeatureExecutor::execNoResult(
                    payload: new SleeperPayload(microseconds: 1_000),
                );
            } catch (Throwable $exception) {
                $caught = $exception;
            }
        });

        self::assertInstanceOf(TaskExecutionException::class, $caught);
        self::assertStringContainsString('detached', $caught->getMessage());
    }

    /**
     * A deliberate unwind is not a task failure: it must reach the coroutine as
     * FlowStoppedException so its finally blocks run and the cancellation stays
     * recognizable. Driven at the fiber level because the window only exists
     * between the suspend and the dispatch, which no higher-level API exposes.
     */
    public function testFlowStoppedPropagatesUnwrapped(): void
    {
        [$fiber, $fiberId] = $this->parkedOnFireAndForget();

        $fiber->throw(new FlowStoppedException(message: 'Flow stopped'));

        State::unRegisterFiber($fiberId);

        self::assertInstanceOf(FlowStoppedException::class, $this->caught);
        self::assertTrue($this->finallyRan);
    }

    /**
     * The mirror case: any other failure thrown into that suspend is a task
     * failure and keeps its TaskExecutionException wrapping, original attached.
     */
    public function testOtherFailuresAreWrappedAsTaskExecution(): void
    {
        [$fiber, $fiberId] = $this->parkedOnFireAndForget();

        $fiber->throw(new TaskErrorException(message: 'push failed'));

        State::unRegisterFiber($fiberId);

        self::assertInstanceOf(TaskExecutionException::class, $this->caught);
        self::assertInstanceOf(TaskErrorException::class, $this->caught->getPrevious());
    }

    /**
     * The respond payload used throughout: its request id resolves to nothing, so
     * the extension logs the failure and drops it. That is exactly the contract
     * under test — PHP must neither wait for the outcome nor learn about it.
     */
    protected static function unroutableRespond(): RespondPayload
    {
        return RespondPayload::full(
            requestId: 'no-such-request',
            status: 200,
            headers: [],
            body: 'ok',
        );
    }

    /**
     * Starts a coroutine parked exactly on the execNoResult suspend with no
     * dispatcher behind it, so the test can throw into that suspend point.
     *
     * @return array{0: Fiber, 1: int}
     */
    protected function parkedOnFireAndForget(): array
    {
        $fiber = new Fiber(function (): void {
            try {
                FeatureExecutor::execNoResult(payload: self::unroutableRespond());
            } catch (Throwable $exception) {
                $this->caught = $exception;
            } finally {
                $this->finallyRan = true;
            }
        });

        $fiberId = spl_object_id($fiber);

        State::registerFiberFlow(
            fiberId: $fiberId,
            flow: new CurrentFlow(
                isAsync: true,
                key: 'fire-and-forget-test',
            ),
        );

        $fiber->start();

        self::assertTrue($fiber->isSuspended(), 'the coroutine must park on the pending push');

        return [$fiber, $fiberId];
    }
}
