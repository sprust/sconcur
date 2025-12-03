<?php

declare(strict_types=1);

namespace SConcur\Connection;

use Psr\Log\LoggerInterface;
use RuntimeException;
use SConcur\Contracts\ServerConnectorInterface;
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

class ServerConnector implements ServerConnectorInterface
{
    protected static bool $connected = false;
    protected static int $tasksCounter = 0;

    public function __construct(protected LoggerInterface $logger)
    {
    }

    public function connect(Context $context): void
    {
        if (self::$connected) {
            return;
        }

        if (!extension_loaded('sconcur')) {
            throw new RuntimeException(
                'The extension "sconcur" is not loaded.'
            );
        }

        self::$connected = true;
    }

    public function disconnect(): void
    {
    }

    public function isConnected(): bool
    {
        return self::$connected;
    }

    public function write(Context $context, MethodEnum $method, string $payload): RunningTaskDto
    {
        ++self::$tasksCounter;

        $taskKey = self::$tasksCounter . ':' . microtime(true);

        push($method->value, $taskKey, $payload);

        return new RunningTaskDto(
            key: $taskKey,
        );
    }

    /**
     * @throws UnexpectedResponseFormatException
     * @throws TaskErrorException
     * @throws ResponseIsNotJsonException
     */
    public function read(Context $context): TaskResultDto
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

    public function __destruct()
    {
        $this->disconnect();
    }
}
