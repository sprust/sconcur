<?php

declare(strict_types=1);

namespace SConcur\Process;

/**
 * The commands of the extension's process export: what a worker master asks of the
 * operating system instead of ext-pcntl and ext-posix.
 *
 * Every case names the Rust struct its parameters are decoded into; the parameters are
 * flat short-key maps built in ProcessControl, the same reasoning as AmqpCommandEnum.
 *
 * Rust: the command values matched in ext/src/process/mod.rs.
 *
 * @internal see ProcessControl
 */
enum ProcessCommandEnum: string
{
    /** Forks this process. Rust: payloads::ForkParams. */
    case Fork = 'frk';

    /** Leaves the process through _exit, after its output is written. Rust: payloads::ExitParams. */
    case Exit = 'ext';

    /** Sends a signal to a process. Rust: payloads::KillParams. */
    case Kill = 'kil';

    /** Collects the children that have exited, without waiting. Rust: payloads::ReapParams. */
    case Reap = 'rap';

    /** The pid of this process's parent. No parameters. */
    case ParentPid = 'ppd';

    /** Starts recording signals. Rust: payloads::WatchParams. */
    case WatchSignals = 'sgw';

    /** The recorded signals, cleared as they are handed over. No parameters. */
    case TakeSignals = 'sgt';

    /** Puts back what was installed for the watched signals before. No parameters. */
    case RestoreSignals = 'sgr';

    /** Binds a listening socket for the workers of a master to share, answers its descriptor. Rust: payloads::BindParams. */
    case Bind = 'bnd';

    /** Closes a descriptor the extension handed over. Rust: payloads::CloseParams. */
    case Close = 'cls';

    /**
     * Closes the descriptors a fork handed over once PHP has opened its streams on copies,
     * and makes those copies close-on-exec. Rust: payloads::AdoptedParams.
     */
    case Adopted = 'adp';

    /** Refuses as a fork would, without forking. No parameters. */
    case CheckFork = 'cfk';

    /** The descriptors above stderr this process has open. No parameters. */
    case OpenDescriptors = 'fds';

    /** Whether this process has a heartbeat pipe to its master. No parameters. */
    case HasHeartbeat = 'hbq';

    /** Writes one mark on the heartbeat pipe. Rust: payloads::MarkParams. */
    case MarkHeartbeat = 'hbw';

    /** Marks this process as a worker master's, which runs nothing asynchronous, or no longer. Rust: payloads::MasterParams. */
    case MarkMaster = 'mst';
}
