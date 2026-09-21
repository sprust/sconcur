<?php

declare(strict_types=1);

namespace SConcur\Features\Files\Results;

use SConcur\Dto\TaskResultDto;
use SConcur\Features\Files\Support\BatchIterator;

/**
 * A file read in raw batches: one buffer per step, so the peak memory is one buffer
 * whatever the file's size — where Files::read() holds it twice, once in the extension
 * and once here.
 *
 * The bytes arrive raw rather than wrapped in MessagePack, for the same reason read()
 * answers raw: wrapping would copy every batch once more on each side.
 *
 * @extends BatchIterator<string>
 */
class ChunksResult extends BatchIterator
{
    /**
     * @return list<string>
     */
    protected function decode(TaskResultDto $result): array
    {
        // The stream ends with an empty batch, so there is no special case in the loop:
        // every batch but the last carries data.
        return $result->payload === '' ? [] : [$result->payload];
    }
}
