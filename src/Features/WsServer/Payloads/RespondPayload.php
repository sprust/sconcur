<?php

declare(strict_types=1);

namespace SConcur\Features\WsServer\Payloads;

use SConcur\Features\MethodEnum;
use SConcur\Transport\PayloadInterface;

/**
 * One action a connection handler performs on its connection: write a message to the
 * client (text or binary), or close the connection. The op field tells the Go side
 * which; messageType selects the WebSocket message type of a written message.
 *
 * Go: payloads.RespondPayload (ext/internal/features/wsserver/payloads/payloads.go).
 */
readonly class RespondPayload implements PayloadInterface
{
    /** Write one message to the client (data may be empty). */
    public const int OP_FRAME = 0;

    /** Close the connection. */
    public const int OP_CLOSE = 1;

    /** A UTF-8 text message. */
    public const int TYPE_TEXT = 0;

    /** A binary message (carries any bytes). */
    public const int TYPE_BINARY = 1;

    private function __construct(
        private string $connectionId,
        private int $op,
        private int $messageType,
        private string $data,
    ) {
    }

    public static function frame(string $connectionId, string $data, bool $binary = false): self
    {
        return new self(
            connectionId: $connectionId,
            op: self::OP_FRAME,
            messageType: $binary ? self::TYPE_BINARY : self::TYPE_TEXT,
            data: $data,
        );
    }

    public static function close(string $connectionId): self
    {
        return new self(
            connectionId: $connectionId,
            op: self::OP_CLOSE,
            messageType: self::TYPE_TEXT,
            data: '',
        );
    }

    public function getMethod(): MethodEnum
    {
        return MethodEnum::WsRespond;
    }

    /**
     * @return array<string, int|string>
     */
    public function getData(): array
    {
        return [
            'cid' => $this->connectionId,
            'op'  => $this->op,
            'mt'  => $this->messageType,
            'dt'  => $this->data,
        ];
    }
}
