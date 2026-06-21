<?php

declare(strict_types=1);

namespace SConcur\Features\SocketClient;

/**
 * Sub-operations of the socket-client feature, carried in the payload envelope (the
 * `cm` field) under the single MethodEnum::SocketClient — mirrors how the HTTP client
 * uses HttpClientCommandEnum under MethodEnum::HttpClient.
 *
 * Go: types.SocketClientCommand (ext/internal/types/socketclient.go).
 */
enum SocketClientCommandEnum: int
{
    /** Dial the remote address and open a streaming connection. */
    case Connect = 1;

    /** Push one length-prefixed frame to the peer. */
    case Send = 2;

    /** Close the connection. */
    case Close = 3;
}
