<?php

declare(strict_types=1);

namespace SConcur\Telemetry\Dto;

/**
 * Process memory of one snapshot: the worker's resident set size, PHP and the
 * extension together.
 *
 * There is no split between the two, and the extension does not offer one:
 * attributing a resident page to either side would need a tracking allocator.
 * Field names mirror the extension's schema (ext/src/stats/mod.rs) so the JSON on
 * the wire round-trips unchanged.
 */
readonly class Memory
{
    public function __construct(
        public int $rssBytes,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            rssBytes: (int) ($data['rssBytes'] ?? 0),
        );
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'rssBytes' => $this->rssBytes,
        ];
    }
}
