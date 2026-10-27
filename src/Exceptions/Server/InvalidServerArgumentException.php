<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Server;

use LogicException;

/**
 * A server was built from CLI argv with an unknown flag or a value that does not
 * match the constructor parameter's type, or given an address that cannot work (a
 * malformed fd://, or reusePort beside one). A usage bug in how the server was launched.
 */
class InvalidServerArgumentException extends LogicException
{
}
