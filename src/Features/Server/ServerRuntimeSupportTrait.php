<?php

declare(strict_types=1);

namespace SConcur\Features\Server;

use Closure;
use DateTimeImmutable;
use ReflectionClass;
use ReflectionNamedType;
use SConcur\Exceptions\Server\InvalidServerArgumentException;
use SConcur\Scheduler\Scheduler;

/**
 * Shared runtime support for the long-lived workers a WorkerMaster supervises — the three
 * servers and the AMQP queue consumer: build constructor overrides from CLI argv, read the
 * telemetry environment, arm automatic preemption and write the lifecycle lines. What
 * tells a worker to stop — a signal, or its master gone — is StopRequest. Stateless — it
 * only adds behaviour, never properties.
 *
 * None of that is about serving a socket, which is why a consumer uses it unchanged; the
 * namespace is where the first user happened to live.
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
                    message: sprintf(
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
     * A telemetry setting from the environment, for a server that left its own null: a
     * worker master puts the collector socket and the pool's name there
     * (SCONCUR_TELEMETRY_SOCKET, SCONCUR_SERVER_NAME); the operator may set the cadence
     * (SCONCUR_TELEMETRY_INTERVAL_MS). Through the environment, so a server built in a
     * worker's role reports to its master without being told to, while a value of its
     * own, an empty socket included, is never overridden.
     */
    protected static function fromEnvironment(string $name, string $default = ''): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * Writes one server lifecycle line to stdout, prefixed with a microsecond ISO
     * timestamp (same shape as the extension's access log), so the startup banner and the
     * graceful-shutdown steps land in the same stream a supervisor already captures.
     */
    protected static function logServerEvent(string $message): void
    {
        fwrite(STDOUT, (new DateTimeImmutable())->format('Y-m-d\TH:i:s.u') . ' ' . $message . PHP_EOL);

        // Flush now: when stdout is a pipe or a file (a worker under a master, a server under
        // a supervisor) it is block buffered, so the startup banner would otherwise not surface until the buffer fills
        // or the process exits.
        fflush(STDOUT);
    }

    /**
     * The descriptor a server's address hands over, or null for one to bind. "fd://N" is
     * a listening socket bound elsewhere, accepted on as it is, so there is nothing for
     * reusePort to set and it is refused beside one rather than silently ignored. The
     * servers ask it in their constructors as well, so a wrong address is refused where
     * the server is built, not in every worker that serves it.
     */
    protected static function listenerDescriptor(string $address, bool $reusePort): ?int
    {
        if (!str_starts_with($address, 'fd://')) {
            return null;
        }

        $descriptor = substr($address, strlen('fd://'));

        if (!ctype_digit($descriptor)) {
            throw new InvalidServerArgumentException(
                message: sprintf(
                    'Invalid address %s: a descriptor is given as fd://<number>.',
                    $address,
                ),
            );
        }

        if ($reusePort) {
            throw new InvalidServerArgumentException(
                message: sprintf(
                    'reusePort does not apply to %s: the socket is bound already.',
                    $address,
                ),
            );
        }

        return (int) $descriptor;
    }

    /**
     * Runs a worker's serving loop with automatic preemption armed, so CPU-bound handler
     * code cannot starve the other coroutines — and so a deadline or a stop can reach a
     * handler that never waits for anything.
     *
     * The servers hand their quantum to Scheduler::serve(), which does this around its own
     * loop. A worker without such a loop — the AMQP queue consumer waits on a WaitGroup —
     * gets the same pairing here instead of arming the timer by hand.
     *
     * @template TReturn
     *
     * @param int                $quantumMs 0 leaves preemption off
     * @param Closure(): TReturn $callback
     *
     * @return TReturn
     */
    protected static function withPreemption(int $quantumMs, Closure $callback): mixed
    {
        if ($quantumMs <= 0) {
            return $callback();
        }

        $scheduler = Scheduler::get();

        $scheduler->enablePreemption(quantumMs: $quantumMs);

        try {
            return $callback();
        } finally {
            $scheduler->disablePreemption();
        }
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
                    message: sprintf(
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
                message: sprintf(
                    'Invalid boolean for %s: %s.',
                    $name,
                    $value,
                ),
            );
        }

        if ($type === 'float') {
            if (!is_numeric($value)) {
                throw new InvalidServerArgumentException(
                    message: sprintf(
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
