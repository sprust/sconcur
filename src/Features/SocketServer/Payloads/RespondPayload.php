<?php

declare(strict_types=1);

namespace SConcur\Features\SocketServer\Payloads;

use SConcur\Features\MethodEnum;
use SConcur\Transport\PayloadInterface;

/**
 * One write a message-handler coroutine sends back for a given message: either a
 * response frame, or a no-reply acknowledgement (skip). Either may additionally
 * close the connection. The op field tells the Go side which.
 *
 * Go: payloads.RespondPayload (ext/internal/features/socketserver/payloads/payloads.go).
 */
readonly class RespondPayload implements PayloadInterface
{
    /** Write one length-prefixed response frame (data may be an empty frame). */
    public const int OP_FRAME = 0;

    /** No reply: just acknowledge the message (disarms the Go handler timer). */
    public const int OP_SKIP = 1;

    private function __construct(
        private string $connectionId,
        private int $op,
        private bool $close,
        private string $data,
    ) {
    }

    /**
     * A response frame for the message. With $close the connection is closed right
     * after the frame is written.
     */
    public static function frame(string $connectionId, string $data, bool $close = false): self
    {
        return new self(
            connectionId: $connectionId,
            op: self::OP_FRAME,
            close: $close,
            data: $data,
        );
    }

    /**
     * No reply for the message (acknowledge only). With $close the connection is
     * closed.
     */
    public static function skip(string $connectionId, bool $close = false): self
    {
        return new self(
            connectionId: $connectionId,
            op: self::OP_SKIP,
            close: $close,
            data: '',
        );
    }

    /**
     * Close the connection (no frame written). Used to end the connection's message
     * loop.
     */
    public static function close(string $connectionId): self
    {
        return new self(
            connectionId: $connectionId,
            op: self::OP_SKIP,
            close: true,
            data: '',
        );
    }

    public function getMethod(): MethodEnum
    {
        return MethodEnum::SocketRespond;
    }

    /**
     * @return array<string, int|string|bool>
     */
    public function getData(): array
    {
        return [
            'cid' => $this->connectionId,
            'op'  => $this->op,
            'cl'  => $this->close,
            'dt'  => $this->data,
        ];
    }
}
