<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * How the master starts the workers of a group. Fork unless a group says otherwise: it is
 * the cheaper start, and it is what lets workers share an application loaded once.
 *
 * Exec runs a php process of its own per worker on the worker script: its own interpreter,
 * php.ini and -d flags, and a reload that rolls the workers picks up new code from disk,
 * the library and the extension included.
 *
 * Fork starts a fork server for each generation of the group — a php of its own, run with
 * the group's phpBinary and phpArgs — which loads the group's bootstrap once (Composer's
 * autoloader when there is none) and forks the workers from itself; each worker then
 * requires the worker script. No interpreter starts per worker, and what the fork server
 * compiled and built while loading is shared rather than done again in each. A reload
 * starts a new fork server, so it picks up new application code, and the library code the
 * bootstrap's autoloader loads, from disk; the extension is whatever that php loads. In
 * exchange the worker script runs inside a function rather than in the global scope, and
 * every worker of a generation starts from what its fork server had loaded.
 */
enum LaunchMode: string
{
    case Exec = 'exec';
    case Fork = 'fork';
}
