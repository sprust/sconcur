<?php

declare(strict_types=1);

namespace SConcur\Telemetry\Dto;

/**
 * Queue-consumer workload section of a snapshot, the delivery counterpart of
 * Requests: a delivery is in flight from the moment the extension hands it to PHP
 * until the acknowledgement or the refusal comes back, so the buckets read the same
 * way and are exclusive. `coroutines` is how many consumers the worker has open — one
 * per coroutine — which is the capacity `inFlight` is spent out of, and `timed` how many
 * deliveries `avgMs` was measured over (an auto-acknowledged one has no handler time).
 * Field names mirror the Go schema (ext/internal/stats/snapshot.go).
 */
readonly class Consumers
{
    public function __construct(
        public int $coroutines,
        public int $delivered,
        public int $acked,
        public int $refused,
        public int $timed,
        public float $avgMs,
        public int $inFlight,
        public int $inFlight1to5s,
        public int $inFlight5to15s,
        public int $inFlightOver15s,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            coroutines: (int) ($data['coroutines'] ?? 0),
            delivered: (int) ($data['delivered'] ?? 0),
            acked: (int) ($data['acked'] ?? 0),
            refused: (int) ($data['refused'] ?? 0),
            timed: (int) ($data['timed'] ?? 0),
            avgMs: (float) ($data['avgMs'] ?? 0),
            inFlight: (int) ($data['inFlight'] ?? 0),
            inFlight1to5s: (int) ($data['inFlight1to5s'] ?? 0),
            inFlight5to15s: (int) ($data['inFlight5to15s'] ?? 0),
            inFlightOver15s: (int) ($data['inFlightOver15s'] ?? 0),
        );
    }

    /**
     * @return array<string, int|float>
     */
    public function toArray(): array
    {
        return [
            'coroutines'      => $this->coroutines,
            'delivered'       => $this->delivered,
            'acked'           => $this->acked,
            'refused'         => $this->refused,
            'timed'           => $this->timed,
            'avgMs'           => $this->avgMs,
            'inFlight'        => $this->inFlight,
            'inFlight1to5s'   => $this->inFlight1to5s,
            'inFlight5to15s'  => $this->inFlight5to15s,
            'inFlightOver15s' => $this->inFlightOver15s,
        ];
    }
}
