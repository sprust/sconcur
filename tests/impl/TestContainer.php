<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

use Closure;
use Dotenv\Dotenv;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use SConcur\Connection\ServerConnector;
use SConcur\Contracts\ParametersResolverInterface;
use SConcur\Contracts\ServerConnectorInterface;
use SConcur\SConcur;

class TestContainer implements ContainerInterface
{
    private static ?TestContainer $container = null;

    /**
     * @var array<class-string<object>, Closure(): object>
     */
    private array $resolvers;

    /**
     * @var array<class-string, object>
     */
    private static array $cache = [];

    public static function resolve(): TestContainer
    {
        return self::$container ??= new TestContainer();
    }

    public static function flush(): void
    {
        self::$container = null;
    }

    private function __construct()
    {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../../', '.env');
        $dotenv->load();

        $this->resolvers = [
            ContainerInterface::class => fn() => $this,

            ParametersResolverInterface::class => fn() => $this->get(TestParametersResolver::class),

            ServerConnectorInterface::class => function () {
                return new ServerConnector(
                    logger: $this->get(LoggerInterface::class),
                );
            },

            LoggerInterface::class => fn() => $this->get(TestLogger::class),
        ];

        SConcur::init(
            parametersResolver: $this->get(ParametersResolverInterface::class),
            logger: $this->get(LoggerInterface::class),
        );
    }

    /**
     * @template TClass
     *
     * @param class-string<TClass> $id
     *
     * @return TClass
     */
    public function get(string $id)
    {
        if ($this->has($id)) {
            return self::$cache[$id] ??= $this->resolvers[$id]();
        }

        if (interface_exists($id)) {
            throw new RuntimeException("No entry found for $id");
        }

        if (!class_exists($id)) {
            throw new RuntimeException("Class [$id] not found");
        }

        try {
            $reflection = new ReflectionClass($id);

            if (!$reflection->isInstantiable()) {
                throw new RuntimeException("Class [$id] is not instantiable");
            }

            $constructor = $reflection->getConstructor();

            if (is_null($constructor) || $constructor->getNumberOfParameters() === 0) {
                return self::$cache[$id] ??= $reflection->newInstance();
            }

            $params = [];

            foreach ($constructor->getParameters() as $param) {
                $type = $param->getType();

                $isDefaultValueAvailable = $param->isDefaultValueAvailable();

                /** @phpstan-ignore-next-line method.notFound */
                if ($type && !$isDefaultValueAvailable && !$type->isBuiltin()) {
                    /** @phpstan-ignore-next-line method.notFound */
                    $params[] = $this->get($type->getName());
                } elseif ($isDefaultValueAvailable) {
                    $params[] = $param->getDefaultValue();
                } else {
                    throw new RuntimeException("Cannot resolve parameter \${$param->getName()} for [$id]");
                }
            }

            return self::$cache[$id] ??= $reflection->newInstanceArgs($params);
        } catch (ReflectionException $exception) {
            throw new RuntimeException(
                message: "Failed to instantiate [$id]: " . $exception->getMessage(),
                previous: $exception
            );
        }
    }

    /**
     * @param class-string<object> $id
     */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->resolvers);
    }

    /**
     * @template TClass
     *
     * @param class-string<object> $id
     * @param Closure(): TClass    $resolver
     */
    public function set(string $id, Closure $resolver): void
    {
        $this->resolvers[$id] = $resolver;

        unset(self::$cache[$id]);
    }
}
