<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestRedisResolver;

require_once __DIR__ . '/../lib/benchmarker.php';

/**
 * A batch of commands against the same commands one at a time. This is the shape where
 * the boundary is paid once for the whole batch instead of once per command, so it is
 * where the synchronous path stops being the worst of the three.
 */
$benchmarker = new Benchmarker(
    name: 'redis-pipeline',
);

const COMMANDS_PER_BATCH = 20;

TestRedisResolver::flush();

$connection = TestRedisResolver::getConnection();
$native     = TestRedisResolver::getNativeRedis();

$benchmarker->run(
    nativeCallback: static function () use ($native): int {
        $pipeline = $native->pipeline();

        for ($index = 0; $index < COMMANDS_PER_BATCH; ++$index) {
            $pipeline->set("bench:pipe:$index", (string) $index);
        }

        return count((array) $pipeline->exec());
    },
    syncCallback: static function () use ($connection): int {
        $pipeline = $connection->pipeline();

        for ($index = 0; $index < COMMANDS_PER_BATCH; ++$index) {
            $pipeline->command('SET', ["bench:pipe:$index", (string) $index]);
        }

        return count($pipeline->execute());
    },
    asyncCallback: static function () use ($connection): int {
        $pipeline = $connection->pipeline();

        for ($index = 0; $index < COMMANDS_PER_BATCH; ++$index) {
            $pipeline->command('SET', ["bench:pipe:$index", (string) $index]);
        }

        return count($pipeline->execute());
    },
);
