<?php

namespace SConcur\Features;

enum MethodEnum: string
{
    case Unknown     = 'unk';
    case Sleep       = 'sl';
    case Mongodb     = 'mng';
    case HttpServe   = 'hs';
    case HttpRespond = 'hr';
    case HttpClient  = 'hc';
    case Mysql       = 'my';
    case Pgsql       = 'pg';
    case SocketServe   = 'ss';
    case SocketRespond = 'sr';
    case SocketClient  = 'sc';
    case WsServe   = 'wss';
    case WsRespond = 'wsr';
    case WsClient  = 'wsc';
}
