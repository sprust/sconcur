<?php

/**
 * The worker of the worker master's soak under failures (worker-soak.php): the memory
 * benchmark's worker (tests/benchmarks/worker) with a path for every way a request or a
 * worker can fail. Loads the stand-in application unless its fork server has (the bootstrap
 * launch).
 *
 *   /work             a request's worth of the application, the load around the failures
 *   /memory           "pid heap-used heap-real tasks": what PHP's heap holds and has taken
 *                     from the system, and the tasks the extension's runtime holds
 *   /slow             waits 200 ms on the runtime
 *   /oom              allocates until memory_limit ends the worker
 *   /exit             exit(1) in the middle of the request
 *   /fatal            a compile error, which no handler catches
 *   /spin             a CPU loop that never comes back to the scheduler
 *   /block            sleep() in PHP, which the runtime does not see
 *   /throw            an exception out of the handler
 *   /orphan           starts coroutines (waits on the runtime, a request to /slow) and throws
 *                     before waiting for them
 *   /handler-timeout  waits 5 s, for a server whose handlerTimeoutMs is shorter
 *   /external         a request to a closed port and one that runs out of its time
 *   /cpu              300 ms of CPU, for a server with preemption and a short handlerTimeoutMs
 *
 * FAILURES_PORT is the port the group listens on; FAILURES_CRASH_AT_START_PERCENT makes that
 * share of the workers exit before they serve. FAILURES_EXIT_TRACE names a file
 * (worker-exit-timing.php reads it) where /exit notes when it calls exit(), and the worker's
 * shutdown functions when they start and end, in milliseconds of the monotonic clock.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/benchmarks/worker/app.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use SConcur\Features\HttpClient\HttpClient;
use SConcur\Features\HttpClient\HttpClientOptions;
use SConcur\Features\HttpServer\HttpServer;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\WaitGroup;
use function SConcur\Extension\tasksCount;

$crashAtStartPercent = (int) (getenv('FAILURES_CRASH_AT_START_PERCENT') ?: 0);

if ($crashAtStartPercent > 0 && random_int(1, 100) <= $crashAtStartPercent) {
    exit(1);
}

$exitTrace = (string) getenv('FAILURES_EXIT_TRACE');

$traceExit = static function (string $event) use ($exitTrace): void {
    if ($exitTrace !== '') {
        file_put_contents($exitTrace, sprintf("%d %s %.3f\n", getmypid(), $event, hrtime(true) / 1e6), FILE_APPEND);
    }
};

if ($exitTrace !== '') {
    // One registered from a shutdown function runs after every one queued before it: the
    // pair brackets the shutdown functions this worker registers, the scheduler's among them
    // (one a fork server registered before the fork runs ahead of both).
    register_shutdown_function(static function () use ($traceExit): void {
        $traceExit('shutdown');

        register_shutdown_function(static fn() => $traceExit('shutdown-end'));
    });
}

$factory = new Psr17Factory();
$slowUrl = 'http://127.0.0.1:' . (int) getenv('FAILURES_PORT') . '/slow';

HttpServer::fromArgs(argv: $argv, serverRequestFactory: $factory, responseFactory: $factory)
    ->serve(static function ($request) use ($factory, $slowUrl, $traceExit) {
        $body = (string) getmypid();

        switch ($request->getUri()->getPath()) {
            case '/work':
                memoryBenchmarkWork();

                break;
            case '/memory':
                $body .= ' ' . memory_get_usage() . ' ' . memory_get_usage(true) . ' ' . tasksCount();

                break;
            case '/slow':
                Sleeper::usleep(200_000);

                break;
            case '/oom':
                $chunks = [];

                while (true) {
                    $chunks[] = str_repeat('x', 1_048_576);
                }

            case '/exit':
                $traceExit('exit');

                exit(1);

            case '/fatal':
                eval('function memoryBenchmarkWork(): string { return ""; }');

                break;
            case '/spin':
                while (true) {
                }

            case '/block':
                sleep(3600);

                break;
            case '/throw':
                throw new RuntimeException('thrown on purpose');
            case '/orphan':
                $group  = WaitGroup::create();
                $client = new HttpClient(
                    responseFactory: $factory,
                    options: new HttpClientOptions(requestTimeoutMs: 2_000),
                );

                for ($sleeper = 0; $sleeper < 3; ++$sleeper) {
                    $group->add(static fn() => Sleeper::sleep(5));
                }

                $group->add(static fn() => $client->sendRequest($factory->createRequest('GET', $slowUrl)));

                throw new RuntimeException('thrown with coroutines in flight');
            case '/handler-timeout':
                Sleeper::sleep(5);

                break;
            case '/external':
                $client = new HttpClient(
                    responseFactory: $factory,
                    options: new HttpClientOptions(requestTimeoutMs: 50),
                );

                foreach (['http://127.0.0.1:1/', $slowUrl] as $url) {
                    try {
                        $client->sendRequest($factory->createRequest('GET', $url));
                    } catch (Throwable) {
                        // Refused, and out of its time: both are the point.
                    }
                }

                break;
            case '/cpu':
                $until = hrtime(true) + 300_000_000;

                while (hrtime(true) < $until) {
                }

                break;
        }

        $response = $factory->createResponse(200);

        $response->getBody()->write($body);

        return $response;
    });
