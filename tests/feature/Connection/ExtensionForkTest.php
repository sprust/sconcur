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
     * A fork of a fork, which is the case the registration is easy to get wrong:
     * `pthread_atfork` is registered once per process image, and a child inherits
     * the registration rather than having to repeat it.
     *
     * The grandchild is the one that proves it. It is two rebuilds deep — the
     * child rebuilt the runtime it inherited, used it, and only then forked — so
     * a registration that did not survive the first fork would leave the
     * grandchild with a runtime that has no worker threads and never answers.
     */
    public function testForkOfAForkWorksTwoRebuildsDeep(): void
    {
        self::assertTrue(function_exists('pcntl_fork'), 'pcntl is required for this test');
        self::assertSame('parent', $this->runOneTask('parent'));

        $pid = pcntl_fork();

        self::assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            // Use the extension first, so the child forks with a runtime of its
            // own rather than the inherited shell.
            $childOutcome = $this->runOneTask('child');

            $grandchildPid = pcntl_fork();

            if ($grandchildPid === 0) {
                exit($this->runOneTask('grandchild') === 'grandchild' ? 0 : 1);
            }

            $grandchildStatus = 0;

            pcntl_waitpid($grandchildPid, $grandchildStatus);

            // The child has to keep working after forking, too.
            $stillWorks = $this->runOneTask('child-again') === 'child-again';

            exit(
                $childOutcome === 'child'
                && $stillWorks
                && pcntl_wifexited($grandchildStatus)
                && pcntl_wexitstatus($grandchildStatus) === 0
                    ? 0
                    : 1
            );
        }

        $status = 0;

        pcntl_waitpid($pid, $status);

        self::assertTrue(pcntl_wifexited($status), 'the child did not exit normally');
        self::assertSame(0, pcntl_wexitstatus($status), 'a fork of a fork could not use the extension');

        self::assertSame('parent-again', $this->runOneTask('parent-again'));
    }

    /**
     * Forking before the extension has run anything: nothing has started, so each
     * child gets a clean slate rather than an inherited runtime to rebuild.
     */
    public function testForkBeforeFirstUseGivesTheChildACleanSlate(): void
    {
        self::assertTrue(function_exists('pcntl_fork'), 'pcntl is required for this test');

        $pid = pcntl_fork();

        self::assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            exit($this->runOneTask('fresh') === 'fresh' ? 0 : 1);
        }

        $status = 0;

        pcntl_waitpid($pid, $status);

        self::assertSame(0, pcntl_wexitstatus($status), 'the child could not start the extension');
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
