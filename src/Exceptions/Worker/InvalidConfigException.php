<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Worker;

use RuntimeException;

/**
 * A WorkerMaster or a WorkerGroupConfig was given something that cannot work: a name
 * outside [A-Za-z0-9._-], a negative count or timeout, a watchdog or ready timeout below
 * its floor, two groups with one name, a master with no group.
 */
class InvalidConfigException extends RuntimeException
{
}
