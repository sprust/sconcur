<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Files;

/**
 * More was asked for than the call's limit allows, so the extension refused rather than
 * spending the memory.
 *
 * Two cases. A read whose requested range is over $maxReadBytes — the range, not the
 * file, so a small range out of a huge file is fine; raise the limit or use
 * Files::readChunks(), which holds one buffer whatever the size. And a line over
 * $maxLineBytes in Files::readLines(), where the remedy is the limit itself: a file with
 * no newline in it cannot be read line by line.
 */
class FileTooLargeException extends FilesException
{
}
