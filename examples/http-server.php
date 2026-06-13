<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SConcur\Features\HttpServer\Dto\Request;
use SConcur\Features\HttpServer\Dto\Response;
use SConcur\Features\HttpServer\HttpServer;
use SConcur\Features\Sleeper\Sleeper;

$address = $argv[1] ?? '0.0.0.0:8088';

$server = new HttpServer(address: $address);

// Each request is handled in its own coroutine; the msleep below proves the
// handler can do async work concurrently with other requests.
$server->serve(static function (Request $request): Response {
    $sleeper = new Sleeper();
    $sleeper->msleep(milliseconds: 5);

    return new Response(
        body: sprintf("echo %s %s body=[%s]\n", $request->method, $request->path, $request->body),
        status: 200,
        headers: ['Content-Type' => 'text/plain'],
    );
});
