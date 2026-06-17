<?php

declare(strict_types=1);

namespace SConcur\Features\Sql\Results;

use SConcur\Transport\MessagePackTransport;

/**
 * Result of an Exec command: rows affected and the last auto-increment id.
 *
 * Go side encodes `{ar: affectedRows, li: lastInsertId}`; a driver that does not
 * report one of them sends 0.
 */
readonly class ExecResult
{
    public function __construct(
        public int $affectedRows,
        public int $lastInsertId,
    ) {
    }

    public static function fromPayload(string $payload): self
    {
        $decoded = $payload === '' ? [] : MessagePackTransport::unpack($payload);

        return new self(
            affectedRows: (int) ($decoded['ar'] ?? 0),
            lastInsertId: (int) ($decoded['li'] ?? 0),
        );
    }
}
