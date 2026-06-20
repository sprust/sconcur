<?php

declare(strict_types=1);

namespace SConcur\Features\SocketServer\Dto;

/**
 * A richer return value from a socket handler: send a response frame and optionally
 * close the connection afterwards. Return a plain string instead to reply without
 * closing, or null for no reply.
 */
readonly class MessageResponse
{
    public function __construct(
        public string $data = '',
        public bool $close = false,
    ) {
    }
}
