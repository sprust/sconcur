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
use function SConcur\Extension\socketStopAccepting;
use function SConcur\Extension\stopFlow;
use function SConcur\Extension\tasksCount;
use function SConcur\Extension\version;
use function SConcur\Extension\wait;
use function SConcur\Extension\waitAny;
use function SConcur\Extension\waitAnyTimeout;
use function SConcur\Extension\wsStopAccepting;

class Extension
{
    /**
     * The exact "sconcur" extension version this package is built against. The PHP
     * package and the Go extension are versioned and released together, so the loaded
     * .so must match this exactly (see checkExtension); bump it whenever the PHP <-> Go
     * protocol changes (payload keys, exported functions) so a mismatched .so is
     * rejected instead of silently misbehaving. Public so tooling (bin/sconcur-status)
     * can report the version the package expects.
     */
    public const string REQUIRED_EXTENSION_VERSION = '0.7.0';

    /**
     * Result frame layout (Go -> PHP), see main.go buildResultFrame. The envelope is
     * a fixed binary header, not MessagePack; only the feature payload stays
     * MessagePack and is decoded once by the feature. Header: flags(1) +
     * methodLen(1) + execMs(uint32) + flowKeyLen(uint16) + taskKeyLen(uint16), then
     * method, flowKey, taskKey and the raw payload (the rest).
     */
    private const int FRAME_HEADER_SIZE   = 10;
    private const int FRAME_FLAG_ERROR    = 1 << 0;
    private const int FRAME_FLAG_HAS_NEXT = 1 << 1;

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

    /**
     * Stops the socket server flow's listener from accepting new connections and
     * half-closes its in-flight connections, so a SO_REUSEPORT sibling takes over
     * new connections while this process drains on graceful shutdown.
     */
    public function socketStopAccepting(string $flowKey): void
    {
        socketStopAccepting($flowKey);
    }

    /**
     * Stops the WebSocket server flow's listener from accepting new connections and
     * drains its in-flight connections, so a SO_REUSEPORT sibling takes over new
     * connections while this process drains on graceful shutdown.
     */
    public function wsStopAccepting(string $flowKey): void
    {
        wsStopAccepting($flowKey);
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
                ),
            );
        }

        try {
            // The envelope is a fixed binary header; the payload (the rest) is the
            // feature's MessagePack bytes, decoded later by the feature itself.
            $header = unpack('Cflags/CmethodLen/NexecutionMs/nflowKeyLen/ntaskKeyLen', $response);

            if ($header === false) {
                throw new UnexpectedResponseFormatException(
                    message: 'Could not unpack result frame header.',
                );
            }

            $offset = self::FRAME_HEADER_SIZE;
            $method = substr($response, $offset, $header['methodLen']);
            $offset += $header['methodLen'];
            $flowKey = substr($response, $offset, $header['flowKeyLen']);
            $offset += $header['flowKeyLen'];
            $taskKey = substr($response, $offset, $header['taskKeyLen']);
            $offset += $header['taskKeyLen'];
            $payload = substr($response, $offset);

            return new TaskResultDto(
                flowKey: $flowKey,
                method: MethodEnum::from($method),
                key: $taskKey,
                isError: ($header['flags'] & self::FRAME_FLAG_ERROR) !== 0,
                payload: $payload,
                hasNext: ($header['flags'] & self::FRAME_FLAG_HAS_NEXT) !== 0,
                executionMs: $header['executionMs'],
                totalExecutionMs: (int) ((microtime(true) - $start) * 1000),
            );
        } catch (UnexpectedResponseFormatException $exception) {
            throw $exception;
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
            ),
        );
    }

    private function checkExtension(): void
    {
        if (static::$checked) {
            return;
        }

        if (!extension_loaded('sconcur')) {
            throw new ExtensionNotLoadedException(
                message: 'The extension "sconcur" is not loaded.',
            );
        }

        $loadedVersion = version();

        if (version_compare($loadedVersion, self::REQUIRED_EXTENSION_VERSION, '!=')) {
            throw new IncompatibleExtensionVersionException(
                message: sprintf(
                    'The loaded "sconcur" extension version %s does not match the required %s.',
                    $loadedVersion,
                    self::REQUIRED_EXTENSION_VERSION,
                ),
            );
        }

        static::$checked = true;
    }

    public function __destruct()
    {
        $this->destroy();
    }
}
