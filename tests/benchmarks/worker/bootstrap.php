<?php

/**
 * The bootstrap of the memory benchmark's bootstrap arm: the fork server of the group loads the
 * stand-in application once, so the workers it forks begin with it loaded.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once __DIR__ . '/app.php';
