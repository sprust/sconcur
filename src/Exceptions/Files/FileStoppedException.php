<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Files;

/**
 * The flow was stopped while the operation was running — WaitGroup::stop(), an early
 * break, or the shutdown that unwinds live coroutines.
 *
 * Its own class rather than a plain operation failure: nothing went wrong with the
 * filesystem, and a caller retrying on an input-output error should not retry on this.
 */
class FileStoppedException extends FilesException
{
}
