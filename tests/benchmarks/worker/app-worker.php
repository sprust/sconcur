<?php

/**
 * The worker of the memory benchmark: loads the stand-in application unless its fork server
 * has (the bootstrap arm), then answers every request with its pid — after running a
 * request's worth of the application on /work (memoryBenchmarkWork()), which is what the
 * long run sends. /memory answers the pid with what PHP's heap holds and has taken from the
 * system (memory_get_usage() without and with real), which the long run reads to tell a
 * leak from pages a worker has merely touched.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once __DIR__ . '/app.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use SConcur\Features\HttpServer\HttpServer;

$factory = new Psr17Factory();

HttpServer::fromArgs(argv: $argv, serverRequestFactory: $factory, responseFactory: $factory)
    ->serve(static function ($request) use ($factory) {
        if ($request->getUri()->getPath() === '/work') {
            memoryBenchmarkWork();
        }

        $response = $factory->createResponse(200);

        $response->getBody()->write(
            $request->getUri()->getPath() === '/memory'
                ? getmypid() . ' ' . memory_get_usage() . ' ' . memory_get_usage(true)
                : (string) getmypid(),
        );

        return $response;
    });
