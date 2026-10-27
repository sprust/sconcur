<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

use Nyholm\Psr7\Factory\Psr17Factory;
use SConcur\Exceptions\Server\InvalidServerArgumentException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Features\HttpServer\HttpServer;
use SConcur\Features\SocketServer\SocketServer;
use SConcur\Features\WsServer\WsServer;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\FreePort;
use SConcur\Tests\Impl\PhpScript;

/**
 * A server accepting on a socket it was handed rather than one it bound — the address
 * "fd://N": one an application bound before its master forked the workers, or one from
 * systemd socket activation.
 *
 * The scenarios that fork run in a process of their own, since a fork is refused once
 * the extension's runtime runs, and this process started it long ago.
 */
class HttpServerFdAddressTest extends BaseTestCase
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

            // Held for as long as the children run: their stdout and stderr are pipes into
            // this process, and a child whose pipes nobody holds open dies on its first line.
            $forks = [];

            for ($index = 0; $index < 2; ++$index) {
                $fork = ProcessControl::fork(keepDescriptors: [$descriptor]);

                if ($fork->isChild()) {
                    $factory = new Psr17Factory();

                    $server = new HttpServer(
                        serverRequestFactory: $factory,
                        responseFactory: $factory,
                        address: 'fd://' . $descriptor,
                    );

                    $server->serve(static function () use ($factory) {
                        $response = $factory->createResponse(200);

                        $response->getBody()->write((string) getmypid());

                        return $response;
                    });

                    ProcessControl::exit(0);
                }

                $children[] = $fork->pid;
                $forks[]    = $fork;
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
                (string) FreePort::find(),
            ],
        );

        $answer = json_decode($output, true);

        self::assertIsArray($answer, 'the scenario printed no JSON: ' . $output);
        self::assertNotContains(null, $answer['before'], 'a request failed while both served: ' . $output);
        self::assertEqualsCanonicalizing(
            $answer['children'],
            array_values(array_unique($answer['before'])),
            'both workers should take connections from the one socket: ' . $output,
        );
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
                    address: 'fd://' . $descriptor,
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
                (string) FreePort::find(),
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
            address: 'fd://0',
        );

        $this->expectException(TaskErrorException::class);
        $this->expectExceptionMessage('descriptor 0 is not a listening socket');

        $server->serve(static fn() => $factory->createResponse(200));
    }

    /**
     * reusePort has nothing to set on a socket that is bound already, so it is refused
     * beside fd:// rather than ignored — where the server is built, so a role holding such
     * a server fails in the master's own config rather than in every worker it forks.
     */
    public function testReusePortBesideADescriptorIsRefused(): void
    {
        $factory = new Psr17Factory();

        $this->expectException(InvalidServerArgumentException::class);
        $this->expectExceptionMessage('reusePort does not apply to fd://3');

        new HttpServer(
            serverRequestFactory: $factory,
            responseFactory: $factory,
            address: 'fd://3',
            reusePort: true,
        );
    }

    /**
     * An fd:// address without a number is refused with the form it expects, not taken
     * for a host to bind.
     */
    public function testAMalformedDescriptorAddressIsRefused(): void
    {
        $factory = new Psr17Factory();

        $this->expectException(InvalidServerArgumentException::class);
        $this->expectExceptionMessage('a descriptor is given as fd://<number>');

        HttpServer::fromArgs(
            argv: ['server.php', '--address=fd://three'],
            serverRequestFactory: $factory,
            responseFactory: $factory,
        );
    }

    /**
     * The socket and WebSocket servers take the same address, and refuse it the same way.
     */
    public function testTheOtherServersRefuseAMalformedDescriptorAddressToo(): void
    {
        foreach ([SocketServer::class, WsServer::class] as $server) {
            try {
                new $server(address: 'fd://', reusePort: false);

                self::fail($server . ' took fd:// without a number');
            } catch (InvalidServerArgumentException $exception) {
                self::assertStringContainsString('fd://<number>', $exception->getMessage());
            }
        }
    }
}
