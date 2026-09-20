<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Files;

/**
 * The file is bigger than the read limit the call carried, so the extension refused to
 * read it instead of spending the memory twice — once in the extension, once in PHP.
 *
 * Raise the limit on the call, or read the file in batches with Files::readChunks().
 */
class FileTooLargeException extends FilesException
{
}
