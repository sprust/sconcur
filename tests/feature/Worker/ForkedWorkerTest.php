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
     * extension among them, and an exit() in the worker's role would leave without the
     * teardown that handle's destructor does — the servers stop accepting, the flows end,
     * the access log is flushed. So the worker lets the inherited handle go and makes one of
     * its own.
     */
    public function testAForkedWorkerMakesAnExtensionHandleOfItsOwn(): void
    {
        $output = PhpScript::run(
            code: <<<'PHP'
            require $argv[1] . '/vendor/autoload.php';

            use SConcur\Connection\Extension;
            use SConcur\Process\ProcessControl;
            use SConcur\Worker\ForkedWorker;
            use SConcur\Worker\WorkerContext;

            $inherited = Extension::get();

            $fork = ProcessControl::fork();

            if ($fork->isChild()) {
                ForkedWorker::run(
                    title: 'forked-worker-test',
                    cwd: sys_get_temp_dir(),
                    environment: [],
                    role: static function () use ($inherited): void {
                        echo json_encode([
                            'same'   => Extension::get() === $inherited,
                            'stable' => Extension::get() === Extension::get(),
                        ]);
                    },
                    context: new WorkerContext(group: 'test', slot: 0, masterPid: getmypid()),
                );
            }

            $deadline = microtime(true) + 5.0;

            while (ProcessControl::reap([$fork->pid]) === [] && microtime(true) < $deadline) {
                usleep(10_000);
            }

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
