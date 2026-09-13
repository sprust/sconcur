<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestRedisResolver;

require_once __DIR__ . '/../lib/benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'redis-set',
);

TestRedisResolver::flush();

$connection = TestRedisResolver::getConnection();
$native     = TestRedisResolver::getNativeRedis();

$benchmarker->run(
    nativeCallback: static function () use ($native): bool {
        return (bool) $native->set('bench:key', 'bench-value');
    },
    syncCallback: static function () use ($connection): bool {
        return $connection->set('bench:key', 'bench-value');
    },
    asyncCallback: static function () use ($connection): bool {
        return $connection->set('bench:key', 'bench-value');
    },
);
