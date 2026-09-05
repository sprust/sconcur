<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

/**
 * The TLS material of a connection. Passing any of it turns the connection into an
 * `amqps` one; a DSN with the `amqps` scheme builds an instance with the defaults.
 *
 * The paths are read by the extension side, in the process that dials the broker — a container
 * that runs the worker must have the files, not the one that built the options.
 */
readonly class TlsOptions
{
    /**
     * @param ?string $caCert path to the CA bundle the broker's certificate is checked
     *                        against; null uses the system trust store
     * @param ?string $cert   path to the client certificate, for SASL EXTERNAL
     * @param ?string $key    path to the client certificate's private key
     * @param bool    $verify whether the broker's certificate and host name are checked;
     *                        false is for a self-signed broker in development and turns
     *                        the connection into an unauthenticated one
     */
    public function __construct(
        public ?string $caCert = null,
        public ?string $cert = null,
        public ?string $key = null,
        public bool $verify = true,
    ) {
    }
}
