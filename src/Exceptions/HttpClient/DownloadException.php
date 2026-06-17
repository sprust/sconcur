<?php

declare(strict_types=1);

namespace SConcur\Exceptions\HttpClient;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;
use Throwable;

/**
 * A HttpClient::download() failure: a non-success (non-2xx) response, or a
 * transport/file error while downloading. For a non-2xx response getStatusCode()
 * returns that status; for a transport/file error it is null and the cause is the
 * previous exception.
 */
class DownloadException extends RuntimeException implements ClientExceptionInterface
{
    public function __construct(
        string $message,
        protected ?int $statusCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            code: 0,
            previous: $previous,
        );
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }
}
