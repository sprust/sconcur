<?php

declare(strict_types=1);

namespace SConcur\Exceptions\File;

use RuntimeException;

/**
 * A runtime file-operation failure (open/read/write/seek/...): the path could not
 * be opened, a read/write failed, or the handle was used in a way its mode forbids.
 */
class FileException extends RuntimeException
{
}
