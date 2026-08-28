<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * What the top level of the config hands down to every group that does not say otherwise.
 *
 * A type of its own rather than the raw config array: the top level is read and checked
 * once, in MasterConfig, and a group then falls back to values that are already known
 * good — so a bad `restartPolicy` is reported where it was written instead of on whichever
 * group happened to omit its own.
 */
readonly class MasterDefaults
{
    /**
     * @param list<string>          $phpArgs
     * @param array<string, string> $env
     */
    public function __construct(
        public string $phpBinary,
        public array $phpArgs,
        public array $env,
        public RestartPolicy $restartPolicy,
        public int $shutdownTimeoutMs,
        public int $restartBackoffMs,
        public int $maxRestartBackoffMs,
    ) {
    }
}
