<?php

declare(strict_types=1);

namespace SConcur\Exceptions\HttpClient;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * A generic HTTP-client failure that is neither a network nor a request error
 * (PSR-18 ClientExceptionInterface). Note that a 4xx/5xx response is NOT an
 * error — it is a normal ResponseInterface.
 */
class HttpClientException extends RuntimeException implements ClientExceptionInterface
{
}
