<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Scheduler;

use Nyholm\Psr7\Factory\Psr17Factory;
use ReflectionMethod;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Features\HttpServer\HttpServer;
use SConcur\Scheduler\Scheduler;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Worker\Heartbeat;

/**
 * What serve() tells a master about the worker it runs in: that it is up, only once its
 * socket is had, and that it is leaving because it reached its limit — and the limit
 * itself, spread under a master and exact without one. The master's end of the pipe is a
 * socket pair here, handed over the way a fork hands one over.
 */
class SchedulerServeMarksTest extends BaseTestCase
{
    /** @var resource|null the master's end */
    protected mixed $readEnd = null;

    /** @var resource|null the worker's end */
    protected mixed $writeEnd = null;

    protected function setUp(): void
    {
        parent::setUp();

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        self::assertIsArray($pair);

        [$this->readEnd, $this->writeEnd] = $pair;

        stream_set_blocking($this->readEnd, false);
    }

    protected function tearDown(): void
    {
        // What this process, which no master started, would have found on its own.
        Heartbeat::adopt(null);

        foreach ([$this->readEnd, $this->writeEnd] as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        parent::tearDown();
    }

    public function testWithoutAMasterTheLimitIsExact(): void
    {
        Heartbeat::adopt(null);

        for ($attempt = 0; $attempt < 20; ++$attempt) {
            self::assertSame(10, $this->spreadLimit(10));
        }

        self::assertSame(0, $this->spreadLimit(0), 'no limit stays no limit');
    }

    public function testUnderAMasterTheLimitIsSpreadUpToHalfAgain(): void
    {
        Heartbeat::adopt($this->writeEnd);

        $limits = [];

        for ($attempt = 0; $attempt < 200; ++$attempt) {
            $limit = $this->spreadLimit(1_000);

            self::assertGreaterThanOrEqual(1_000, $limit);
            self::assertLessThanOrEqual(1_500, $limit);

            $limits[$limit] = true;
        }

        self::assertGreaterThan(1, count($limits), 'the workers of a pool must not all draw the same limit');
        self::assertSame(0, $this->spreadLimit(0), 'no limit stays no limit');
    }

    /**
     * A server whose socket cannot be had is not up. Its first wait brings back the bind
     * failure, and the reload waiting on it must not hear otherwise.
     */
    public function testAServerThatCannotBindNeverSaysItIsUp(): void
    {
        Heartbeat::adopt($this->writeEnd);

        $taken = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        self::assertNotFalse($taken, "could not allocate a port: $errorMessage");

        $factory = new Psr17Factory();

        $server = new HttpServer(
            serverRequestFactory: $factory,
            responseFactory: $factory,
            address: (string) stream_socket_get_name($taken, false),
        );

        try {
            $server->serve(static fn() => $factory->createResponse(200));

            self::fail('a taken address must fail the serve');
        } catch (TaskErrorException) {
            // The failure itself is covered elsewhere; what matters is what the master heard.
        } finally {
            fclose($taken);
        }

        self::assertStringNotContainsString(Heartbeat::MARK_READY, $this->readAvailable());
    }

    /**
     * A server that serves says it is up, and one that stops at its limit says why before
     * it drains, so the master starts its replacement while it finishes.
     */
    public function testAServingServerSaysItIsUpAndThatItLeavesAtItsLimit(): void
    {
        Heartbeat::adopt($this->writeEnd);

        $port = self::freePort();

        // One request from another process, once the server below is listening.
        $client = proc_open(
            [
                PHP_BINARY,
                '-r',
                'usleep(300000); echo @file_get_contents("http://127.0.0.1:' . $port . '/");',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        self::assertIsResource($client);

        $factory = new Psr17Factory();

        $server = new HttpServer(
            serverRequestFactory: $factory,
            responseFactory: $factory,
            address: '127.0.0.1:' . $port,
            // 1 is 1 however it is spread: half of it rounds to nothing.
            maxRequests: 1,
        );

        $server->serve(static function () use ($factory) {
            $response = $factory->createResponse(200);

            $response->getBody()->write('served');

            return $response;
        });

        $body = (string) stream_get_contents($pipes[1]);

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        proc_close($client);

        self::assertSame('served', $body);

        $marks = $this->readAvailable();

        self::assertStringContainsString(Heartbeat::MARK_READY, $marks);
        self::assertStringContainsString(Heartbeat::MARK_RECYCLING, $marks);
        self::assertLessThan(
            strpos($marks, Heartbeat::MARK_RECYCLING),
            strpos($marks, Heartbeat::MARK_READY),
            'up first, leaving after',
        );
    }

    protected function spreadLimit(int $limit): int
    {
        $method = new ReflectionMethod(Scheduler::class, 'spreadLimit');

        return (int) $method->invoke(null, $limit);
    }

    protected function readAvailable(): string
    {
        $marks = '';

        while (is_string($chunk = fread($this->readEnd, 8192)) && $chunk !== '') {
            $marks .= $chunk;
        }

        return $marks;
    }

    protected static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        self::assertNotFalse($socket, "could not allocate a port: $errorMessage");

        $name = (string) stream_socket_get_name($socket, false);

        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
