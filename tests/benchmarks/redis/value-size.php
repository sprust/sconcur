<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestRedisResolver;

require_once __DIR__ . '/../lib/benchmarker.php';

/**
 * The same GET at three value sizes, to find where copying the value across the boundary
 * costs more than the concurrency saves. Pass the size in bytes as the third argument;
 * the documented run uses 100, 10240 and 1048576.
 */
$benchmarker = new Benchmarker(
    name: 'redis-value-size',
);

$valueSizeBytes = (int) ($_SERVER['argv'][3] ?? 10240);

TestRedisResolver::flush();

$connection = TestRedisResolver::getConnection();
$native     = TestRedisResolver::getNativeRedis();

$native->set('bench:blob', str_repeat('x', $valueSizeBytes));

echo "value size: $valueSizeBytes bytes\n";

$benchmarker->run(
    nativeCallback: static function () use ($native): int {
        return strlen((string) $native->get('bench:blob'));
    },
    syncCallback: static function () use ($connection): int {
        return strlen((string) $connection->get('bench:blob'));
    },
    asyncCallback: static function () use ($connection): int {
        return strlen((string) $connection->get('bench:blob'));
    },
);
