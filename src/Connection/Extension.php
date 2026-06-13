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
use function SConcur\Extension\next;
use function SConcur\Extension\push;
use function SConcur\Extension\stopFlow;
use function SConcur\Extension\tasksCount;
use function SConcur\Extension\version;
use function SConcur\Extension\wait;

class Extension
{
    /**
     * Minimum "sconcur" extension version this package is compatible with. Bump it
     * whenever the PHP <-> Go protocol changes (payload keys, exported functions) so
     * an outdated .so is rejected instead of silently misbehaving.
     */
    private const string REQUIRED_EXTENSION_VERSION = '0.0.1';

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

        if (str_starts_with($response, 'error:')) {
            throw new TaskErrorException(
                message: sprintf(
                    'flow %s: %s',
                    $flowKey,
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

    public function count(): int
    {
        return tasksCount();
    }

    public function stopFlow(string $flowKey): void
    {
        stopFlow($flowKey);
    }

    public function destroy(): void
    {
        destroy();
    }

    public function version(): string
    {
        return version();
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
