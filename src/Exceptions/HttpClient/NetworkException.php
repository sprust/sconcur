<?php

declare(strict_types=1);

namespace SConcur\Exceptions\HttpClient;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * The request could not be completed because of a network problem: connection
 * refused, DNS failure, connection reset, or a timeout (PSR-18
 * NetworkExceptionInterface). The original request is always available.
 */
class NetworkException extends RuntimeException implements NetworkExceptionInterface
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
