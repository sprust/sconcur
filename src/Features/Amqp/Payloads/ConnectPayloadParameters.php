<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of a Connect command: the broker credentials, the TLS material and every
 * tuning value a connection carries. The execution bound it carries is
 * connectTimeoutMs, which bounds the dial.
 *
 * The connection's other three deadlines — read, write and RPC — are not here: each of
 * them bounds a command rather than the connection, so ConnectionOptions puts them on the
 * commands themselves (RPC on every one-shot method, write on a publish, read on the wait
 * for a consumer's next delivery).
 *
 * Go: payloads.ConnectParams (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class ConnectPayloadParameters implements PayloadParametersInterface
{
    public function __construct(
        protected string $host,
        protected int $port,
        protected string $vhost,
        protected string $login,
        protected string $password,
        protected int $connectTimeoutMs,
        protected int $channelMax,
        protected int $frameMaxBytes,
        protected int $heartbeatSeconds,
        protected bool $secure,
        protected ?string $caCertPath,
        protected ?string $certPath,
        protected ?string $keyPath,
        protected bool $verify,
        protected int $saslMethod,
        protected ?string $connectionName,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return [
            'ho' => $this->host,
            'po' => $this->port,
            'vh' => $this->vhost,
            'lg' => $this->login,
            'pw' => $this->password,
            'ct' => $this->connectTimeoutMs,
            'cx' => $this->channelMax,
            'fx' => $this->frameMaxBytes,
            'hb' => $this->heartbeatSeconds,
            'sc' => $this->secure,
            'ca' => $this->caCertPath,
            'ce' => $this->certPath,
            'ke' => $this->keyPath,
            'vf' => $this->verify,
            'sm' => $this->saslMethod,
            'cn' => $this->connectionName,
        ];
    }
}
