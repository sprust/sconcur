<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Worker;

use Closure;
use PHPUnit\Framework\TestCase;
use SConcur\Tests\Impl\Worker\TestWorkerMaster;

/**
 * What a program a worker starts gets from it: stdin, stdout and stderr, and nothing else.
 * Not the master's placeholders, not the heartbeat, not the script the worker runs. A
 * handler that runs `shell_exec` or starts a sidecar must not hand any of them on, and a
 * leak of a new kind fails here without a test of its own.
 *
 * Each worker lists the descriptors of a program it starts before it serves. Descriptors
 * the application opens itself are its own business and are not in play: the scripts here
 * open none.
 */
class WorkerDescriptorsTest extends TestCase
{
    protected string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/sc-worker-descriptors-' . uniqid('', true);

        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    public function testAProgramAWorkerStartsGetsNothingButItsStdio(): void
    {
        $root = dirname(__DIR__, 3);
        $port = TestWorkerMaster::freePort();

        $worker = $this->directory . '/worker.php';

        file_put_contents(
            $worker,
            "<?php\n" . $this->listingOfAStartedProgram('worker.txt')
            . 'require ' . var_export($root . '/tests/servers/http/http-server.php', true) . ";\n",
        );

        $options = [
            'workerCount'  => 1,
            'workerScript' => $worker,
            'accessLog'    => 'off',
        ];

        $master = TestWorkerMaster::start(
            options: $options,
            port: $port,
        );

        try {
            self::assertGreaterThan(0, $master->workerPid(), 'the worker should answer');

            $this->assertOnlyStdio(
                file: 'worker.txt',
                who: 'the worker',
            );
        } finally {
            $master->stop();
        }
    }

    /** A line of PHP that writes what a program it starts holds into $file. */
    protected function listingOfAStartedProgram(string $file): string
    {
        return sprintf(
            "file_put_contents(%s, (string) shell_exec('ls -l /proc/self/fd'));\n",
            var_export($this->directory . '/' . $file, true),
        );
    }

    protected function assertOnlyStdio(string $file, string $who): void
    {
        $path = $this->directory . '/' . $file;

        $listed = $this->waitFor(
            condition: static fn(): bool => is_file($path) && filesize($path) > 0,
            timeoutSeconds: 5.0,
        );

        self::assertTrue($listed, sprintf('a program %s started should have listed its descriptors', $who));

        $listing = (string) file_get_contents($path);

        $foreign = [];

        foreach (explode("\n", $listing) as $line) {
            if (preg_match('/ (\d+) -> (.+)$/', $line, $matches) !== 1) {
                continue;
            }

            // ls's own: the directory it lists.
            if ((int) $matches[1] <= 2 || preg_match('#^/proc/\d+/fd$#', $matches[2]) === 1) {
                continue;
            }

            $foreign[] = $matches[1] . ' -> ' . $matches[2];
        }

        self::assertSame(
            [],
            $foreign,
            sprintf("a program %s started inherited descriptors that are not its stdio:\n%s", $who, $listing),
        );
    }

    protected function waitFor(Closure $condition, float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return true;
            }

            usleep(20_000);
        }

        return $condition();
    }
}
