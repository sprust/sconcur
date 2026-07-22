<?php

declare(strict_types=1);

namespace SConcur\Features\WsClient;

/**
 * Sub-operations of the ws-client feature, carried in the payload envelope (the `cm`
 * field) under the single MethodEnum::WsClient — mirrors SocketClientCommandEnum.
 *
 * Go: types.WsClientCommand (ext/internal/types/wsclient.go).
 */
enum WsClientCommandEnum: string
{
    /** Dial the remote ws:// URL and open a streaming connection. */
    case Connect = 'con';

    /** Push one message (text or binary) to the peer. */
    case Send = 'snd';

    /** Close the connection. */
    case Close = 'cls';
}
