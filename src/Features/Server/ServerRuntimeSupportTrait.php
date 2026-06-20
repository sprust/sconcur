<?php

declare(strict_types=1);

namespace SConcur\Features\Server;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use SConcur\Exceptions\Server\InvalidServerArgumentException;

/**
 * Shared runtime support for the long-lived servers (HttpServer, SocketServer):
 * build constructor overrides from CLI argv, install graceful-shutdown signal
 * handlers, and detect an orphaned worker. Stateless ("lite") — it only adds
 * behaviour, never properties.
 */
trait ServerRuntimeSupportTrait
{
    /**
     * Parses argv into a map of constructor overrides for the using class. Only
     * scalar, single-typed constructor parameters can be set from a CLI string;
     * union/intersection types and closures are skipped. Each "--name=value" is
     * coerced to the parameter's declared type; an unknown name throws.
     *
     * @param array<int, string> $argv
     *
     * @return array<string, int|bool|float|string>
     */
    protected static function parseArgs(array $argv): array
    {
        $allowedTypes = ['int', 'bool', 'float', 'string'];

        $reflectionClass = new ReflectionClass(static::class);

        $parameters = [];

        foreach ($reflectionClass->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            // Only scalar, single-typed params can be set from a CLI string; skip
            // union/intersection types and the closures (onError, ...).
            if (!$type instanceof ReflectionNamedType) {
                continue;
            }

            $typeName = $type->getName();

            if (!in_array($typeName, $allowedTypes, true)) {
                continue;
            }

            $parameters[$parameter->getName()] = $typeName;
        }

        $overrides = [];

        foreach ($argv as $argument) {
            if (!str_starts_with($argument, '--')) {
                continue;
            }

            [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '');

            $type = $parameters[$name] ?? null;

            if ($type === null) {
                throw new InvalidServerArgumentException(
                    sprintf(
                        'Unknown argument: %s. supported only %s',
                        $argument,
                        implode(', ', array_keys($parameters)),
                    ),
                );
            }

            $overrides[$name] = self::coerceArgument(
                name: $name,
                type: $type,
                value: $value,
            );
        }

        return $overrides;
    }

    /**
     * Installs SIGTERM/SIGINT handlers that flip $stopRequested so the serve loop
     * shuts down gracefully, and returns a callback that restores the handlers (and
     * async-signals mode) that were in place before. Requires ext-pcntl; without it
     * the server runs until the process is killed and the restorer is a no-op.
     *
     * @return Closure(): void
     */
    protected function installSignalHandlers(bool &$stopRequested): Closure
    {
        if (!function_exists('pcntl_async_signals')) {
            return static function (): void {
            };
        }

        $signals = [SIGTERM, SIGINT];

        $previousAsync = pcntl_async_signals();

        /** @var array<int, callable|int> $previousHandlers */
        $previousHandlers = [];

        foreach ($signals as $signal) {
            $previousHandlers[$signal] = pcntl_signal_get_handler($signal);
        }

        pcntl_async_signals(true);

        $handler = static function () use (&$stopRequested): void {
            $stopRequested = true;
        };

        foreach ($signals as $signal) {
            pcntl_signal($signal, $handler);
        }

        return static function () use ($signals, $previousHandlers, $previousAsync): void {
            foreach ($signals as $signal) {
                pcntl_signal($signal, $previousHandlers[$signal]);
            }

            pcntl_async_signals($previousAsync);
        };
    }

    /**
     * Whether this worker has been orphaned — its launching master is no longer its
     * parent. Uses posix_getppid() (immune to PID reuse: the kernel reparents the
     * worker once the master dies, so getppid stops matching). Falls back to a
     * signal-0 liveness probe when posix_getppid is unavailable.
     */
    protected static function isOrphaned(int $masterPid): bool
    {
        if (function_exists('posix_getppid')) {
            return posix_getppid() !== $masterPid;
        }

        if (function_exists('posix_kill')) {
            return !@posix_kill($masterPid, 0);
        }

        return false;
    }

    /**
     * Coerces a raw CLI string to the constructor parameter's declared scalar type,
     * throwing on a value that does not represent that type.
     */
    private static function coerceArgument(string $name, string $type, string $value): int|bool|float|string
    {
        if ($type === 'int') {
            if (((string) (int) $value) !== $value) {
                throw new InvalidServerArgumentException(
                    sprintf(
                        'Invalid integer for %s: %s.',
                        $name,
                        $value,
                    ),
                );
            }

            return (int) $value;
        }

        if ($type === 'bool') {
            if ($value === '1') {
                return true;
            }

            if ($value === '0') {
                return false;
            }

            throw new InvalidServerArgumentException(
                sprintf(
                    'Invalid boolean for %s: %s.',
                    $name,
                    $value,
                ),
            );
        }

        if ($type === 'float') {
            if (!is_numeric($value)) {
                throw new InvalidServerArgumentException(
                    sprintf(
                        'Invalid float for %s: %s.',
                        $name,
                        $value,
                    ),
                );
            }

            return (float) $value;
        }

        return $value;
    }
}
