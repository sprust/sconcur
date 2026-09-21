<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Files;

/**
 * The destination is already there and the mode forbids replacing it: a write or a copy
 * in FileWriteMode::Create, or makeDirectory() without recursive.
 *
 * Not raised by move(), which replaces — see Files::move() for why it cannot refuse.
 */
class FileAlreadyExistsException extends FilesException
{
}
