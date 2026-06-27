<?php

declare(strict_types=1);

namespace SConcur\Exceptions\HttpServer;

use LogicException;

/**
 * An unsupported operation was attempted on the request body stream: it is
 * read-only and not seekable, so seek/rewind/write throw. A usage bug.
 */
class RequestBodyStreamException extends LogicException
{
}
