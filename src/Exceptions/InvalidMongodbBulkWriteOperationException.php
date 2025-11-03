<?php

declare(strict_types=1);

namespace SConcur\Exceptions;

use Exception;

class InvalidMongodbBulkWriteOperationException extends Exception
{
    public function __construct(public readonly string $operationType)
    {
        parent::__construct(
            message: "Invalid bulk write operation type [$operationType]",
        );
    }
}
