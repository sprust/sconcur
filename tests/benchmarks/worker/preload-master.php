<?php

/**
 * The master of the memory benchmark's preload arm: loads the stand-in application, then
 * starts the master, so the workers it forks begin with the application loaded.
 */

declare(strict_types=1);

use SConcur\Worker\MasterCli;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require __DIR__ . '/app.php';

exit(new MasterCli()->run($argv));
