<?php

declare(strict_types=1);

namespace SConcur\Exceptions\HttpServer;

use LogicException;

/**
 * A request handler returned something other than a Response. A usage bug: the
 * handler contract is Closure(Request): Response.
 */
class InvalidHandlerResponseException extends LogicException
{
}
