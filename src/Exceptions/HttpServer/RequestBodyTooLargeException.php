<?php

declare(strict_types=1);

namespace SConcur\Exceptions\HttpServer;

use RuntimeException;

/**
 * The request body exceeded the server's maxRequestBody while being read. The
 * server maps this to a 413 response when the handler has not started replying.
 */
class RequestBodyTooLargeException extends RuntimeException
{
}
