<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Files;

/**
 * The path is of the wrong kind: a directory where a file was wanted, a file where a directory was, or a directory that still holds entries.
 */
class UnexpectedFileTypeException extends FilesException
{
}
