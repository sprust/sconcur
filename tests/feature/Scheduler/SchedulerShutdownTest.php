<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Scheduler;

use SConcur\Features\Sleeper\Sleeper;
use SConcur\Scheduler\Scheduler;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\WaitGroup;

class SchedulerShutdownTest extends BaseTestCase
{
    public function testShutdownUnwindsLiveCoroutines(): void
    {
        $finallyRuns = [];

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: static function () use (&$finallyRuns): void {
                try {
                    Sleeper::sleep(seconds: 30);
                } finally {
                    $finallyRuns[] = 'first';
                }
            },
        );

        $waitGroup->add(
            callback: static function () use (&$finallyRuns): void {
                try {
                    Sleeper::sleep(seconds: 30);
                } finally {
                    $finallyRuns[] = 'second';
                }
            },
        );

        Scheduler::get()->shutdown();

        self::assertSame(
            [
                'first',
                'second',
            ],
            $finallyRuns,
        );
        self::assertFalse($waitGroup->isLive());

        $this->assertNoTasksCount();
    }

    public function testSchedulerStaysUsableAfterShutdown(): void
    {
        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: static function (): void {
                Sleeper::sleep(seconds: 30);
            },
        );

        Scheduler::get()->shutdown();

        $secondGroup = WaitGroup::create();

        $secondGroup->add(
            callback: static function (): int {
                Sleeper::usleep(microseconds: 1);

                return 42;
            },
        );

        $results = $secondGroup->waitResults();

        self::assertSame([42], array_values($results));
    }

    public function testExitWithLiveCoroutinesUnwindsAndExitsCleanly(): void
    {
        $projectRoot = dirname(__DIR__, 3);

        $childCode = <<<'PHP'
        require $argv[1] . '/vendor/autoload.php';

        use SConcur\Features\Sleeper\Sleeper;
        use SConcur\WaitGroup;

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: static function (): void {
                try {
                    Sleeper::sleep(seconds: 30);
                } finally {
                    fwrite(STDOUT, "FINALLY-RAN\n");
                }
            },
        );

        exit(7);
        PHP;

        $command = sprintf(
            'timeout 15 %s -d extension=%s -r %s %s 2>&1; echo "EXIT:$?"',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($projectRoot . '/ext/build/sconcur.so'),
            escapeshellarg($childCode),
            escapeshellarg($projectRoot),
        );

        $startTime = microtime(true);
        $output    = (string) shell_exec($command);
        $elapsed   = microtime(true) - $startTime;

        self::assertStringContainsString('FINALLY-RAN', $output);
        self::assertStringContainsString('EXIT:7', $output);
        // The 30-second sleeper must be cancelled, not awaited (15s would be the
        // `timeout` guard kicking in, 30s the sleep itself).
        self::assertLessThan(10, $elapsed);
    }
}
