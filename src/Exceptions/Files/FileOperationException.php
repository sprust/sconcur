<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Files;

/**
 * Any other input-output failure — a full disk, a broken device, a quota. The
 * extension's own text is the message; the cause is in getPrevious().
 */
class FileOperationException extends FilesException
{
}
