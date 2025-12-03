<?php

declare(strict_types=1);

namespace SConcur;

use Closure;
use Fiber;
use Generator;
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SConcur\Connection\Extension;
use SConcur\Contracts\ParametersResolverInterface;
use SConcur\Dto\FeatureResultDto;
use SConcur\Entities\Context;
use SConcur\Exceptions\AlreadyRunningException;
use SConcur\Flow\Flow;
use Throwable;

class SConcur
{
    protected static bool $initialized = false;

    protected static ?Flow $asyncFlow = null;
    protected static ?Flow $syncFlow = null;

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

    public static function getCurrentFlow(): Flow
    {
        static::checkInitialization();

        if (static::$asyncFlow !== null) {
            return static::$asyncFlow;
        } else {
            return static::initSyncFlow();
        }
    }

    /**
     * @param Generator<mixed, FeatureResultDto> $results
     */
    public static function wait(Generator $results): array
    {
        $result = [];

        foreach ($results as $featureResultDto) {
            $result[$featureResultDto->key] = $featureResultDto->result;
        }

        return $result;
    }

    /**
     * @param array<mixed, Closure> &$callbacks
     *
     * @return Generator<mixed, FeatureResultDto>
     */
    public static function run(
        array &$callbacks,
        int $timeoutSeconds,
        ?int $limitCount = null,
    ): Generator {
        static::checkInitialization();

        if (static::$asyncFlow !== null) {
            throw new AlreadyRunningException();
        }

        try {
            $limitCount = max(0, $limitCount ?? 0);

            $context = Context::create($timeoutSeconds);

            $flow = static::initAsyncFlow();

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

                $taskResult = $flow->wait(
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

                try {
                    $foundFiber->resume($taskResult);
                } catch (Throwable $exception) {
                    throw new RuntimeException(
                        message: $exception->getMessage(),
                        previous: $exception
                    );
                }

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

    protected static function initAsyncFlow(): Flow
    {
        return static::$asyncFlow = new Flow(
            extension: new Extension(
                logger: static::$logger,
            ),
            isAsync: true
        );
    }

    protected static function deleteAsyncFlow(): void
    {
        static::$asyncFlow?->close();
        static::$asyncFlow = null;
    }

    protected static function initSyncFlow(): Flow
    {
        return static::$syncFlow ??= new Flow(
            extension: new Extension(
                logger: static::$logger,
            ),
            isAsync: false,
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
