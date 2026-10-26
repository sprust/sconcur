<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\PhpScript;

/**
 * What ForkedWorker makes of the copy of its master a fork hands it, before the worker
 * script runs.
 */
class ForkedWorkerTest extends BaseTestCase
{
    /**
     * The fork marks the master's objects destroyed in the worker, its handle on the
     * extension among them, and an exit() in the worker script would leave without the
     * teardown that handle's destructor does — the servers stop accepting, the flows end,
     * the access log is flushed. So the worker lets the inherited handle go and makes one of
     * its own, as a php started on the script would.
     */
    public function testAForkedWorkerMakesAnExtensionHandleOfItsOwn(): void
    {
        $output = PhpScript::run(
            code: <<<'PHP'
            require $argv[1] . '/vendor/autoload.php';

            use SConcur\Connection\Extension;
            use SConcur\Process\ProcessControl;
            use SConcur\Worker\ForkedWorker;

            $inherited = Extension::get();

            $script = (string) tempnam(sys_get_temp_dir(), 'forked-worker-');

            file_put_contents($script, <<<'WORKER'
            <?php
            echo json_encode([
                'same'   => SConcur\Connection\Extension::get() === $GLOBALS['inherited'],
                'stable' => SConcur\Connection\Extension::get() === SConcur\Connection\Extension::get(),
            ]);
            WORKER);

            $fork = ProcessControl::fork();

            if ($fork->isChild()) {
                ForkedWorker::run(
                    title: 'forked-worker-test',
                    cwd: sys_get_temp_dir(),
                    environment: [],
                    argv: [$script],
                );
            }

            $deadline = microtime(true) + 5.0;

            while (ProcessControl::reap([$fork->pid]) === [] && microtime(true) < $deadline) {
                usleep(10_000);
            }

            unlink($script);

            echo stream_get_contents($fork->stdout);
            PHP,
            arguments: [PhpScript::projectRoot()],
        );

        $answer = json_decode($output, true);

        self::assertIsArray($answer, 'the worker printed no JSON: ' . $output);
        self::assertFalse($answer['same'], 'the worker should not keep its master\'s handle');
        self::assertTrue($answer['stable'], 'the worker\'s own handle should be one for the life of the worker');
    }
}
