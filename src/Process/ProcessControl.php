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
 * nor ext-posix is needed; and the heartbeat its workers mark themselves on.
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
     * @param bool      $heartbeat       a third pipe, for the child's marks: the parent reads
     *                                   it, the child writes it through markHeartbeat()
     */
    public static function fork(
        array $keepDescriptors = [],
        bool $captureOutput = true,
        bool $heartbeat = false,
    ): ForkResult {
        /** @var array{pid: int, out?: int, err?: int, hb?: int} $answer */
        $answer = Extension::get()->process(
            command: ProcessCommandEnum::Fork,
            payload: [
                'kp' => $keepDescriptors,
                'io' => $captureOutput,
                'hb' => $heartbeat,
            ],
        );

        // The child's end of the heartbeat stays with the extension, which writes the marks.
        if ($answer['pid'] === 0) {
            return new ForkResult(pid: 0);
        }

        $descriptors = array_filter(
            [
                'out' => $answer['out'] ?? -1,
                'err' => $answer['err'] ?? -1,
                'hb'  => $answer['hb'] ?? -1,
            ],
            static fn(int $descriptor): bool => $descriptor >= 0,
        );

        try {
            $streams = self::adopt($descriptors);
        } catch (ProcessException $exception) {
            self::abandon($answer['pid']);

            throw $exception;
        }

        return new ForkResult(
            pid: $answer['pid'],
            stdout: $streams['out'] ?? null,
            stderr: $streams['err'] ?? null,
            heartbeat: $streams['hb'] ?? null,
        );
    }

    /**
     * Whether this process has a heartbeat pipe to a master: whether it is a child of
     * fork(heartbeat: true). The extension settles it in the fork, and the answer stands.
     */
    public static function hasHeartbeat(): bool
    {
        return (bool) Extension::get()->process(command: ProcessCommandEnum::HasHeartbeat);
    }

    /**
     * Marks this process as a worker master's. From here on an asynchronous call in it is
     * refused before it starts the extension's runtime: every worker is a fork of the
     * master, and a process running the runtime's threads cannot fork. A fork's child is
     * no master.
     */
    public static function markMaster(): void
    {
        Extension::get()->process(
            command: ProcessCommandEnum::MarkMaster,
            payload: ['on' => true],
        );
    }

    /**
     * Undoes markMaster() once the master has stopped: the process that ran it is the
     * application's again, and may make asynchronous calls — at the cost of never forking
     * a worker after the first one.
     */
    public static function forgetMaster(): void
    {
        Extension::get()->process(
            command: ProcessCommandEnum::MarkMaster,
            payload: ['on' => false],
        );
    }

    /**
     * Writes one mark for the master to read, and answers whether it was written. Not
     * written is not a failure: there is no heartbeat, the master stopped reading and the
     * pipe is full, or the master is gone.
     */
    public static function markHeartbeat(HeartbeatMarkEnum $mark): bool
    {
        return (bool) Extension::get()->process(
            command: ProcessCommandEnum::MarkHeartbeat,
            payload: ['mk' => $mark->value],
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
     * Sends a signal. False when there is no such process. A pid of 0 or below is refused
     * (see assertChildPid()).
     */
    public static function kill(int $pid, ProcessSignalEnum $signal): bool
    {
        self::assertChildPid($pid);

        return (bool) Extension::get()->process(
            command: ProcessCommandEnum::Kill,
            payload: [
                'pid' => $pid,
                'sg'  => $signal->value,
            ],
        );
    }

    /**
     * The children among $pids that have exited, without waiting for the others. A list
     * with a pid of 0 or below in it is refused whole, before any child is reaped (see
     * assertChildPid()).
     *
     * @param list<int> $pids
     *
     * @return list<ProcessExit>
     */
    public static function reap(array $pids): array
    {
        foreach ($pids as $pid) {
            self::assertChildPid($pid);
        }

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
     * Refuses as fork() would in this process, without forking: a process that already
     * runs the extension's runtime, or threads of anything else, cannot fork. The refusal
     * is a ForkRefusedException, and says how many threads the process runs where the
     * platform can count them.
     */
    public static function assertForkable(): void
    {
        Extension::get()->process(command: ProcessCommandEnum::CheckFork);
    }

    /**
     * The descriptors above stderr this process has open, in no particular order.
     *
     * @return list<int>
     */
    public static function openDescriptors(): array
    {
        /** @var list<int> $descriptors */
        $descriptors = Extension::get()->process(command: ProcessCommandEnum::OpenDescriptors);

        return $descriptors;
    }

    /**
     * Refuses a pid that names no single process before it reaches the system: to kill(2)
     * 0 is this process's whole group — a master included — and -1 every process it may
     * signal; to waitpid(2) both are any child, which would be reported under the pid
     * asked for. A child's ForkResult has a pid of 0, so one that reached the wrong side
     * of the fork would do exactly that. The extension refuses them too.
     */
    protected static function assertChildPid(int $pid): void
    {
        if ($pid <= 0) {
            throw new ProcessException(
                message: sprintf('process: pid %d is not a child', $pid),
            );
        }
    }

    /**
     * Opens the descriptors the extension handed over as non-blocking streams. php://fd
     * opens a copy of each made by dup(2), which does not carry close-on-exec over, so the
     * extension closes the originals and puts the flag on the copies — in one call, which
     * looks for the copies among what was opened since the fork: nothing this process
     * execs inherits the pipes. The originals are closed whatever fails, and so are the
     * streams already opened.
     *
     * @param array<string, int> $descriptors
     *
     * @return array<string, resource>
     */
    protected static function adopt(array $descriptors): array
    {
        if ($descriptors === []) {
            return [];
        }

        $streams = array_map(
            static fn(int $descriptor): mixed => @fopen('php://fd/' . $descriptor, 'r'),
            $descriptors,
        );

        try {
            Extension::get()->process(
                command: ProcessCommandEnum::Adopted,
                payload: ['fds' => array_values($descriptors)],
            );

            foreach ($streams as $key => $stream) {
                if ($stream === false) {
                    throw new ProcessException(
                        message: sprintf(
                            'process: the descriptor %d the fork handed over cannot be opened',
                            $descriptors[$key],
                        ),
                    );
                }
            }
        } catch (ProcessException $exception) {
            foreach ($streams as $stream) {
                if ($stream !== false) {
                    fclose($stream);
                }
            }

            throw $exception;
        }

        foreach ($streams as $stream) {
            stream_set_blocking($stream, false);
        }

        return $streams;
    }

    /**
     * Undoes a fork whose pipes could not be taken: a child the caller is never told
     * about would serve unwatched, keep what it listens on, and outlive the master as a
     * stray. It is killed and reaped; adopt() has closed the pipes to it.
     */
    protected static function abandon(int $pid): void
    {
        self::kill(
            pid: $pid,
            signal: ProcessSignalEnum::Kill,
        );

        // SIGKILL leaves no say to the child, so it is gone within milliseconds. Bounded all
        // the same: the caller is the master's loop.
        $deadline = hrtime(true) + 1_000_000_000;

        while (self::reap([$pid]) === [] && hrtime(true) < $deadline) {
            usleep(1_000);
        }
    }
}
