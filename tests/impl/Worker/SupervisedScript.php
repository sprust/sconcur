<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl\Worker;

use SConcur\Tests\Impl\PhpScript;

/**
 * Runs PHP code the way a worker master runs a worker: in a fork, with the heartbeat pipe
 * the fork made for it (tests/servers/worker/supervised.php). Answers what the code
 * printed, stderr included, and the marks it put on the pipe — the bytes as they came, in
 * order, which a master's WorkerProcess does not keep.
 */
class SupervisedScript
{
    /**
     * @param list<string>          $arguments   $argv[1] onwards
     * @param bool                  $heartbeat   false forks it as no master would: without a
     *                                           heartbeat pipe
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
        $codeFile = (string) tempnam(sys_get_temp_dir(), 'sc-supervised-');

        file_put_contents($codeFile, $code);

        $pipes = [];

        // timeout -k: a scenario running a server takes the SIGTERM timeout sends as a
        // graceful stop and may carry on, so a SIGKILL follows it. timeout signals its whole
        // process group, the forked code included.
        $process = proc_open(
            command: [
                'timeout',
                '-k',
                '5',
                (string) $timeoutSeconds,
                PHP_BINARY,
                '-d',
                'extension=' . PhpScript::extensionPath(),
                PhpScript::projectRoot() . '/tests/servers/worker/supervised.php',
                $codeFile,
                $heartbeat ? '1' : '0',
                ...$arguments,
            ],
            descriptor_spec: [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['redirect', 1],
            ],
            pipes: $pipes,
            env_vars: [...getenv(), ...$environment],
        );

        if (!is_resource($process)) {
            @unlink($codeFile);

            return [
                'output' => 'the script could not be started',
                'marks'  => '',
            ];
        }

        $printed = (string) stream_get_contents($pipes[1]);

        fclose($pipes[1]);

        proc_close($process);

        @unlink($codeFile);

        $answer = json_decode($printed, true);

        if (!is_array($answer)) {
            return [
                'output' => $printed,
                'marks'  => '',
            ];
        }

        return [
            'output' => (string) $answer['output'],
            'marks'  => (string) hex2bin((string) $answer['marks']),
        ];
    }
}
