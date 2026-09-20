<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Files;

/**
 * The writer or the stream this handle names is gone: closed already, or released when
 * the coroutine that opened it ended.
 */
class FileWriterClosedException extends FilesException
{
}
