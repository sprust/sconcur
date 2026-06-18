<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Worker;

use RuntimeException;

/**
 * The master config file (--configPath) is missing, unreadable, not valid JSON, or
 * carries an invalid value (e.g. a missing workerScript or an unknown restartPolicy).
 */
class InvalidConfigException extends RuntimeException
{
}
