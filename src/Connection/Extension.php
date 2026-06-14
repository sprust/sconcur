<?php

declare(strict_types=1);

namespace SConcur\Connection;

use SConcur\Dto\RunningTaskDto;
use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\ExtensionCallException;
use SConcur\Exceptions\ExtensionNotLoadedException;
use SConcur\Exceptions\IncompatibleExtensionVersionException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\UnexpectedResponseFormatException;
use SConcur\Features\MethodEnum;
use SConcur\Transport\MessagePackTransport;
use SConcur\Transport\PayloadInterface;
use Throwable;
use function SConcur\Extension\destroy;
use function SConcur\Extension\httpStopAccepting;
use function SConcur\Extension\next;
use function SConcur\Extension\push;
use function SConcur\Extension\stopFlow;
use function SConcur\Extension\tasksCount;
use function SConcur\Extension\version;
use function SConcur\Extension\wait;
use function SConcur\Extension\waitAny;
use function SConcur\Extension\waitAnyTimeout;

class Extension
{
    /**
     * Minimum "sconcur" extension version this package is compatible with. Bump it
     * whenever the PHP <-> Go protocol changes (payload keys, exported functions) so
     * an outdated .so is rejected instead of silently misbehaving.
     */
    private const string REQUIRED_EXTENSION_VERSION = '0.2.0';

    protected static ?Extension $instance = null;

    protected static bool $checked     = false;
    protected static int $tasksCounter = 0;

    private function __construct()
    {
        $this->checkExtension();
    }

    public static function get(): Extension
    {
        return static::$instance ??= new Extension();
    }

    public function push(string $flowKey, PayloadInterface $payload): RunningTaskDto
    {
        ++static::$tasksCounter;

        $taskKey = $flowKey . ':' . static::$tasksCounter;

        $response = push($flowKey, $payload->getMethod()->value, $taskKey, MessagePackTransport::pack($payload));

        static::checkCallResponse(flowKey: $flowKey, response: $response);

        return new RunningTaskDto(
            key: $taskKey,
        );
    }

    public function next(string $flowKey, string $taskKey): RunningTaskDto
    {
        $response = next($flowKey, $taskKey);

        static::checkCallResponse(flowKey: $flowKey, response: $response);

        return new RunningTaskDto(
            key: $taskKey,
        );
    }

    public function wait(string $flowKey): TaskResultDto
    {
        $start = microtime(true);

        $response = wait($flowKey);

        return static::parseWaitResponse(
            response: $response,
            errorContext: sprintf('flow %s', $flowKey),
            start: $start,
        );
    }

    /**
     * Waits for the first ready result of any flow. This is the single global
     * wait point the scheduler uses so flows progress concurrently instead of
     * each one blocking on its own channel.
     */
    public function waitAny(): TaskResultDto
    {
        $start = microtime(true);

        $response = waitAny();

        return static::parseWaitResponse(
            response: $response,
            errorContext: 'waitAny',
            start: $start,
        );
    }

    /**
     * waitAny with a deadline: returns null if no result became ready within
     * $timeoutMs, so a blocking caller (the HTTP serve loop) can wake to check for
     * a shutdown signal even on an idle server.
     */
    public function waitAnyTimeout(int $timeoutMs): ?TaskResultDto
    {
        $start = microtime(true);

        $response = waitAnyTimeout($timeoutMs);

        // Distinct, non-"error:" sentinel the Go side returns on timeout. A real
        // result is msgpack (binary) and an error starts with "error:", so this
        // never collides.
        if ($response === 'timeout') {
            return null;
        }

        return static::parseWaitResponse(
            response: $response,
            errorContext: 'waitAny',
            start: $start,
        );
    }

    public function count(): int
    {
        return tasksCount();
    }

    public function stopFlow(string $flowKey): void
    {
        stopFlow($flowKey);
    }

    /**
     * Stops the HTTP server flow's listener from accepting new connections,
     * without cancelling in-flight requests. Lets a SO_REUSEPORT sibling take over
     * new connections while this process drains on graceful shutdown.
     */
    public function httpStopAccepting(string $flowKey): void
    {
        httpStopAccepting($flowKey);
    }

    public function destroy(): void
    {
        destroy();
    }

    public function version(): string
    {
        return version();
    }

    protected static function parseWaitResponse(string $response, string $errorContext, float $start): TaskResultDto
    {
        if (str_starts_with($response, 'error:')) {
            throw new TaskErrorException(
                message: sprintf(
                    '%s: %s',
                    $errorContext,
                    $response,
                )
            );
        }

        $responseData = MessagePackTransport::unpack($response);

        try {
            return new TaskResultDto(
                flowKey: $responseData['fk'],
                method: MethodEnum::from($responseData['md']),
                key: $responseData['tk'],
                isError: $responseData['er'],
                payload: $responseData['pl'],
                hasNext: $responseData['hn'],
                executionMs: $responseData['ems'],
                totalExecutionMs: (int) ((microtime(true) - $start) * 1000),
            );
        } catch (Throwable $exception) {
            throw new UnexpectedResponseFormatException(
                message: $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    protected static function checkCallResponse(string $flowKey, string $response): void
    {
        if (!str_starts_with($response, 'error:')) {
            return;
        }

        throw new ExtensionCallException(
            message: sprintf(
                'flow %s: %s',
                $flowKey,
                $response,
            )
        );
    }

    private function checkExtension(): void
    {
        if (static::$checked) {
            return;
        }

        if (!extension_loaded('sconcur')) {
            throw new ExtensionNotLoadedException(
                'The extension "sconcur" is not loaded.'
            );
        }

        $loadedVersion = version();

        if (version_compare($loadedVersion, self::REQUIRED_EXTENSION_VERSION, '<')) {
            throw new IncompatibleExtensionVersionException(
                message: sprintf(
                    'The loaded "sconcur" extension version %s is older than the required %s.',
                    $loadedVersion,
                    self::REQUIRED_EXTENSION_VERSION,
                )
            );
        }

        static::$checked = true;
    }

    public function __destruct()
    {
        $this->destroy();
    }
}
