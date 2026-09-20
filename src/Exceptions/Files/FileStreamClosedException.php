<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Files;

/**
 * The stream this handle names is gone: closed already, cut off mid-chunk, or released
 * when the coroutine that opened it ended.
 *
 * Covers both directions, which is why it is not named after the writer: a readChunks()
 * or walk() whose state has been released raises the same case.
 */
class FileStreamClosedException extends FilesException
{
}
