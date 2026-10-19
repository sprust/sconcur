<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

use Nyholm\Psr7\Factory\Psr17Factory;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Features\HttpServer\HttpServer;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\PhpScript;

/**
 * A server accepting on a socket it was handed rather than one it bound: the shared
 * socket of a worker master's group, or one from systemd socket activation.
 *
 * The scenarios that fork run in a process of their own, since a fork is refused once
 * the extension's runtime runs, and this process started it long ago.
 */
class HttpServerListenFdTest extends BaseTestCase
{
    /**
     * Two workers forked off one bound socket accept from the same queue. Stopping one
     * closes only its own copy of the listener: the other goes on serving and nothing
     * waiting on the socket is refused — the difference from SO_REUSEPORT, where a
     * closing socket resets the connections queued on it.
     */
    public function testWorkersOnOneSocketKeepServingWhenOneOfThemStops(): void
    {
        $output = PhpScript::run(
            code: <<<'PHP'
            require $argv[1] . '/vendor/autoload.php';

            use Nyholm\Psr7\Factory\Psr17Factory;
            use SConcur\Features\HttpServer\HttpServer;
            use SConcur\Process\ProcessControl;
            use SConcur\Process\ProcessSignalEnum;

            $address    = '127.0.0.1:' . $argv[2];
            $descriptor = ProcessControl::bind($address);

            $children = [];

            for ($index = 0; $index < 2; ++$index) {
                $fork = ProcessControl::fork(keepDescriptors: [$descriptor]);

                if ($fork->isChild()) {
                    $factory = new Psr17Factory();

                    $server = new HttpServer(
                        serverRequestFactory: $factory,
                        responseFactory: $factory,
                        listenFd: $descriptor,
                    );

                    $server->serve(static function () use ($factory) {
                        $response = $factory->createResponse(200);

                        $response->getBody()->write((string) getmypid());

                        return $response;
                    });

                    ProcessControl::exit(0);
                }

                $children[] = $fork->pid;
            }

            // A fresh connection per request, so every one goes back to the shared queue.
            $ask = static function () use ($address): ?int {
                $body = @file_get_contents('http://' . $address . '/', false, stream_context_create([
                    'http' => ['timeout' => 2],
                ]));

                return is_string($body) && ctype_digit($body) ? (int) $body : null;
            };

            $waitFor = static function (int $pid): bool {
                $deadline = microtime(true) + 5.0;

                while (microtime(true) < $deadline) {
                    if (ProcessControl::reap([$pid]) !== []) {
                        return true;
                    }

                    usleep(10_000);
                }

                return false;
            };

            $deadline = microtime(true) + 5.0;

            while ($ask() === null && microtime(true) < $deadline) {
                usleep(20_000);
            }

            $before = [];

            for ($request = 0; $request < 20; ++$request) {
                $before[] = $ask();
            }

            ProcessControl::kill($children[0], ProcessSignalEnum::Terminate);

            $firstStopped = $waitFor($children[0]);

            $after = [];

            for ($request = 0; $request < 20; ++$request) {
                $after[] = $ask();
            }

            ProcessControl::kill($children[1], ProcessSignalEnum::Terminate);

            $waitFor($children[1]);

            echo json_encode([
                'children'     => $children,
                'before'       => $before,
                'firstStopped' => $firstStopped,
                'after'        => $after,
            ]);
            PHP,
            arguments: [
                PhpScript::projectRoot(),
                (string) self::freePort(),
            ],
        );

        $answer = json_decode($output, true);

        self::assertIsArray($answer, 'the scenario printed no JSON: ' . $output);
        self::assertNotContains(null, $answer['before'], 'a request failed while both served: ' . $output);
        self::assertTrue($answer['firstStopped'], 'the first worker did not stop on SIGTERM');
        self::assertNotContains(null, $answer['after'], 'a request was refused once one worker stopped: ' . $output);
        self::assertSame([$answer['children'][1]], array_values(array_unique($answer['after'])));
    }

    /**
     * A server takes over a copy of the descriptor, so stopping it closes the copy and
     * leaves the descriptor itself open: the next server in the same process accepts on it
     * again. Taking over the original would have closed it with the first server.
     *
     * In a process of its own, so a server left waiting for a request that never comes
     * fails the test at the timeout instead of hanging the run.
     */
    public function testTheSameDescriptorServesAgainAfterAServerOnItStopped(): void
    {
        $output = PhpScript::run(
            code: <<<'PHP'
            require $argv[1] . '/vendor/autoload.php';

            use Nyholm\Psr7\Factory\Psr17Factory;
            use SConcur\Features\HttpClient\HttpClient;
            use SConcur\Features\HttpServer\HttpServer;
            use SConcur\Features\Sleeper\Sleeper;
            use SConcur\Process\ProcessControl;
            use SConcur\Scheduler\Scheduler;

            $port       = (int) $argv[2];
            $descriptor = ProcessControl::bind('127.0.0.1:' . $port);
            $factory    = new Psr17Factory();
            $received   = [];

            foreach (['first', 'second'] as $round) {
                Scheduler::get()->spawn(
                    callback: static function () use ($factory, $port, &$received): void {
                        // The server starts accepting once serve() runs, after this is spawned.
                        Sleeper::usleep(microseconds: 50_000);

                        // A connection of its own per round: a kept-alive one would still
                        // belong to the server of the round before.
                        $request = $factory->createRequest('GET', 'http://127.0.0.1:' . $port . '/')
                            ->withHeader('Connection', 'close');

                        $received[] = (string) (new HttpClient(responseFactory: $factory))
                            ->sendRequest($request)
                            ->getBody();
                    },
                );

                $server = new HttpServer(
                    serverRequestFactory: $factory,
                    responseFactory: $factory,
                    maxRequests: 1,
                    listenFd: $descriptor,
                );

                $server->serve(static function () use ($factory, $round) {
                    $response = $factory->createResponse(200);

                    $response->getBody()->write($round);

                    return $response;
                });
            }

            echo 'RECEIVED:', json_encode($received);
            PHP,
            arguments: [
                PhpScript::projectRoot(),
                (string) self::freePort(),
            ],
        );

        self::assertStringContainsString('RECEIVED:["first","second"]', $output);
    }

    /**
     * A descriptor that is open but is no listening socket fails the serve at once, naming
     * the descriptor, rather than on the first accept with an error about something else.
     */
    public function testADescriptorThatIsNoListeningSocketIsRefused(): void
    {
        $factory = new Psr17Factory();

        $server = new HttpServer(
            serverRequestFactory: $factory,
            responseFactory: $factory,
            listenFd: 0,
        );

        $this->expectException(TaskErrorException::class);
        $this->expectExceptionMessage('descriptor 0 is not a listening socket');

        $server->serve(static fn() => $factory->createResponse(200));
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
