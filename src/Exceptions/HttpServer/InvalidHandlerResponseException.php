<?php

declare(strict_types=1);

namespace SConcur\Exceptions\HttpServer;

use LogicException;

/**
 * A request handler returned something other than a PSR-7 ResponseInterface. A
 * usage bug: the handler contract is Closure(ServerRequestInterface): ResponseInterface.
 */
class InvalidHandlerResponseException extends LogicException
{
}
