<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Files;

/**
 * The destination is already there and the mode forbids replacing it (FileWriteMode::Create, a move onto an existing path).
 */
class FileAlreadyExistsException extends FilesException
{
}
