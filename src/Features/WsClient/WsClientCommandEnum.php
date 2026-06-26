<?php

declare(strict_types=1);

namespace SConcur\Features\WsClient;

/**
 * Sub-operations of the ws-client feature, carried in the payload envelope (the `cm`
 * field) under the single MethodEnum::WsClient — mirrors SocketClientCommandEnum.
 *
 * Go: types.WsClientCommand (ext/internal/types/wsclient.go).
 */
enum WsClientCommandEnum: int
{
    /** Dial the remote ws:// URL and open a streaming connection. */
    case Connect = 1;

    /** Push one message (text or binary) to the peer. */
    case Send = 2;

    /** Close the connection. */
    case Close = 3;
}
