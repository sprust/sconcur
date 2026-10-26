<?php

/**
 * The demo HTTP server under a worker master, the way an application runs in production:
 * what the server builds on is loaded once here, before the master starts, and every
 * worker is a fork that starts with it in memory. The workers then run
 * tests/servers/http/http-server.php as their role (tests/servers/worker/master.php).
 *
 * Used by tests/benchmarks/http/load-stats.sh with LAUNCH=master.
 *
 * Usage: php -d extension=sconcur.so tests/servers/http/load-master.php <config.json>
 */

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use SConcur\Connection\Extension;
use SConcur\Features\HttpServer\HttpServer;
use SConcur\Features\Mongodb\Connection\Client;
use SConcur\Features\Mysql\Connection as MysqlConnection;
use SConcur\Features\Pgsql\Connection as PgsqlConnection;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Scheduler\Scheduler;
use SConcur\WaitGroup;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// Loaded, not used: a class is compiled when the autoloader finds it, and a class
// compiled before the fork is one every worker shares instead of compiling its own copy.
// Nothing here may touch the extension's runtime — the master could not fork after it.
foreach (
    [
        HttpServer::class,
        Psr17Factory::class,
        Scheduler::class,
        WaitGroup::class,
        Extension::class,
        Sleeper::class,
        Client::class,
        MysqlConnection::class,
        PgsqlConnection::class,
    ] as $class
) {
    class_exists($class);
}

require dirname(__DIR__) . '/worker/master.php';
