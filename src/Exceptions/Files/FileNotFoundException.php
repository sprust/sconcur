<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Files;

/**
 * The path does not exist. Raised for a read, a copy source, a stat that had to find something, and every other operation that cannot invent what it was asked for.
 */
class FileNotFoundException extends FilesException
{
}
