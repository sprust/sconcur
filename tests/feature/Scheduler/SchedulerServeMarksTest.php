<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Scheduler;

use ReflectionMethod;
use SConcur\Process\HeartbeatMarkEnum;
use SConcur\Process\ProcessControl;
use SConcur\Scheduler\Scheduler;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\FreePort;
use SConcur\Tests\Impl\PhpScript;
use SConcur\Tests\Impl\Worker\SupervisedScript;

/**
 * What serve() tells a master about the worker it runs in: that it is up, only once its
 * socket is had, and that it is leaving because it reached its limit — and the limit
 * itself, spread under a master and exact without one. Under a master means in a worker of
 * its own, exec'd with the pipe on its descriptor 3 as a master starts one: the extension
 * finds the pipe as the process starts, and this one has none.
 */
class SchedulerServeMarksTest extends BaseTestCase
{
    /** Prepended to every worker script: the autoloader, and what the scripts use. */
    protected const string PRELUDE = <<<'PHP'
    require $argv[1] . '/vendor/autoload.php';

    use Nyholm\Psr7\Factory\Psr17Factory;
    use SConcur\Exceptions\TaskErrorException;
    use SConcur\Features\HttpServer\HttpServer;
    use SConcur\Scheduler\Scheduler;

    PHP;

    public function testWithoutAMasterTheLimitIsExact(): void
    {
        self::assertFalse(ProcessControl::hasHeartbeat(), 'the test process has a master');

        $method = new ReflectionMethod(Scheduler::class, 'spreadLimit');

        for ($attempt = 0; $attempt < 20; ++$attempt) {
            self::assertSame(10, $method->invoke(null, 10));
        }

        self::assertSame(0, $method->invoke(null, 0), 'no limit stays no limit');
    }

    public function testUnderAMasterTheLimitIsSpreadUpToHalfAgain(): void
    {
        $run = SupervisedScript::run(
            code: self::PRELUDE . <<<'PHP'
            $method = new ReflectionMethod(Scheduler::class, 'spreadLimit');

            $limits = [];

            for ($attempt = 0; $attempt < 200; ++$attempt) {
                $limits[] = $method->invoke(null, 1_000);
            }

            echo json_encode([
                'limits'  => $limits,
                'noLimit' => $method->invoke(null, 0),
            ]);
            PHP,
            arguments: [PhpScript::projectRoot()],
        );

        $answer = json_decode($run['output'], true);

        self::assertIsArray($answer, $run['output']);

        foreach ($answer['limits'] as $limit) {
            self::assertGreaterThanOrEqual(1_000, $limit);
            self::assertLessThanOrEqual(1_500, $limit);
        }

        self::assertGreaterThan(
            1,
            count(array_unique($answer['limits'])),
            'the workers of a pool must not all draw the same limit',
        );
        self::assertSame(0, $answer['noLimit'], 'no limit stays no limit');
    }

    /**
     * A server whose socket cannot be had is not up. Its first wait brings back the bind
     * failure, and the reload waiting on it must not hear otherwise.
     */
    public function testAServerThatCannotBindNeverSaysItIsUp(): void
    {
        $run = SupervisedScript::run(
            code: self::PRELUDE . <<<'PHP'
            $taken = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

            if ($taken === false) {
                echo "could not allocate a port: $errorMessage";

                exit(1);
            }

            $factory = new Psr17Factory();

            $server = new HttpServer(
                serverRequestFactory: $factory,
                responseFactory: $factory,
                address: (string) stream_socket_get_name($taken, false),
            );

            try {
                $server->serve(static fn() => $factory->createResponse(200));

                echo 'served on a taken address';
            } catch (TaskErrorException) {
                // The failure itself is covered elsewhere; what matters is what the master heard.
                echo 'refused';
            }
            PHP,
            arguments: [PhpScript::projectRoot()],
        );

        // After the lines the server logs on its stdout.
        self::assertStringEndsWith('refused', $run['output']);
        self::assertStringNotContainsString(HeartbeatMarkEnum::Ready->byte(), $run['marks']);
    }

    /**
     * A server that serves says it is up, and one that stops at its limit says why before
     * it drains, so the master starts its replacement while it finishes.
     */
    public function testAServingServerSaysItIsUpAndThatItLeavesAtItsLimit(): void
    {
        $run = SupervisedScript::run(
            code: self::PRELUDE . <<<'PHP'
            $port = $argv[2];

            // One request from another process, asked until the server below is listening:
            // a request that never arrives would leave serve() waiting for its limit forever.
            $client = proc_open(
                [
                    PHP_BINARY,
                    '-r',
                    <<<'CLIENT'
                    $url      = $argv[1];
                    $deadline = microtime(true) + 10.0;
                    $context  = stream_context_create(['http' => ['timeout' => 2]]);

                    while (($body = @file_get_contents($url, false, $context)) === false && microtime(true) < $deadline) {
                        usleep(20_000);
                    }

                    echo $body;
                    CLIENT,
                    'http://127.0.0.1:' . $port . '/',
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );

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

            echo stream_get_contents($pipes[1]);

            foreach ($pipes as $pipe) {
                fclose($pipe);
            }

            proc_close($client);
            PHP,
            arguments: [
                PhpScript::projectRoot(),
                (string) FreePort::find(),
            ],
        );

        self::assertStringEndsWith('served', $run['output']);

        $ready     = strpos($run['marks'], HeartbeatMarkEnum::Ready->byte());
        $recycling = strpos($run['marks'], HeartbeatMarkEnum::Recycling->byte());

        self::assertNotFalse($ready, 'the server never said it is up');
        self::assertNotFalse($recycling, 'the server never said it leaves at its limit');
        self::assertLessThan($recycling, $ready, 'up first, leaving after');
    }
}
