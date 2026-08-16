<?php

declare(strict_types=1);

/**
 * Payload used by the *-payload-* benchmarks: a base64 string (valid UTF-8 for
 * TEXT columns, incompressible enough to defeat block compression) of exactly
 * SCONCUR_BENCH_PAYLOAD_BYTES bytes (default 1024). Built once per process so
 * generation never lands inside the measured phase.
 */
function benchmarkPayload(): string
{
    $payloadBytes = (int) (getenv('SCONCUR_BENCH_PAYLOAD_BYTES') ?: 1024);

    $encoded = base64_encode(random_bytes((int) ceil($payloadBytes * 3 / 4) + 3));

    return substr($encoded, 0, $payloadBytes);
}
