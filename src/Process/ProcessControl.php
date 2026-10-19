<?php

declare(strict_types=1);

namespace SConcur\Process;

use SConcur\Connection\Extension;
use SConcur\Exceptions\Process\ProcessException;
use SConcur\Process\Dto\ForkResult;
use SConcur\Process\Dto\ProcessExit;

/**
 * What a worker master asks of the operating system — fork a worker, reap it, signal it,
 * watch its own signals, find its parent — through the extension, so neither ext-pcntl
 * nor ext-posix is needed.
 *
 * Every call is synchronous and starts no runtime. A fork is refused once the
 * extension's runtime runs in this process, or any other thread does: a fork keeps only
 * the calling thread, and a lock another one held would stay held in the child.
 *
 * The child is prepared before it is handed back: stdin from /dev/null, stdout and
 * stderr onto pipes the parent reads, every other descriptor above stderr pointed at
 * /dev/null unless it is kept, the parent's signal watching undone, the random
 * generators reseeded, and the parent's objects marked as destroyed so their
 * destructors never run in a worker.
 *
 * @internal the tool of the worker master and of the long-lived workers it runs, not a
 *           public API
 */
class ProcessControl
{
    /**
     * Forks this process. The answer comes back twice: in the parent with the child's pid
     * and pipes, in the child with a pid of 0. The child continues from here and leaves
     * through exit().
     *
     * @param list<int> $keepDescriptors the descriptors above stderr the child keeps as they
     *                                   are, such as a listening socket
     * @param bool      $captureOutput   the child's stdout and stderr become pipes the parent
     *                                   reads; otherwise the child writes where the parent does
     * @param bool      $heartbeat       a third pipe, for the child's liveness mark
     */
    public static function fork(
        array $keepDescriptors = [],
        bool $captureOutput = true,
        bool $heartbeat = false,
    ): ForkResult {
        /** @var array{pid: int, out?: int, err?: int, hb: int} $answer */
        $answer = Extension::get()->process(
            command: ProcessCommandEnum::Fork,
            payload: [
                'kp' => $keepDescriptors,
                'io' => $captureOutput,
                'hb' => $heartbeat,
            ],
        );

        if ($answer['pid'] === 0) {
            return new ForkResult(
                pid: 0,
                heartbeat: self::adopt(
                    descriptor: $answer['hb'],
                    mode: 'w',
                ),
            );
        }

        return new ForkResult(
            pid: $answer['pid'],
            stdout: self::adopt(
                descriptor: $answer['out'] ?? -1,
                mode: 'r',
            ),
            stderr: self::adopt(
                descriptor: $answer['err'] ?? -1,
                mode: 'r',
            ),
            heartbeat: self::adopt(
                descriptor: $answer['hb'],
                mode: 'r',
            ),
        );
    }

    /**
     * Leaves this process after writing out what it printed, without PHP's shutdown: the
     * shutdown functions and destructors a worker would run are its master's, inherited
     * with its memory.
     */
    public static function exit(int $code): never
    {
        Extension::get()->process(
            command: ProcessCommandEnum::Exit,
            payload: ['cd' => $code],
        );

        throw new ProcessException(message: 'process: exit returned');
    }

    /**
     * Sends a signal. False when there is no such process.
     */
    public static function kill(int $pid, ProcessSignalEnum $signal): bool
    {
        return (bool) Extension::get()->process(
            command: ProcessCommandEnum::Kill,
            payload: [
                'pid' => $pid,
                'sg'  => $signal->value,
            ],
        );
    }

    /**
     * The children among $pids that have exited, without waiting for the others.
     *
     * @param list<int> $pids
     *
     * @return list<ProcessExit>
     */
    public static function reap(array $pids): array
    {
        /** @var list<array{pid: int, cd: int|null, sg: int|null}> $exited */
        $exited = Extension::get()->process(
            command: ProcessCommandEnum::Reap,
            payload: ['pids' => $pids],
        );

        return array_map(
            static fn(array $outcome): ProcessExit => new ProcessExit(
                pid: $outcome['pid'],
                exitCode: $outcome['cd'],
                signal: $outcome['sg'],
            ),
            $exited,
        );
    }

    /**
     * The pid of this process's parent. Once the parent dies the child is handed to init
     * or a subreaper, so a worker tells that its master is gone by this changing.
     */
    public static function parentPid(): int
    {
        return (int) Extension::get()->process(command: ProcessCommandEnum::ParentPid);
    }

    /**
     * Starts recording the given signals; takeSignals() hands over what arrived. No PHP
     * code runs inside a signal handler.
     *
     * @param list<ProcessSignalEnum> $signals
     */
    public static function watchSignals(array $signals): void
    {
        Extension::get()->process(
            command: ProcessCommandEnum::WatchSignals,
            payload: [
                'sg' => array_map(
                    static fn(ProcessSignalEnum $signal): string => $signal->value,
                    $signals,
                ),
            ],
        );
    }

    /**
     * The watched signals that arrived since the last call, each once.
     *
     * @return list<ProcessSignalEnum>
     */
    public static function takeSignals(): array
    {
        /** @var list<string> $names */
        $names = Extension::get()->process(command: ProcessCommandEnum::TakeSignals);

        return array_map(
            static fn(string $name): ProcessSignalEnum => ProcessSignalEnum::from($name),
            $names,
        );
    }

    /**
     * Puts back what was installed for each watched signal before.
     */
    public static function restoreSignals(): void
    {
        Extension::get()->process(command: ProcessCommandEnum::RestoreSignals);
    }

    /**
     * How many threads this process runs, or null where the platform cannot say cheaply.
     */
    public static function threadCount(): ?int
    {
        $count = Extension::get()->process(command: ProcessCommandEnum::ThreadCount);

        return is_int($count) ? $count : null;
    }

    /**
     * Opens a descriptor the extension handed over as a non-blocking stream. php://fd
     * opens a copy of it, so the original is closed here.
     *
     * @return resource|null
     */
    protected static function adopt(int $descriptor, string $mode): mixed
    {
        if ($descriptor < 0) {
            return null;
        }

        $stream = @fopen('php://fd/' . $descriptor, $mode);

        Extension::get()->process(
            command: ProcessCommandEnum::Close,
            payload: ['fd' => $descriptor],
        );

        if ($stream === false) {
            throw new ProcessException(
                message: sprintf(
                    'process: the descriptor %d the fork handed over cannot be opened',
                    $descriptor,
                ),
            );
        }

        stream_set_blocking($stream, false);

        return $stream;
    }
}
