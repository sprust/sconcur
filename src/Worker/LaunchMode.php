<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * How the master starts the workers of a group. Fork unless a group says otherwise: it is
 * the cheaper start, and it is what lets workers share an application loaded once.
 *
 * Exec runs a php process of its own per worker on the worker script: its own interpreter,
 * php.ini and -d flags.
 *
 * Fork forks the workers from the master itself; each worker then requires the worker
 * script. No interpreter starts per worker, and whatever the master's process loaded
 * before the master started — an application — is in every worker's memory and shared
 * rather than loaded again in each. In exchange the worker script runs inside a function
 * rather than in the global scope, and a worker runs with the master's php and its flags.
 */
enum LaunchMode: string
{
    case Exec = 'exec';
    case Fork = 'fork';
}
