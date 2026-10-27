<?php

declare(strict_types=1);

namespace SConcur\Telemetry\Dto;

/**
 * Process memory of one snapshot, PHP and the extension together: the resident set
 * size, its proportional share (Pss) and the same share of swap (SwapPss).
 *
 * A page shared with other processes — what a worker inherited from its master, the
 * libraries — counts whole in the RSS of every process mapping it, and is divided
 * among them in Pss. So Pss sums across a pool to the memory the pool takes, which RSS
 * does not. It is not a leak signal: under memory pressure the kernel drops clean file
 * pages, and Pss falls while nothing was freed.
 *
 * There is no split between PHP and the extension, and the extension does not offer
 * one: attributing a resident page to either side would need a tracking allocator.
 * Field names mirror the extension's schema (ext/src/stats/mod.rs) so the JSON on the
 * wire round-trips unchanged.
 */
readonly class Memory
{
    public function __construct(
        public int $rssBytes,
        public int $pssBytes,
        public int $swapPssBytes,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            rssBytes: (int) ($data['rssBytes'] ?? 0),
            pssBytes: (int) ($data['pssBytes'] ?? 0),
            swapPssBytes: (int) ($data['swapPssBytes'] ?? 0),
        );
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'rssBytes'     => $this->rssBytes,
            'pssBytes'     => $this->pssBytes,
            'swapPssBytes' => $this->swapPssBytes,
        ];
    }
}
