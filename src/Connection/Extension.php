<?php

declare(strict_types=1);

namespace SConcur\Connection;

use RuntimeException;
use SConcur\Dto\RunningTaskDto;
use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\UnexpectedResponseFormatException;
use SConcur\Features\MethodEnum;
use SConcur\Transport\MessagePackTransport;
use Throwable;
use function SConcur\Extension\count;
use function SConcur\Extension\destroy;
use function SConcur\Extension\pushBin;
use function SConcur\Extension\next;
use function SConcur\Extension\stopFlow;
use function SConcur\Extension\version;
use function SConcur\Extension\waitBin;

class Extension
{
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

    public function push(string $flowKey, MethodEnum $method, string $payload): RunningTaskDto
    {
        ++static::$tasksCounter;

        $taskKey = $flowKey . ':' . static::$tasksCounter;

        pushBin($flowKey, $method->value, $taskKey, $payload);

        return new RunningTaskDto(
            key: $taskKey,
        );
    }

    public function next(string $flowKey, string $taskKey): RunningTaskDto
    {
        next($flowKey, $taskKey);

        return new RunningTaskDto(
            key: $taskKey,
        );
    }

    public function wait(string $flowKey): TaskResultDto
    {
        $start = microtime(true);

        $response = waitBin($flowKey);

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
        return count();
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

    private function checkExtension(): void
    {
        if (static::$checked) {
            return;
        }

        if (!extension_loaded('sconcur')) {
            throw new RuntimeException(
                'The extension "sconcur" is not loaded.'
            );
        }

        static::$checked = true;
    }

    public function __destruct()
    {
        $this->destroy();
    }
}
