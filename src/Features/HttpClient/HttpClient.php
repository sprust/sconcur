<?php

declare(strict_types=1);

namespace SConcur\Features\HttpClient;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use SConcur\Exceptions\HttpClient\HttpClientException;
use SConcur\Exceptions\HttpClient\NetworkException;
use SConcur\Exceptions\HttpClient\RequestException;
use SConcur\Dto\TaskResultDto;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\HttpClient\Dto\ResponseBodyStream;
use SConcur\Features\HttpClient\Payloads\RequestPayload;
use SConcur\Features\HttpClient\Payloads\RequestPayloadParameters;
use SConcur\Features\HttpClient\Payloads\UploadChunkPayload;
use SConcur\Features\HttpClient\Payloads\UploadEndPayload;
use SConcur\Transport\MessagePackTransport;
use Throwable;

/**
 * Asynchronous, streaming PSR-18 HTTP client. The whole network round-trip (DNS,
 * connect, TLS, send, read) lives in the Go extension; the request is run in a
 * goroutine while the calling coroutine suspends, so dozens of requests fan out
 * concurrently. Outside a WaitGroup the same call works synchronously.
 *
 * The response body is a streaming ResponseBodyStream — it is never buffered whole
 * in the extension. See .ai/plans/http-client.md.
 */
readonly class HttpClient implements ClientInterface
{
    /** Error-class markers the Go side prefixes onto its error payloads. */
    protected const string NETWORK_MARKER = 'net:';
    protected const string REQUEST_MARKER = 'req:';

    public function __construct(
        protected ResponseFactoryInterface $responseFactory,
        protected HttpClientOptions $options = new HttpClientOptions(),
    ) {
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        try {
            $result = $this->options->streamRequestBody
                ? $this->sendStreaming($request)
                : FeatureExecutor::exec(
                    payload: $this->buildPayload(
                        request: $request,
                        body: (string) $request->getBody(),
                        streamBody: false,
                        requestId: '',
                    ),
                );
        } catch (Throwable $exception) {
            throw $this->toClientException(
                exception: $exception,
                request: $request,
            );
        }

        return $this->buildResponse($result);
    }

    /**
     * Streams the request body to Go in chunks instead of buffering it whole: open
     * the request (Go starts the round-trip with a pipe as its body), push the body
     * chunk by chunk (each write blocks until Go consumes it — backpressure), end
     * the body, then pull the response metadata. Mirrors the HTTP-server's write
     * commands in reverse.
     */
    protected function sendStreaming(RequestInterface $request): TaskResultDto
    {
        $requestId = uniqid('up_', more_entropy: true);

        $open = FeatureExecutor::exec(
            payload: $this->buildPayload(
                request: $request,
                body: '',
                streamBody: true,
                requestId: $requestId,
            ),
        );

        $body = $request->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            $chunk = $body->read($this->options->chunkSize);

            if ($chunk === '') {
                break;
            }

            FeatureExecutor::exec(
                payload: new UploadChunkPayload(
                    requestId: $requestId,
                    body: $chunk,
                ),
            );
        }

        FeatureExecutor::exec(payload: new UploadEndPayload($requestId));

        // The response status+headers are only known once the body is fully sent.
        return FeatureExecutor::next(taskKey: $open->key);
    }

    protected function buildResponse(TaskResultDto $result): ResponseInterface
    {
        /** @var array<string, mixed> $meta */
        $meta = MessagePackTransport::unpack($result->payload);

        $response = $this->responseFactory->createResponse((int) ($meta['st'] ?? 200));

        foreach ($this->normalizeHeaders($meta['hd'] ?? []) as $name => $values) {
            $response = $response->withHeader($name, $values);
        }

        $contentLength = (int) ($meta['cl'] ?? -1);

        $body = new ResponseBodyStream(
            firstChunk: (string) ($meta['b'] ?? ''),
            bodyKey: $result->hasNext ? $result->key : '',
            size: $contentLength >= 0 ? $contentLength : null,
        );

        return $response->withBody($body);
    }

    protected function buildPayload(
        RequestInterface $request,
        string $body,
        bool $streamBody,
        string $requestId,
    ): RequestPayload {
        return new RequestPayload(
            new RequestPayloadParameters(
                method: $request->getMethod(),
                url: (string) $request->getUri(),
                headers: $request->getHeaders(),
                body: $body,
                streamBody: $streamBody,
                requestId: $requestId,
                requestTimeoutMs: $this->options->requestTimeoutMs,
                connectTimeoutMs: $this->options->connectTimeoutMs,
                responseHeaderTimeoutMs: $this->options->responseHeaderTimeoutMs,
                maxResponseBody: $this->options->maxResponseBody,
                followRedirects: $this->options->followRedirects,
                maxRedirects: $this->options->maxRedirects,
                chunkSize: $this->options->chunkSize,
                verifyTls: $this->options->verifyTls,
                maxIdleConns: $this->options->maxIdleConns,
                maxIdleConnsPerHost: $this->options->maxIdleConnsPerHost,
                idleConnTimeoutMs: $this->options->idleConnTimeoutMs,
                tlsHandshakeTimeoutMs: $this->options->tlsHandshakeTimeoutMs,
            ),
        );
    }

    /**
     * An empty header map decodes to stdClass (a MessagePack quirk), and nested
     * values may too; normalize to array<string, array<int, string>>.
     *
     * @return array<string, array<int, string>>
     */
    protected function normalizeHeaders(mixed $headers): array
    {
        $normalized = [];

        foreach ((array) $headers as $name => $values) {
            $normalized[(string) $name] = array_values((array) $values);
        }

        return $normalized;
    }

    /**
     * Maps an extension failure to the right PSR-18 exception by the marker the Go
     * side prefixed onto the error payload (net/req), defaulting to a generic
     * client error. The marker may sit on a wrapped exception, so the whole chain
     * is inspected.
     */
    protected function toClientException(Throwable $exception, RequestInterface $request): ClientExceptionInterface
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $message = $current->getMessage();

            if (str_starts_with($message, self::NETWORK_MARKER)) {
                return new NetworkException(
                    request: $request,
                    message: $message,
                    previous: $exception,
                );
            }

            if (str_starts_with($message, self::REQUEST_MARKER)) {
                return new RequestException(
                    request: $request,
                    message: $message,
                    previous: $exception,
                );
            }
        }

        return new HttpClientException(
            message: $exception->getMessage(),
            code: 0,
            previous: $exception,
        );
    }
}
