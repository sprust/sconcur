<?php

declare(strict_types=1);

namespace SConcur\Features\Files\Results;

use SConcur\Dto\TaskResultDto;
use SConcur\Features\Files\Support\BatchIterator;
use SConcur\Transport\MessagePackTransport;

/**
 * A file read line by line, in batches.
 *
 * The lines are cut in the extension, which is the point: fgets() in a loop is one
 * crossing and one thread pause per line, and here a batch of them is one of each. The
 * separators are removed, `\r\n` included, and a last line without a terminator is still
 * a line.
 *
 * @extends BatchIterator<string>
 */
class LinesResult extends BatchIterator
{
    /**
     * @return list<string>
     */
    protected function decode(TaskResultDto $result): array
    {
        /** @var list<string> $lines */
        $lines = MessagePackTransport::unpack($result->payload);

        return $lines;
    }
}
