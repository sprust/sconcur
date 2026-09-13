<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestRedisResolver;

require_once __DIR__ . '/../lib/benchmarker.php';

/**
 * The same async GET at one pool size, so a run over 1/2/4/8 says what the default should
 * be. Pass the size as the third argument.
 *
 * One multiplexed connection already carries any number of concurrent commands; more than
 * one exists so a large value in flight does not hold up what is queued behind it.
 */
$benchmarker = new Benchmarker(
    name: 'redis-pool-size',
);

$poolSize = (int) ($_SERVER['argv'][3] ?? 4);

TestRedisResolver::flush();

$connection = TestRedisResolver::getConnection(poolSize: $poolSize);
$native     = TestRedisResolver::getNativeRedis();

$native->set('bench:key', 'bench-value');

echo "pool size: $poolSize\n";

$benchmarker->run(
    asyncCallback: static function () use ($connection): string {
        return (string) $connection->get('bench:key');
    },
);
