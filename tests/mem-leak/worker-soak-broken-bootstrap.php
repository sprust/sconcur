<?php

/**
 * The bootstrap a failing reload of the failures benchmark names: its fork server cannot
 * load, so the reload is given up and the running generation stays.
 */

declare(strict_types=1);

throw new RuntimeException('a bootstrap that does not load, on purpose');
