<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Files;

use RuntimeException;

/**
 * The base of every failure that happens while the extension works with a file.
 *
 * It extends RuntimeException because a missing file, a refused permission and a full
 * disk are runtime conditions whatever the caller does — the project's rule for the kind
 * (.ai/README.md, "Exceptions"). The usage mistakes of this feature extend
 * LogicException instead and do not descend from here.
 */
class FilesException extends RuntimeException
{
}
