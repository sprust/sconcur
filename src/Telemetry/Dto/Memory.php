<?php

declare(strict_types=1);

namespace SConcur\Telemetry\Dto;

/**
 * Process memory split of one snapshot. Field names mirror the Go schema
 * (ext/internal/stats/snapshot.go) so the JSON on the wire round-trips unchanged.
 */
readonly class Memory
{
    public function __construct(
        public int $rssBytes,
        public int $goRuntimeBytes,
        public int $nonExtensionBytes,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            rssBytes: (int) ($data['rssBytes'] ?? 0),
            goRuntimeBytes: (int) ($data['goRuntimeBytes'] ?? 0),
            nonExtensionBytes: (int) ($data['nonExtensionBytes'] ?? 0),
        );
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'rssBytes'          => $this->rssBytes,
            'goRuntimeBytes'    => $this->goRuntimeBytes,
            'nonExtensionBytes' => $this->nonExtensionBytes,
        ];
    }
}
