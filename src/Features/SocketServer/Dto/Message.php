<?php

declare(strict_types=1);

namespace SConcur\Features\SocketServer\Dto;

/**
 * One inbound message (a single length-prefixed frame) of a socket connection,
 * passed to the server handler. The handler returns a string (a response frame), a
 * MessageResponse (to also close the connection), or null (no reply).
 */
readonly class Message
{
    public function __construct(
        public string $connectionId,
        public string $data,
        public string $remoteAddr,
        public string $localAddr,
        public int $messageIndex,
    ) {
    }
}
