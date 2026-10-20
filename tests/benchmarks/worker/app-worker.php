<?php

/**
 * The worker of the memory benchmark: loads the stand-in application unless its fork server
 * has (the bootstrap arm), then answers every request with its pid.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once __DIR__ . '/app.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use SConcur\Features\HttpServer\HttpServer;

$factory = new Psr17Factory();

HttpServer::fromArgs(argv: $argv, serverRequestFactory: $factory, responseFactory: $factory)
    ->serve(static function () use ($factory) {
        $response = $factory->createResponse(200);

        $response->getBody()->write((string) getmypid());

        return $response;
    });
