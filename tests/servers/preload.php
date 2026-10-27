<?php

/**
 * What the demo servers build on, loaded once in a worker master before it starts, the way
 * an application loads before its master: every worker is a fork that starts with it in
 * memory. The load harnesses name it in the "preload" of the master's config
 * (tests/servers/worker/master.php).
 */

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use SConcur\Connection\Extension;
use SConcur\Features\HttpServer\HttpServer;
use SConcur\Features\Mongodb\Connection\Client;
use SConcur\Features\Mysql\Connection as MysqlConnection;
use SConcur\Features\Pgsql\Connection as PgsqlConnection;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Features\WsServer\WsServer;
use SConcur\Scheduler\Scheduler;
use SConcur\WaitGroup;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// Loaded, not used: a class is compiled when the autoloader finds it, and a class
// compiled before the fork is one every worker shares instead of compiling its own copy.
// Nothing here may touch the extension's runtime — the master could not fork after it.
foreach (
    [
        HttpServer::class,
        WsServer::class,
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
