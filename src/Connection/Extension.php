<?php

declare(strict_types=1);

namespace SConcur\Connection;

use Psr\Log\LoggerInterface;
use RuntimeException;
use SConcur\Dto\RunningTaskDto;
use SConcur\Dto\TaskResultDto;
use SConcur\Entities\Context;
use SConcur\Exceptions\ResponseIsNotJsonException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\UnexpectedResponseFormatException;
use SConcur\Features\MethodEnum;
use Throwable;

use function SConcur\Extension\push;
use function SConcur\Extension\wait;
use function SConcur\Extension\cancel;
use function SConcur\Extension\stop;

class Extension
{
    protected static bool $checked = false;
    protected static int $tasksCounter = 0;

    public function __construct(protected LoggerInterface $logger)
    {
        $this->checkExtension();
    }

    public function push(MethodEnum $method, string $payload): RunningTaskDto
    {
        ++static::$tasksCounter;

        $taskKey = (string) static::$tasksCounter;

        push($method->value, $taskKey, $payload);

        return new RunningTaskDto(
            key: $taskKey,
        );
    }

    public function wait(Context $context): TaskResultDto
    {
        $response = wait($context->getRemainMs());

        if (str_starts_with($response, 'error:')) {
            throw new TaskErrorException(
                message: $response
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

    public function cancel(string $taskKey): void
    {
        cancel($taskKey);
    }

    public function stop(): void
    {
        stop();
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
}
