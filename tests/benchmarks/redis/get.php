<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestRedisResolver;

require_once __DIR__ . '/../lib/benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'redis-get',
);

TestRedisResolver::flush();

$connection = TestRedisResolver::getConnection();
$native     = TestRedisResolver::getNativeRedis();

$native->set('bench:key', 'bench-value');

$benchmarker->run(
    nativeCallback: static function () use ($native): string {
        return (string) $native->get('bench:key');
    },
    syncCallback: static function () use ($connection): string {
        return (string) $connection->get('bench:key');
    },
    asyncCallback: static function () use ($connection): string {
        return (string) $connection->get('bench:key');
    },
);
