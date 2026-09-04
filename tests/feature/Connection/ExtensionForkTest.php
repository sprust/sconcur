<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Connection;

use SConcur\Features\Sleeper\Sleeper;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\WaitGroup;

/**
 * `pcntl_fork` after the extension has already run work.
 *
 * Only the forking thread survives into the child, so the runtime it inherits is
 * a shell with no worker threads behind it — a child that used it as-is would
 * hang. The core registers a `pthread_atfork` child handler that flags the
 * inherited state, and the next access rebuilds it.
 *
 * This is a capability the library documents, so it is asserted rather than
 * assumed: the failure it guards against is a child that never returns, which no
 * other test would catch.
 */
class ExtensionForkTest extends BaseTestCase
{
    public function testForkAfterFirstUseWorksInBothProcesses(): void
    {
        self::assertTrue(function_exists('pcntl_fork'), 'pcntl is required for this test');

        // First use, so the runtime is up and holding worker threads when the
        // fork happens. Forking before this would prove nothing.
        self::assertSame('before', $this->runOneTask('before'));

        $pid = pcntl_fork();

        self::assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            // The child exits with 0 only if the task actually came back. Any
            // hang here is caught by the parent's wait below timing out the run.
            $outcome = $this->runOneTask('child');

            exit($outcome === 'child' ? 0 : 1);
        }

        $status = 0;

        pcntl_waitpid($pid, $status);

        self::assertTrue(pcntl_wifexited($status), 'the child did not exit normally');
        self::assertSame(0, pcntl_wexitstatus($status), 'the child could not use the extension');

        // The parent's own runtime is untouched by the child's rebuild.
        self::assertSame('after', $this->runOneTask('after'));
    }

    /**
     * One coroutine through the extension and back, answering the label it was
     * given.
     */
    protected function runOneTask(string $label): string
    {
        $group = WaitGroup::create();

        $group->add(static function () use ($label): string {
            Sleeper::usleep(microseconds: 1_000);

            return $label;
        });

        foreach ($group->iterate() as $value) {
            return (string) $value;
        }

        return '';
    }
}
