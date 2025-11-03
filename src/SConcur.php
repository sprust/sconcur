<?php

declare(strict_types=1);

namespace SConcur;

use Closure;
use Fiber;
use Generator;
use LogicException;
use Psr\Container\ContainerInterface;
use SConcur\Contracts\ParametersResolverInterface;
use SConcur\Contracts\ServerConnectorInterface;
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
use Throwable;

class SConcur
{
    protected static bool $initialized = false;
    protected static bool $connected = false;
    protected static bool $running = false;

    protected static string $flowUuid;

    protected static ContainerInterface $container;

    protected static ?ServerConnectorInterface $serverConnector = null;
    protected static ?ParametersResolverInterface $parametersResolver = null;

    protected static ?TaskResultDto $currentResult = null;

    private function __construct()
    {
    }

    public static function init(ContainerInterface $container): void
    {
        static::$container = $container;

        static::$initialized = true;
    }

    public static function isConcurrency(): bool
    {
        return static::$initialized && static::$running && static::$connected;
    }

    public static function getServerConnector(): ServerConnectorInterface
    {
        static::checkInitialization();

        return static::$serverConnector
            ??= static::$container->get(ServerConnectorInterface::class);
    }

    public static function getParametersResolver(): ParametersResolverInterface
    {
        static::checkInitialization();

        return static::$parametersResolver
            ??= static::$container->get(ParametersResolverInterface::class);
    }

    public static function getFlowUuid(): string
    {
        return self::$flowUuid;
    }

    /**
     * @throws FeatureResultNotFoundException
     */
    public static function detectResult(string $taskKey): TaskResultDto
    {
        static::checkInitialization();

        if (static::$currentResult?->key === $taskKey) {
            $currentResult = static::$currentResult;

            static::$currentResult = null;

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

        if (Fiber::getCurrent()) {
            throw new AlreadyRunningException(
                'Running inside fiber is not allowed.',
            );
        }

        if (static::$running) {
            throw new AlreadyRunningException(
                'Concurrency is already running',
            );
        }

        static::$running  = true;
        static::$flowUuid = uniqid(more_entropy: true);

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

            $serverConnector    = static::getServerConnector();
            $parametersResolver = static::getParametersResolver();

            $serverConnector->connect(
                context: $context,
                waitHandshake: true
            );

            if (!$serverConnector->isConnected()) {
                static::$connected = false;

                $callbackKeys = array_keys($callbacks);

                foreach ($callbackKeys as $callbackKey) {
                    $context->check();

                    $callback = $callbacks[$callbackKey];

                    unset($callbacks[$callbackKey]);

                    $parameters = $parametersResolver->make(
                        context: $context,
                        callback: $callback
                    );

                    $result = $callback(...$parameters);

                    yield new FeatureResultDto(
                        key: $callbackKey,
                        result: $result,
                    );
                }

                return;
            }

            static::$connected = true;

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
                        $parameters = $parametersResolver->make(
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

                while (true) {
                    $taskResult = $serverConnector->read(
                        context: $context,
                    );

                    if ($taskResult === null) {
                        continue;
                    }

                    break;
                }

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

                static::$currentResult = $taskResult;

                try {
                    $fiber->resume();
                } catch (Throwable $exception) {
                    throw new ResumeException(
                        message: $exception->getMessage(),
                        previous: $exception
                    );
                }

                static::$currentResult = null;

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
            static::$running   = false;
            static::$connected = false;

            static::$serverConnector?->disconnect();
        }
    }

    /**
     * @throws ContinueException
     */
    public static function wait(string $taskKey): void
    {
        static::checkInitialization();

        if (!Fiber::getCurrent()) {
            return;
        }

        try {
            Fiber::suspend($taskKey);
        } catch (Throwable $exception) {
            throw new ContinueException(
                previous: $exception
            );
        }
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
