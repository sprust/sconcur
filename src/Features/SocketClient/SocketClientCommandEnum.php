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
enum SocketClientCommandEnum: string
{
    /** Dial the remote address and open a streaming connection. */
    case Connect = 'con';

    /** Push one length-prefixed frame to the peer. */
    case Send = 'snd';

    /** Close the connection. */
    case Close = 'cls';
}
