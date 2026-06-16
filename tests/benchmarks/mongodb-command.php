<?php

declare(strict_types=1);

use SConcur\Tests\Impl\TestMongodbResolver;

require_once __DIR__ . '/_benchmarker.php';

$benchmarker = new Benchmarker(
    name: 'mongodb-command',
);

$driverDatabase  = TestMongodbResolver::getDriverTestDatabase();
$sconcurDatabase = TestMongodbResolver::getSconcurTestDatabase();

$command = ['ping' => 1];

$benchmarker->run(
    nativeCallback: static function () use ($driverDatabase, $command) {
        return $driverDatabase->command($command)->toArray();
    },
    syncCallback: static function () use ($sconcurDatabase, $command) {
        return $sconcurDatabase->command($command);
    },
    asyncCallback: static function () use ($sconcurDatabase, $command) {
        return $sconcurDatabase->command($command);
    },
);
