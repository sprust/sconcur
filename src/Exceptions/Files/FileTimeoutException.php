<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Files;

/**
 * The operation's deadline ran out. It means the caller stopped waiting, not that the syscall was interrupted: see docs/files.md.
 */
class FileTimeoutException extends FilesException
{
}
