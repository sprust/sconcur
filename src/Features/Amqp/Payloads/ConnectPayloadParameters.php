<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Transport\PayloadParametersInterface;

/**
 * Parameters of a Connect command: the broker credentials, the TLS material and every
 * tuning value ext-amqp keeps on AMQPConnection. Carries the mandatory execution
 * bounds of a long-lived resource — the connect deadline plus the read, write and RPC
 * deadlines every later command on this connection is bounded by.
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
        protected int $readTimeoutMs,
        protected int $writeTimeoutMs,
        protected int $rpcTimeoutMs,
        protected int $channelMax,
        protected int $frameMaxBytes,
        protected int $heartbeatSeconds,
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
            'rt' => $this->readTimeoutMs,
            'wt' => $this->writeTimeoutMs,
            'rc' => $this->rpcTimeoutMs,
            'cx' => $this->channelMax,
            'fx' => $this->frameMaxBytes,
            'hb' => $this->heartbeatSeconds,
            'ca' => $this->caCertPath,
            'ce' => $this->certPath,
            'ke' => $this->keyPath,
            'vf' => $this->verify,
            'sm' => $this->saslMethod,
            'cn' => $this->connectionName,
        ];
    }
}
