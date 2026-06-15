<?php

declare(strict_types=1);

namespace SConcur\Exceptions\HttpClient;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * The request is malformed and could not be sent (e.g. an invalid URL or method),
 * so it never left the client (PSR-18 RequestExceptionInterface). The offending
 * request is always available.
 */
class RequestException extends RuntimeException implements RequestExceptionInterface
{
    public function __construct(
        protected readonly RequestInterface $request,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
