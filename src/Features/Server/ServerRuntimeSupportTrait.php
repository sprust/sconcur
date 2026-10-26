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
     * Fills the telemetry-related constructor overrides from the environment, unless
     * an explicit --flag already set them: the collector socket, the pool name and
     * the two sampling cadences. Shared by the servers' fromArgs(). Via env (not
     * argv) so the server-agnostic WorkerMaster can supply telemetry config without
     * feeding a non-matching worker an unknown --flag. The master injects
     * SCONCUR_TELEMETRY_SOCKET/SCONCUR_SERVER_NAME from its runtimeDir/name; the
     * operator may set the cadences.
     *
     * @param array<string, int|bool|float|string> $overrides
     *
     * @return array<string, int|bool|float|string>
     */
    protected static function applyTelemetryEnvironment(array $overrides): array
    {
        $stringDefaults = [
            'telemetrySocket' => 'SCONCUR_TELEMETRY_SOCKET',
            'serverName'      => 'SCONCUR_SERVER_NAME',
        ];

        foreach ($stringDefaults as $parameter => $environmentName) {
            if (isset($overrides[$parameter])) {
                continue;
            }

            $environmentValue = getenv($environmentName);

            if (is_string($environmentValue) && $environmentValue !== '') {
                $overrides[$parameter] = $environmentValue;
            }
        }

        $integerDefaults = [
            'telemetryIntervalMs' => 'SCONCUR_TELEMETRY_INTERVAL_MS',
        ];

        foreach ($integerDefaults as $parameter => $environmentName) {
            if (isset($overrides[$parameter])) {
                continue;
            }

            $environmentValue = getenv($environmentName);

            if (is_string($environmentValue) && $environmentValue !== '') {
                $overrides[$parameter] = (int) $environmentValue;
            }
        }

        return $overrides;
    }

    /**
     * Writes one server lifecycle line to stdout, prefixed with a microsecond ISO
     * timestamp (same shape as the extension's access log), so the startup banner and the
     * graceful-shutdown steps land in the same stream a supervisor already captures.
     */
    protected static function logServerEvent(string $message): void
    {
        fwrite(STDOUT, (new DateTimeImmutable())->format('Y-m-d\TH:i:s.u') . ' ' . $message . PHP_EOL);

        // Flush now: when stdout is redirected to a file (a supervised worker) it is block
        // buffered, so the startup banner would otherwise not surface until the buffer fills
        // or the process exits.
        fflush(STDOUT);
    }

    /**
     * The listener as a server's startup line names it: the address it binds, or the
     * descriptor it accepts on when it was handed one.
     */
    protected static function describeListener(string $address, ?int $listenFd): string
    {
        return $listenFd === null ? $address : 'fd ' . $listenFd;
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
