<?php

declare(strict_types=1);

namespace SConcur\Telemetry;

use Closure;
use SConcur\Telemetry\Dto\Aggregate;
use SConcur\Telemetry\Render\HtmlRenderer;
use SConcur\Telemetry\Render\JsonRenderer;
use SConcur\Telemetry\Render\PrometheusRenderer;

/**
 * The telemetry panel's serve side: a non-blocking HTTP/1.1 + SSE server that
 * answers GET /api/stats (content-negotiated JSON / Prometheus / HTML), GET / (the
 * live HTML panel, meta-refreshing) and GET /events (an SSE stream of the aggregate
 * JSON). Auth is a Bearer token (also accepted as ?token= so a browser can open the
 * panel); a bad or missing token is 404 to hide the endpoint, a non-GET is 405.
 *
 * Every socket is non-blocking and output is buffered per client and flushed on
 * writability — the master's supervision loop is never blocked on a slow client. SSE
 * is lossy: a client whose buffer would grow unbounded is dropped.
 */
class PanelServer
{
    protected const int READ_CHUNK_BYTES = 8_192;

    protected const int MAX_REQUEST_BYTES = 16_384;

    protected const int MAX_CLIENTS = 64;

    protected const int MAX_CLIENT_BUFFER_BYTES = 1_048_576;

    /** @var null|resource the TCP listener, or null until start() */
    protected $listener = null;

    /** @var array<int, resource> client id => stream */
    protected array $clients = [];

    /** @var array<int, string> client id => unparsed request buffer */
    protected array $inputs = [];

    /** @var array<int, string> client id => pending output buffer */
    protected array $outputs = [];

    /** @var array<int, bool> client id => true once it is an open SSE stream */
    protected array $sse = [];

    /** @var array<int, bool> client id => true once the response is fully queued (close after flush) */
    protected array $closing = [];

    protected JsonRenderer $jsonRenderer;

    protected PrometheusRenderer $prometheusRenderer;

    protected HtmlRenderer $htmlRenderer;

    /**
     * @param null|Closure(string): void $logError
     */
    public function __construct(
        protected readonly int $port,
        protected readonly string $token,
        protected readonly string $name,
        protected readonly Store $store,
        protected readonly Aggregator $aggregator,
        protected readonly ?Closure $logError = null,
    ) {
        $this->jsonRenderer       = new JsonRenderer();
        $this->prometheusRenderer = new PrometheusRenderer();
        $this->htmlRenderer       = new HtmlRenderer();
    }

    /**
     * Binds the TCP panel listener in non-blocking mode. Returns false on failure —
     * telemetry is optional and must never take the master down.
     */
    public function start(): bool
    {
        $listener = @stream_socket_server('tcp://0.0.0.0:' . $this->port, $errno, $errstr);

        if ($listener === false) {
            $this->log(sprintf('telemetry panel bind :%d failed: %s', $this->port, $errstr));

            return false;
        }

        stream_set_blocking($listener, false);

        $this->listener = $listener;

        return true;
    }

    /**
     * @return array<int, resource>
     */
    public function readStreams(): array
    {
        $streams = [];

        if ($this->listener !== null) {
            $streams[(int) $this->listener] = $this->listener;
        }

        foreach ($this->clients as $id => $client) {
            $streams[$id] = $client;
        }

        return $streams;
    }

    /**
     * @return array<int, resource>
     */
    public function writeStreams(): array
    {
        $streams = [];

        foreach ($this->clients as $id => $client) {
            if ($this->outputs[$id] !== '') {
                $streams[$id] = $client;
            }
        }

        return $streams;
    }

    public function owns(int $streamId): bool
    {
        if ($this->listener !== null && (int) $this->listener === $streamId) {
            return true;
        }

        return isset($this->clients[$streamId]);
    }

    /**
     * @param resource $stream
     */
    public function onReadable($stream): void
    {
        if ($this->listener !== null && (int) $stream === (int) $this->listener) {
            $this->accept();

            return;
        }

        $this->readClient($stream);
    }

    /**
     * @param resource $stream
     */
    public function onWritable($stream): void
    {
        $id = (int) $stream;

        if (!isset($this->clients[$id]) || $this->outputs[$id] === '') {
            return;
        }

        $written = @fwrite($stream, $this->outputs[$id]);

        if ($written === false) {
            $this->dropClient($id);

            return;
        }

        $this->outputs[$id] = substr($this->outputs[$id], $written);

        if ($this->outputs[$id] === '' && ($this->closing[$id] ?? false)) {
            $this->dropClient($id);
        }
    }

    /**
     * Appends the current pool aggregate as one SSE event to every open SSE client.
     * Called periodically by the runtime. Lossy: a client whose buffer is already
     * over the cap is dropped rather than grown.
     */
    public function pushSse(int $nowMs): void
    {
        if ($this->sse === []) {
            return;
        }

        $event = 'data: ' . $this->jsonRenderer->render($this->buildAggregate($nowMs)) . "\n\n";

        foreach (array_keys($this->sse) as $id) {
            $this->queue($id, $event);
        }
    }

    public function stop(): void
    {
        foreach (array_keys($this->clients) as $id) {
            $this->dropClient($id);
        }

        if ($this->listener !== null) {
            fclose($this->listener);

            $this->listener = null;
        }
    }

    protected function accept(): void
    {
        if ($this->listener === null) {
            return;
        }

        $client = @stream_socket_accept($this->listener, 0);

        if ($client === false) {
            return;
        }

        if (count($this->clients) >= self::MAX_CLIENTS) {
            fclose($client);

            return;
        }

        stream_set_blocking($client, false);

        $id = (int) $client;

        $this->clients[$id] = $client;
        $this->inputs[$id]  = '';
        $this->outputs[$id] = '';
    }

    /**
     * @param resource $stream
     */
    protected function readClient($stream): void
    {
        $id    = (int) $stream;
        $chunk = @fread($stream, self::READ_CHUNK_BYTES);

        if ($chunk === false || ($chunk === '' && feof($stream))) {
            $this->dropClient($id);

            return;
        }

        $this->inputs[$id] .= $chunk;

        if (strlen($this->inputs[$id]) > self::MAX_REQUEST_BYTES) {
            $this->dropClient($id);

            return;
        }

        $headerEnd = strpos($this->inputs[$id], "\r\n\r\n");

        if ($headerEnd === false) {
            return;
        }

        $this->handleRequest($id, substr($this->inputs[$id], 0, $headerEnd));
    }

    protected function handleRequest(int $id, string $head): void
    {
        $lines     = explode("\r\n", $head);
        $firstLine = $lines[0];
        $parts     = explode(' ', $firstLine);

        $method = $parts[0];
        $target = $parts[1] ?? '';

        $queryStart = strpos($target, '?');
        $path       = $queryStart === false ? $target : substr($target, 0, $queryStart);
        $query      = $queryStart === false ? '' : substr($target, $queryStart + 1);

        if (!$this->authorized($lines, $query)) {
            $this->respond($id, 404, 'text/plain; charset=utf-8', "not found\n");

            return;
        }

        if ($method !== 'GET') {
            $this->respond($id, 405, 'text/plain; charset=utf-8', "method not allowed\n");

            return;
        }

        if ($path === '/events') {
            $this->startSse($id);

            return;
        }

        $nowMs     = $this->nowMs();
        $aggregate = $this->buildAggregate($nowMs);

        if ($path === '/') {
            $body = $this->htmlRenderer->render($aggregate, '/?token=' . rawurlencode($this->token));

            $this->respond($id, 200, $this->htmlRenderer->contentType(), $body);

            return;
        }

        if ($path === '/api/stats') {
            $this->respondNegotiated($id, $aggregate, $lines);

            return;
        }

        $this->respond($id, 404, 'text/plain; charset=utf-8', "not found\n");
    }

    /**
     * @param list<string> $headerLines
     */
    protected function respondNegotiated(int $id, Aggregate $aggregate, array $headerLines): void
    {
        $accept = $this->header($headerLines, 'accept');

        if (str_contains($accept, 'application/json')) {
            $this->respond($id, 200, $this->jsonRenderer->contentType(), $this->jsonRenderer->render($aggregate));

            return;
        }

        if (str_contains($accept, 'text/html')) {
            $this->respond($id, 200, $this->htmlRenderer->contentType(), $this->htmlRenderer->render($aggregate));

            return;
        }

        $this->respond($id, 200, $this->prometheusRenderer->contentType(), $this->prometheusRenderer->render($aggregate));
    }

    /**
     * @param list<string> $headerLines
     */
    protected function authorized(array $headerLines, string $query): bool
    {
        if ($this->token === '') {
            return false;
        }

        $authorization = $this->header($headerLines, 'authorization');
        $provided      = '';

        if (str_starts_with($authorization, 'Bearer ')) {
            $provided = substr($authorization, strlen('Bearer '));
        } elseif ($query !== '') {
            parse_str($query, $params);

            $provided = is_string($params['token'] ?? null) ? $params['token'] : '';
        }

        return $provided !== '' && hash_equals($this->token, $provided);
    }

    /**
     * @param list<string> $headerLines
     */
    protected function header(array $headerLines, string $name): string
    {
        $prefix = strtolower($name) . ':';

        foreach ($headerLines as $line) {
            if (str_starts_with(strtolower($line), $prefix)) {
                return trim(substr($line, strlen($prefix)));
            }
        }

        return '';
    }

    protected function startSse(int $id): void
    {
        $this->queue(
            $id,
            "HTTP/1.1 200 OK\r\n"
            . "Content-Type: text/event-stream\r\n"
            . "Cache-Control: no-cache\r\n"
            . "Connection: keep-alive\r\n"
            . "\r\n",
        );

        $this->sse[$id] = true;

        $this->queue($id, 'data: ' . $this->jsonRenderer->render($this->buildAggregate($this->nowMs())) . "\n\n");
    }

    protected function respond(int $id, int $status, string $contentType, string $body): void
    {
        $reason = match ($status) {
            200     => 'OK',
            400     => 'Bad Request',
            404     => 'Not Found',
            405     => 'Method Not Allowed',
            default => 'OK',
        };

        $response = 'HTTP/1.1 ' . $status . ' ' . $reason . "\r\n"
            . 'Content-Type: ' . $contentType . "\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $body;

        $this->closing[$id] = true;

        $this->queue($id, $response);
    }

    protected function queue(int $id, string $data): void
    {
        if (!isset($this->outputs[$id])) {
            return;
        }

        if (strlen($this->outputs[$id]) + strlen($data) > self::MAX_CLIENT_BUFFER_BYTES) {
            // The client cannot keep up: drop it rather than grow the buffer unbounded.
            $this->dropClient($id);

            return;
        }

        $this->outputs[$id] .= $data;
    }

    protected function buildAggregate(int $nowMs): Aggregate
    {
        return $this->aggregator->aggregate($this->store->all(), $this->name, $nowMs, gmdate('c', intdiv($nowMs, 1000)));
    }

    protected function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    protected function dropClient(int $id): void
    {
        if (isset($this->clients[$id])) {
            fclose($this->clients[$id]);
        }

        unset($this->clients[$id], $this->inputs[$id], $this->outputs[$id], $this->sse[$id], $this->closing[$id]);
    }

    protected function log(string $message): void
    {
        if ($this->logError !== null) {
            ($this->logError)($message);
        }
    }
}
