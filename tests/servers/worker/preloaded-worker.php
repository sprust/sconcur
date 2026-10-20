<?php

/**
 * The worker script of a fork group whose master loaded the application first
 * (preloaded-master.php). It uses what the master loaded instead of loading it again, and
 * reaches it through $GLOBALS: a forked worker runs its script in a function's scope.
 *
 *   /pid   this worker's pid, which is what TestWorkerMaster waits for
 *   /      {"loadedBy": <the pid the application loaded in>, "pid": <this worker>}
 *   /log   writes a line to the log the application opened, then answers as /
 *   /warn  raises a warning, then answers as /
 *   /hang  holds the PHP thread for 20 s, which the watchdog takes for a hung worker
 *
 * SCONCUR_TEST_WORKER_THROWS=1 makes it throw before it serves.
 */

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SConcur\Features\HttpServer\HttpServer;

if (getenv('SCONCUR_TEST_WORKER_THROWS') === '1') {
    throw new RuntimeException('the worker failed to start');
}

/** @var array{loadedBy: int, log: resource} $app */
$app     = $GLOBALS['app'];
$factory = new Psr17Factory();

$server = HttpServer::fromArgs(
    argv: $argv,
    serverRequestFactory: $factory,
    responseFactory: $factory,
);

$server->serve(static function (ServerRequestInterface $request) use ($app, $factory): ResponseInterface {
    match ($request->getUri()->getPath()) {
        '/log'  => fwrite($app['log'], 'written by ' . getmypid() . PHP_EOL),
        '/warn' => trigger_error('raised in the worker', E_USER_WARNING),
        '/hang' => usleep(20_000_000),
        default => null,
    };

    $response = $factory->createResponse(200);

    $response->getBody()->write(
        $request->getUri()->getPath() === '/pid'
            ? (string) getmypid()
            : (string) json_encode([
                'loadedBy' => $app['loadedBy'],
                'pid'      => getmypid(),
            ]),
    );

    return $response;
});
