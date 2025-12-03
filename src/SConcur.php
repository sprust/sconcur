<?php

declare(strict_types=1);

namespace SConcur;

use Closure;
use Fiber;
use Generator;
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SConcur\Connection\ServerConnector;
use SConcur\Contracts\FlowInterface;
use SConcur\Contracts\ParametersResolverInterface;
use SConcur\Dto\FeatureResultDto;
use SConcur\Dto\TaskResultDto;
use SConcur\Entities\Context;
use SConcur\Exceptions\AlreadyRunningException;
use SConcur\Exceptions\InvalidValueException;
use SConcur\Exceptions\TimeoutException;
use SConcur\Flow\AsyncFlow;
use Throwable;

class SConcur
{
    protected static bool $initialized = false;

    protected static ?FlowInterface $currentAsyncFlow = null;
    protected static ?FlowInterface $currentSyncFlow = null;

    protected static ?TaskResultDto $currentAsyncResult = null;

    protected static ParametersResolverInterface $parametersResolver;
    protected static LoggerInterface $logger;

    private function __construct()
    {
    }

    public static function init(
        ParametersResolverInterface $parametersResolver,
        LoggerInterface $logger,
    ): void {
        static::$parametersResolver = $parametersResolver;
        static::$logger             = $logger;

        static::$initialized = true;
    }

    public static function getCurrentFlow(): FlowInterface
    {
        static::checkInitialization();

        if (static::$currentAsyncFlow !== null) {
            return static::$currentAsyncFlow;
        } else {
            return static::initSyncFlow();
        }
    }

    /**
     * @param array<mixed, Closure> &$callbacks
     *
     * @return Generator<mixed, FeatureResultDto>
     *
     * @throws AlreadyRunningException
     * @throws TimeoutException
     * @throws InvalidValueException
     */
    public static function run(
        array &$callbacks,
        int $timeoutSeconds,
        ?int $limitCount = null,
    ): Generator {
        static::checkInitialization();

        if (static::$currentAsyncFlow !== null) {
            throw new AlreadyRunningException();
        }

        try {
            $limitCount = max(0, $limitCount ?? 0);

            $context = new Context(
                timeoutSeconds: $timeoutSeconds
            );

            $flow = static::createAsyncFlow();

            /** @var array<string, Fiber> $fibers */
            $fibers = [];

            /** @var array<string, Closure> $callbacksKeyByFiberId */
            $callbacksKeyByFiberId = [];

            $callbackKeys = array_keys($callbacks);

            /** @var array<string, mixed> $callbackKeyKeyByFiberId */
            $callbackKeyKeyByFiberId = [];

            foreach ($callbackKeys as $callbackKey) {
                $callback = $callbacks[$callbackKey];

                unset($callbacks[$callbackKey]);

                $fiber = new Fiber($callback);

                $fiberId = spl_object_id($fiber);

                $fibers[$fiberId]                  = $fiber;
                $callbacksKeyByFiberId[$fiberId]   = $callback;
                $callbackKeyKeyByFiberId[$fiberId] = $callbackKey;
            }

            while (count($fibers) > 0) {
                $context->check();

                if ($limitCount > 0 && count($fibers) >= $limitCount) {
                    $fiberKeys = array_keys(array_slice($fibers, 0, $limitCount, true));
                } else {
                    $fiberKeys = array_keys($fibers);
                }

                foreach ($fiberKeys as $fiberKey) {
                    $fiber = $fibers[$fiberKey];

                    if (!$fiber->isStarted()) {
                        $parameters = static::$parametersResolver->make(
                            context: $context,
                            callback: $callbacksKeyByFiberId[$fiberKey]
                        );

                        unset($callbacksKeyByFiberId[$fiberKey]);

                        try {
                            $fiber->start(...$parameters);
                        } catch (Throwable $exception) {
                            throw new RuntimeException(
                                message: $exception->getMessage(),
                                previous: $exception
                            );
                        }
                    }
                }

                $taskResult = $flow->waitResult(
                    context: $context,
                );

                $taskKey = $taskResult->key;

                $foundFiber = $flow->getFiberByTaskUuid($taskKey);

                if (!$foundFiber) {
                    throw new LogicException(
                        message: "Fiber not found by task key [$taskKey]"
                    );
                }

                if (!$foundFiber->isSuspended()) {
                    throw new LogicException(
                        message: "Fiber with task key [$taskKey] is not suspended"
                    );
                }

                static::$currentAsyncResult = $taskResult;

                try {
                    $foundFiber->resume();
                } catch (Throwable $exception) {
                    throw new RuntimeException(
                        message: $exception->getMessage(),
                        previous: $exception
                    );
                }

                static::$currentAsyncResult = null;

                if ($foundFiber->isTerminated()) {
                    $result = $foundFiber->getReturn();

                    $fiberId = spl_object_id($foundFiber);

                    unset($fibers[$fiberId]);
                    $flow->deleteFiberByTaskUuid($taskKey);

                    $callbackKey = $callbackKeyKeyByFiberId[$fiberId];

                    unset($callbackKeyKeyByFiberId[$fiberId]);

                    yield new FeatureResultDto(
                        key: $callbackKey,
                        result: $result
                    );
                }
            }
        } finally {
            self::deleteAsyncFlow();
        }
    }

    protected static function wait(): void
    {
        if (!Fiber::getCurrent()) {
            throw new LogicException(
                message: "Can't wait outside of fiber."
            );
        }

        try {
            Fiber::suspend();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                message: $exception->getMessage(),
                previous: $exception
            );
        }
    }

    public static function waitResult(Context $context, string $taskKey): TaskResultDto
    {
        static::checkInitialization();

        if (self::$currentAsyncFlow === null) {
            return self::$currentSyncFlow->waitResult($context);
        }

        static::wait();

        if (static::$currentAsyncResult?->key === $taskKey) {
            $currentResult = static::$currentAsyncResult;

            static::$currentAsyncResult = null;

            return $currentResult;
        }

        throw new LogicException(
            message: "Feature result not found for task key [$taskKey]",
        );
    }

    protected static function createAsyncFlow(): FlowInterface
    {
        return static::$currentAsyncFlow = new AsyncFlow(
            serverConnector: new ServerConnector(
                logger: static::$logger,
            ),
        );
    }

    protected static function deleteAsyncFlow(): void
    {
        static::$currentAsyncFlow?->close();
        static::$currentAsyncFlow = null;
    }

    protected static function initSyncFlow(): FlowInterface
    {
        return static::$currentSyncFlow ??= new AsyncFlow(
            serverConnector: new ServerConnector(
                logger: static::$logger,
            ),
        );
    }

    protected static function checkInitialization(): void
    {
        if (!static::$initialized) {
            throw new LogicException(
                'SConcur is not initialized'
            );
        }
    }
}
