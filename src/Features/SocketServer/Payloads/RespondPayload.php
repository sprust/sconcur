<?php

declare(strict_types=1);

namespace SConcur\Features\SocketServer\Payloads;

use SConcur\Features\MethodEnum;
use SConcur\Transport\PayloadInterface;

/**
 * One action a connection handler performs on its connection: write a frame to the
 * client, or close the connection. The op field tells the Go side which.
 *
 * Go: payloads.RespondPayload (ext/internal/features/socketserver/payloads/payloads.go).
 */
readonly class RespondPayload implements PayloadInterface
{
    /** Write one length-prefixed frame to the client (data may be an empty frame). */
    public const int OP_FRAME = 0;

    /** Close the connection. */
    public const int OP_CLOSE = 1;

    private function __construct(
        private string $connectionId,
        private int $op,
        private string $data,
    ) {
    }

    public static function frame(string $connectionId, string $data): self
    {
        return new self(
            connectionId: $connectionId,
            op: self::OP_FRAME,
            data: $data,
        );
    }

    public static function close(string $connectionId): self
    {
        return new self(
            connectionId: $connectionId,
            op: self::OP_CLOSE,
            data: '',
        );
    }

    public function getMethod(): MethodEnum
    {
        return MethodEnum::SocketRespond;
    }

    /**
     * @return array<string, int|string>
     */
    public function getData(): array
    {
        return [
            'cid' => $this->connectionId,
            'op'  => $this->op,
            'dt'  => $this->data,
        ];
    }
}
