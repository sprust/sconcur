<?php

declare(strict_types=1);

namespace SConcur\Connection;

use RuntimeException;
use SConcur\Dto\RunningTaskDto;
use SConcur\Dto\TaskResultDto;
use SConcur\Entities\Context;
use SConcur\Exceptions\ResponseIsNotJsonException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\UnexpectedResponseFormatException;
use SConcur\Features\MethodEnum;
use Throwable;
use function SConcur\Extension\count;
use function SConcur\Extension\destroy;
use function SConcur\Extension\push;
use function SConcur\Extension\stopFlow;
use function SConcur\Extension\wait;

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

        push($flowKey, $method->value, $taskKey, $payload);

        return new RunningTaskDto(
            key: $taskKey,
        );
    }

    public function wait(Context $context, string $flowKey, bool $isAsync): TaskResultDto
    {
        $response = wait($flowKey, $context->getRemainMs());

        if (str_starts_with($response, 'error:')) {
            $isAsyncView = $isAsync ? 'async' : 'sync';

            throw new TaskErrorException(
                message: sprintf(
                    'flow %s [%s]: %s',
                    $flowKey,
                    $isAsyncView,
                    $response,
                )
            );
        }

        try {
            $responseData = json_decode(
                json: $response,
                associative: true,
                flags: JSON_THROW_ON_ERROR
            );
        } catch (Throwable $exception) {
            throw new ResponseIsNotJsonException(
                message: $exception->getMessage(),
            );
        }

        try {
            return new TaskResultDto(
                flowKey: $responseData['fk'],
                method: MethodEnum::from($responseData['md']),
                key: $responseData['tk'],
                isError: $responseData['er'],
                payload: $responseData['pl'],
                hasNext: $responseData['hn'],
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
