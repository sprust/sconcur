<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Files;

/**
 * The path does not exist. Raised for a read, a copy source, and every other operation
 * that cannot invent what it was asked for.
 *
 * Not raised by stat(), which answers exists: false — finding nothing is its answer
 * rather than its failure.
 */
class FileNotFoundException extends FilesException
{
}
