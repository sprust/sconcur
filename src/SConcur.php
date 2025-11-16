<?php

declare(strict_types=1);

namespace SConcur;

use Closure;
use Fiber;
use Generator;
use LogicException;
use Psr\Log\LoggerInterface;
use SConcur\Connection\ServerConnector;
use SConcur\Contracts\FlowInterface;
use SConcur\Contracts\ParametersResolverInterface;
use SConcur\Dto\FeatureResultDto;
use SConcur\Dto\TaskResultDto;
use SConcur\Entities\Context;
use SConcur\Entities\Timer;
use SConcur\Exceptions\AlreadyRunningException;
use SConcur\Exceptions\ContextCheckerException;
use SConcur\Exceptions\ContinueException;
use SConcur\Exceptions\FeatureResultNotFoundException;
use SConcur\Exceptions\FiberNotFoundByTaskKeyException;
use SConcur\Exceptions\InvalidValueException;
use SConcur\Exceptions\ResumeException;
use SConcur\Exceptions\StartException;
use SConcur\Flow\AsyncFlow;
use Throwable;

class SConcur
{
    protected static bool $initialized = false;

    protected static ?FlowInterface $currentAsyncFlow = null;
    protected static ?FlowInterface $currentSyncFlow = null;

    protected static ?TaskResultDto $currentAsyncResult = null;

    protected static array $socketAddresses;
    protected static ParametersResolverInterface $parametersResolver;
    protected static LoggerInterface $logger;

    private function __construct()
    {
    }

    /**
     * @param array<string> $socketAddresses
     */
    public static function init(
        array $socketAddresses,
        ParametersResolverInterface $parametersResolver,
        LoggerInterface $logger,
    ): void {
        static::$socketAddresses    = $socketAddresses;
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
     * @throws FeatureResultNotFoundException
     * @throws ContinueException
     */
    public static function waitResult(Context $context, string $taskKey): TaskResultDto
    {
        static::checkInitialization();

        if (self::$currentAsyncFlow === null) {
            return self::$currentSyncFlow->waitResult($context);
        }

        static::wait($taskKey);

        if (static::$currentAsyncResult?->key === $taskKey) {
            $currentResult = static::$currentAsyncResult;

            static::$currentAsyncResult = null;

            return $currentResult;
        }

        throw new FeatureResultNotFoundException(
            taskKey: $taskKey,
        );
    }

    /**
     * @return Generator<int|string, FeatureResultDto>
     *
     * @throws AlreadyRunningException
     * @throws ContextCheckerException
     * @throws InvalidValueException
     * @throws ResumeException
     * @throws StartException
     * @throws FiberNotFoundByTaskKeyException
     */
    public static function run(
        array &$callbacks,
        int $timeoutSeconds,
        ?int $limitCount = null,
        ?Context $context = null
    ): Generator {
        static::checkInitialization();

        if (static::$currentAsyncFlow !== null) {
            throw new AlreadyRunningException();
        }

        try {
            $limitCount ??= 0;

            if (is_null($context)) {
                $context = new Context();
            }

            if ($timeoutSeconds && !$context->hasChecker(Timer::class)) {
                $context->setChecker(
                    new Timer(timeoutSeconds: $timeoutSeconds)
                );
            }

            $flow = static::createAsyncFlow();

            /** @var array<string, array{fk: string, fi: Fiber}> $fibersByTaskKey */
            $fibersByTaskKey = [];

            $fibers = array_map(
                static fn(Closure $callback) => new Fiber($callback),
                $callbacks
            );

            while (count($fibers) > 0) {
                $context->check();

                if ($limitCount > 0 && count($fibers) >= $limitCount) {
                    $fiberKeys = array_keys(array_slice($fibers, 0, $limitCount, true));
                } else {
                    $fiberKeys = array_keys($fibers);
                }

                foreach ($fiberKeys as $fiberKey) {
                    $context->check();

                    $fiberData = $fibers[$fiberKey];

                    if (!$fiberData->isStarted()) {
                        $parameters = static::$parametersResolver->make(
                            context: $context,
                            callback: $callbacks[$fiberKey]
                        );

                        unset($callbacks[$fiberKey]);

                        try {
                            $taskKey = $fiberData->start(...$parameters);
                        } catch (Throwable $exception) {
                            throw new StartException(
                                message: $exception->getMessage(),
                                previous: $exception
                            );
                        }

                        $fibersByTaskKey[$taskKey] = [
                            'fk' => $fiberKey,
                            'fi' => $fiberData,
                        ];
                    }
                }

                $taskResult = $flow->waitResult(
                    context: $context,
                );

                $taskKey = $taskResult->key;

                $fiberData = $fibersByTaskKey[$taskKey] ?? null;

                if (!$fiberData) {
                    throw new FiberNotFoundByTaskKeyException(
                        taskKey: $taskKey
                    );
                }

                /** @var string $fiberKey */
                $fiberKey = $fiberData['fk'];

                /** @var Fiber $fiber */
                $fiber = $fiberData['fi'];

                if (!$fiber->isSuspended()) {
                    throw new LogicException(
                        message: "Fiber with task key [$taskKey] is not suspended"
                    );
                }

                static::$currentAsyncResult = $taskResult;

                try {
                    $fiber->resume();
                } catch (Throwable $exception) {
                    throw new ResumeException(
                        message: $exception->getMessage(),
                        previous: $exception
                    );
                }

                static::$currentAsyncResult = null;

                if ($fiber->isTerminated()) {
                    $result = $fiber->getReturn();

                    unset($fibers[$fiberKey]);
                    unset($fibersByTaskKey[$taskKey]);

                    yield new FeatureResultDto(
                        key: $fiberKey,
                        result: $result
                    );
                }
            }
        } finally {
            self::deleteAsyncFlow();
        }
    }

    /**
     * @throws ContinueException
     */
    protected static function wait(string $taskKey): void
    {
        if (!Fiber::getCurrent()) {
            throw new ContinueException(
                message: "Can't wait outside of fiber."
            );
        }

        try {
            Fiber::suspend($taskKey);
        } catch (Throwable $exception) {
            throw new ContinueException(
                previous: $exception
            );
        }
    }

    protected static function createAsyncFlow(): FlowInterface
    {
        return static::$currentAsyncFlow = new AsyncFlow(
            serverConnector: new ServerConnector(
                socketAddresses: static::$socketAddresses,
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
                socketAddresses: static::$socketAddresses,
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
