<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * One pending reload request, as the trigger file holds it.
 *
 * The config path is what the requesting CLI was given, because the master is handed its
 * groups as objects and has no path of its own to go back to; an empty one means "roll
 * everything on the config already loaded".
 */
readonly class MasterReloadRequest
{
    /**
     * @param string $configPath the config to re-read, or an empty string for none
     * @param string $group      the single group to roll, or an empty string for all
     * @param string $signature  identifies this request, so the clear that ends it does
     *                           not delete a later one written while it was rolling
     */
    public function __construct(
        public string $configPath,
        public string $group,
        public string $signature,
    ) {
    }
}
