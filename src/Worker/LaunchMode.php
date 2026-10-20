<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * How the master starts a worker of a group. Fork unless a group says otherwise: it is
 * the cheaper start, and it is what lets workers share an application loaded once.
 *
 * Exec runs a php process of its own on the worker script: its own interpreter, its own
 * php.ini and -d flags, and a reload that rolls the workers picks up new code from disk,
 * the library and the extension included.
 *
 * Fork copies the master, which already runs php with the extension loaded, and the copy
 * requires the worker script: no interpreter to start and no extension to load, so a
 * worker is up sooner, and the code the master has compiled is shared rather than
 * compiled again per worker. In exchange a fork group runs on the master's interpreter
 * and php.ini, and a reload picks up new application code only — what the master itself
 * loaded, the library and the extension, stays as it was until the master restarts.
 */
enum LaunchMode: string
{
    case Exec = 'exec';
    case Fork = 'fork';
}
