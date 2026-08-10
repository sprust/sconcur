<?php

declare(strict_types=1);

namespace SConcur\Connection;

use Closure;
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
use function SConcur\Extension\armPreemption;
use function SConcur\Extension\destroy;
use function SConcur\Extension\disarmPreemption;
use function SConcur\Extension\httpStopAccepting;
use function SConcur\Extension\next;
use function SConcur\Extension\push;
use function SConcur\Extension\socketStopAccepting;
use function SConcur\Extension\stopFlow;
use function SConcur\Extension\tasksCount;
use function SConcur\Extension\version;
use function SConcur\Extension\wait;
use function SConcur\Extension\waitAny;
use function SConcur\Extension\waitAnyBatch;
use function SConcur\Extension\waitAnyTimeout;
use function SConcur\Extension\waitAnyTimeoutBatch;
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
    public const string REQUIRED_EXTENSION_VERSION = '0.9.1';

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

    /**
     * waitAny draining the batch: blocks for the first ready result exactly like
     * waitAny(), then returns it together with every further result that was
     * already ready — up to $maxResults — all in one cgo crossing. The batch
     * never waits to fill up, so the first result's latency is unchanged.
     *
     * @return non-empty-list<TaskResultDto>
     */
    public function waitAnyBatch(int $maxResults): array
    {
        $start = microtime(true);

        $response = waitAnyBatch($maxResults);

        return static::parseWaitBatchResponse(
            response: $response,
            errorContext: 'waitAnyBatch',
            start: $start,
        );
    }

    /**
     * waitAnyBatch with a deadline for the first result: returns null if nothing
     * became ready within $timeoutMs, so a blocking caller (the serve loop) can
     * wake to check for a shutdown signal even on an idle server.
     *
     * @return non-empty-list<TaskResultDto>|null
     */
    public function waitAnyTimeoutBatch(int $timeoutMs, int $maxResults): ?array
    {
        $start = microtime(true);

        $response = waitAnyTimeoutBatch($timeoutMs, $maxResults);

        // Distinct, non-"error:" sentinel the Go side returns on timeout. A real
        // batch is binary and an error starts with "error:", so this never
        // collides.
        if ($response === 'timeout') {
            return null;
        }

        return static::parseWaitBatchResponse(
            response: $response,
            errorContext: 'waitAnyTimeoutBatch',
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

    /**
     * Starts automatic preemption: the extension's timer requests a VM interrupt
     * every $quantumMs, and the engine calls $preemptCallback between opcodes on
     * the PHP thread (the Scheduler's preempt hook parking the current
     * coroutine). Re-arming replaces the previous timer and callback.
     */
    public function armPreemption(int $quantumMs, Closure $preemptCallback): void
    {
        armPreemption($quantumMs, $preemptCallback);
    }

    public function disarmPreemption(): void
    {
        disarmPreemption();
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

        return static::parseResultFrame(
            response: $response,
            offset: 0,
            frameLength: strlen($response),
            start: $start,
        );
    }

    /**
     * Decodes one result frame at $offset without copying the frame out of
     * $response, so a batch multiframe decodes each frame in place.
     */
    protected static function parseResultFrame(string $response, int $offset, int $frameLength, float $start): TaskResultDto
    {
        try {
            // The envelope is a fixed binary header; the payload (the rest of the
            // frame) is the feature's MessagePack bytes, decoded later by the
            // feature itself.
            $header = unpack('Cflags/CmethodLen/NexecutionMs/nflowKeyLen/ntaskKeyLen', $response, $offset);

            if ($header === false) {
                throw new UnexpectedResponseFormatException(
                    message: 'Could not unpack result frame header.',
                );
            }

            // The declared lengths must fit the frame: on a corrupt frame a
            // blind substr chain would silently slice bytes of the NEIGHBOUR
            // frames into this result's payload instead of failing loudly.
            $declaredLength = self::FRAME_HEADER_SIZE
                + $header['methodLen']
                + $header['flowKeyLen']
                + $header['taskKeyLen'];

            if ($declaredLength > $frameLength || ($offset + $frameLength) > strlen($response)) {
                throw new UnexpectedResponseFormatException(
                    message: 'Result frame lengths exceed the frame boundary.',
                );
            }

            $cursor = $offset + self::FRAME_HEADER_SIZE;
            $method = substr($response, $cursor, $header['methodLen']);
            $cursor += $header['methodLen'];
            $flowKey = substr($response, $cursor, $header['flowKeyLen']);
            $cursor += $header['flowKeyLen'];
            $taskKey = substr($response, $cursor, $header['taskKeyLen']);
            $cursor += $header['taskKeyLen'];
            $payload = substr($response, $cursor, ($offset + $frameLength) - $cursor);

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

    /**
     * Parses the result multiframe of waitAnyBatch/waitAnyTimeoutBatch (see
     * buildResultBatchFrame in main.go): [count uint16][frameLen uint32][frame]...
     * — each inner frame in the exact single-result format of parseWaitResponse.
     *
     * @return non-empty-list<TaskResultDto>
     */
    protected static function parseWaitBatchResponse(string $response, string $errorContext, float $start): array
    {
        // The tail results of a batch were already ready when the crossing
        // returned: only the first frame waited from $start, the rest waited
        // for nothing — mirroring the per-call semantics of the singular
        // waitAny, where a ready result's own wait returns immediately.
        $crossingEnd = microtime(true);

        if (str_starts_with($response, 'error:')) {
            throw new TaskErrorException(
                message: sprintf(
                    '%s: %s',
                    $errorContext,
                    $response,
                ),
            );
        }

        // A structurally corrupt frame fails the whole batch loudly (the
        // already-parsed prefix included): the frames are machine-built by the
        // version-locked extension, so a mismatch is a protocol bug — partial
        // delivery would only hide it behind a few silently hung coroutines.
        try {
            $header = unpack('ncount', $response);

            if ($header === false || $header['count'] < 1) {
                throw new UnexpectedResponseFormatException(
                    message: 'Could not unpack result batch header.',
                );
            }

            $results = [];
            $offset  = 2;

            for ($frameIndex = 0; $frameIndex < $header['count']; $frameIndex++) {
                $frameHeader = unpack('NframeLength', $response, $offset);

                if ($frameHeader === false) {
                    throw new UnexpectedResponseFormatException(
                        message: 'Could not unpack result batch frame length.',
                    );
                }

                $offset += 4;

                $results[] = static::parseResultFrame(
                    response: $response,
                    offset: $offset,
                    frameLength: $frameHeader['frameLength'],
                    start: ($frameIndex === 0) ? $start : $crossingEnd,
                );

                $offset += $frameHeader['frameLength'];
            }

            if ($offset !== strlen($response)) {
                throw new UnexpectedResponseFormatException(
                    message: 'Result batch has trailing bytes past the last frame.',
                );
            }

            return $results;
        } catch (UnexpectedResponseFormatException|TaskErrorException $exception) {
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
