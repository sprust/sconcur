<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * What the top level of the config hands down to every group that does not say
 * otherwise. Kept apart from MasterConfig so a group can be read on its own — the
 * reload path re-reads groups against the defaults of the master already running.
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
