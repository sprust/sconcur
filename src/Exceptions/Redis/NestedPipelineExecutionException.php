<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Redis;

use LogicException;

/**
 * execute() was called on the pipeline a transaction() callback is filling.
 *
 * Those commands would go out on their own, outside the MULTI/EXEC the caller
 * asked for, and their replies would be dropped on the floor — the transaction
 * returns only what its own send answered. Silently doing half of what the code
 * says is worse than refusing.
 */
class NestedPipelineExecutionException extends LogicException
{
}
