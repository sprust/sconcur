<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

/**
 * Runs PHP code in a process of its own with the extension loaded, and answers what it
 * printed, stderr included.
 *
 * For what has to start from a process that has not used the extension's runtime yet: a
 * fork, a thread count, a teardown. The PHPUnit process has used it long before most
 * tests run.
 */
class PhpScript
{
    /**
     * @param list<string> $arguments $argv[1] onwards
     */
    public static function run(string $code, array $arguments = [], int $timeoutSeconds = 30): string
    {
        $command = sprintf(
            'timeout %d %s -d extension=%s -r %s %s 2>&1',
            $timeoutSeconds,
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::extensionPath()),
            escapeshellarg($code),
            implode(' ', array_map('escapeshellarg', $arguments)),
        );

        return (string) shell_exec($command);
    }

    public static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function extensionPath(): string
    {
        return getenv('SCONCUR_EXT') ?: self::projectRoot() . '/ext/build/sconcur.so';
    }
}
