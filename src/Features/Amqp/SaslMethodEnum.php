<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

/**
 * How the broker authenticates the connection. The values are what the wire carries.
 */
enum SaslMethodEnum: int
{
    /** Login and password, the default everywhere. */
    case Plain = 0;

    /**
     * The client certificate is the identity: the broker takes the user name from it and
     * no password is sent. Needs TLS with a certificate and key — see docs/amqp.md.
     */
    case External = 1;
}
