<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Files;

/**
 * The process may not do this to the path: the permission bits, an ownership or a mount option refused it.
 */
class FilePermissionException extends FilesException
{
}
