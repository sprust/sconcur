<?php

declare(strict_types=1);

namespace SConcur\Features\HttpServer\Dto;

use SConcur\Features\FeatureExecutor;
use SConcur\Features\HttpServer\Payloads\RespondPayload;

/**
 * The writer handed to a StreamedResponse's closure. Each write() pushes one body
 * chunk to the client and only returns once Go has flushed it — natural write
 * backpressure, so a fast producer cannot outrun a slow client. The response head
 * is sent by the framework before the writer runs; this object only appends body.
 */
readonly class ResponseStream
{
    public function __construct(
        private string $requestId,
    ) {
    }

    /**
     * Sends one body chunk and waits for it to be flushed to the client. An empty
     * chunk is a no-op (nothing to flush).
     */
    public function write(string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        FeatureExecutor::exec(
            payload: RespondPayload::chunk($this->requestId, $chunk),
        );
    }
}
