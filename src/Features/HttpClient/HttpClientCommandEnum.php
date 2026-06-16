<?php

declare(strict_types=1);

namespace SConcur\Features\HttpClient;

/**
 * Sub-operations of the HTTP-client feature, carried in the payload envelope (the
 * `cm` field) under the single MethodEnum::HttpClient — mirrors how MongoDB uses
 * CommandEnum under MethodEnum::Mongodb.
 *
 * Go: types.HttpClientCommand (ext/internal/types/httpclient.go).
 */
enum HttpClientCommandEnum: int
{
    /** Open a request (buffered body, or the start of a streamed-body upload). */
    case Request = 1;

    /** Append a chunk to a streamed request body. */
    case UploadChunk = 2;

    /** Close a streamed request body: no more chunks. */
    case UploadEnd = 3;
}
