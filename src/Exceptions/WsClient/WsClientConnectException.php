<?php

declare(strict_types=1);

namespace SConcur\Exceptions\WsClient;

use RuntimeException;

/**
 * Dialing a ws-client connection failed (connection refused, DNS failure, handshake
 * failure or a connect timeout). A runtime failure thrown from WsClient::connect(); the
 * Go side tags the cause with a "net:" marker, preserved in the message.
 */
class WsClientConnectException extends RuntimeException
{
}
