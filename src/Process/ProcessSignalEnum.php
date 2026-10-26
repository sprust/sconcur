<?php

declare(strict_types=1);

namespace SConcur\Process;

/**
 * The signals a worker master and its tests send and watch, by name. The numbers differ
 * between platforms — CHLD is 17 on Linux and 20 on macOS — so the extension resolves
 * them, and PHP never needs the SIG* constants of ext-pcntl.
 *
 * The names the extension knows are the table in ext/src/process/signals.rs, which is the
 * authority: a case here is one of them that something sends or watches, and a name the
 * table does not have is refused there.
 *
 * @internal see ProcessControl
 */
enum ProcessSignalEnum: string
{
    case Interrupt = 'INT';

    case Kill = 'KILL';

    case Terminate = 'TERM';

    /** Nothing acts on it by default: what a test sends where a signal must harm nobody. */
    case Child = 'CHLD';
}
