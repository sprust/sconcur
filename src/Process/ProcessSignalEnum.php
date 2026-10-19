<?php

declare(strict_types=1);

namespace SConcur\Process;

/**
 * The signals a worker master sends and watches, by name. The numbers differ between
 * platforms — USR1 is 10 on Linux and 30 on macOS — so the extension resolves them, and
 * PHP never needs the SIG* constants of ext-pcntl.
 *
 * @internal see ProcessControl
 */
enum ProcessSignalEnum: string
{
    case Hangup = 'HUP';

    case Interrupt = 'INT';

    case Quit = 'QUIT';

    case Kill = 'KILL';

    case User1 = 'USR1';

    case User2 = 'USR2';

    case Terminate = 'TERM';

    case Child = 'CHLD';
}
