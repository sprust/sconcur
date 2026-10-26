<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\Worker;

use SConcur\Process\ProcessControl;
use SConcur\Tests\Impl\PhpScript;

/**
 * Runs PHP code the way a worker master runs an exec'd worker: a php of its own with the
 * extension loaded, a pipe on ProcessControl::HEARTBEAT_FD and its number in the
 * environment. Answers what the code printed, stderr included, and the marks it put on the
 * pipe — the bytes as they came, in order, which a master's WorkerProcess does not keep.
 *
 * PHP cannot learn the number of a descriptor it holds, so a pipe of the test's own cannot
 * be handed to the extension in this process; a child that finds it on descriptor 3, as a
 * worker does, is how it gets there.
 */
class SupervisedScript
{
    /**
     * @param list<string>          $arguments   $argv[1] onwards
     * @param bool                  $heartbeat   false starts it as no master would: descriptor 3
     *                                           on /dev/null and no variable naming it, unless
     *                                           $environment sets one
     * @param array<string, string> $environment what the environment adds
     *
     * @return array{output: string, marks: string}
     */
    public static function run(
        string $code,
        array $arguments = [],
        bool $heartbeat = true,
        array $environment = [],
        int $timeoutSeconds = 30,
    ): array {
        $inherited = getenv();

        unset($inherited[ProcessControl::HEARTBEAT_FD_ENVIRONMENT_NAME]);

        if ($heartbeat) {
            $inherited[ProcessControl::HEARTBEAT_FD_ENVIRONMENT_NAME] = (string) ProcessControl::HEARTBEAT_FD;
        }

        $pipes = [];

        // timeout -k: a scenario running a server takes the SIGTERM timeout sends as a
        // graceful stop and may carry on, so a SIGKILL follows it.
        $process = proc_open(
            command: [
                'timeout',
                '-k',
                '5',
                (string) $timeoutSeconds,
                PHP_BINARY,
                '-d',
                'extension=' . PhpScript::extensionPath(),
                '-r',
                $code,
                ...$arguments,
            ],
            descriptor_spec: [
                0                            => ['file', '/dev/null', 'r'],
                1                            => ['pipe', 'w'],
                2                            => ['redirect', 1],
                ProcessControl::HEARTBEAT_FD => $heartbeat ? ['pipe', 'w'] : ['file', '/dev/null', 'w'],
            ],
            pipes: $pipes,
            env_vars: [...$inherited, ...$environment],
        );

        if (!is_resource($process)) {
            return [
                'output' => 'the script could not be started',
                'marks'  => '',
            ];
        }

        // Its output to the end first: the marks are a few bytes, and the pipe holds them
        // until the script is gone.
        $output = (string) stream_get_contents($pipes[1]);
        $marks  = isset($pipes[ProcessControl::HEARTBEAT_FD])
            ? (string) stream_get_contents($pipes[ProcessControl::HEARTBEAT_FD])
            : '';

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        proc_close($process);

        return [
            'output' => $output,
            'marks'  => $marks,
        ];
    }
}
