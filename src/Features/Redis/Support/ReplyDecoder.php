<?php

declare(strict_types=1);

namespace SConcur\Features\Redis\Support;

use SConcur\Dto\TaskResultDto;
use SConcur\Features\Redis\Dto\ErrorReply;
use function msgpack_unpack;

/**
 * A reply on its way back into PHP values.
 *
 * MessagePackTransport::unpack is not used here because it insists on an array,
 * and a Redis reply is just as often a string, an integer or null.
 */
readonly class ReplyDecoder
{
    public static function decode(TaskResultDto $result): mixed
    {
        if ($result->payload === '') {
            return null;
        }

        return msgpack_unpack($result->payload);
    }

    /**
     * The answer to a pipeline: one reply per command, with a Dto\ErrorReply in
     * the place of each command the server refused.
     *
     * The failures arrive beside the replies (`e`, keyed by position) rather than
     * inside them. They used to be a marked map in the reply's own place, which
     * a stored value could imitate: a hash with a field named after the marker
     * came back to the caller as an error object.
     *
     * @return list<mixed>
     */
    public static function decodePipeline(TaskResultDto $result): array
    {
        $decoded = static::decode($result);

        if (!is_array($decoded)) {
            return [];
        }

        /** @var list<mixed> $replies */
        $replies = is_array($decoded['r'] ?? null) ? array_values($decoded['r']) : [];

        /** @var array<int|string, array<string, mixed>> $failures */
        $failures = is_array($decoded['e'] ?? null) ? $decoded['e'] : [];

        foreach ($failures as $position => $failure) {
            $index = (int) $position;

            if (!array_key_exists($index, $replies)) {
                continue;
            }

            $replies[$index] = new ErrorReply(
                code: (string) ($failure['c'] ?? 'ERR'),
                message: (string) ($failure['m'] ?? ''),
            );
        }

        return $replies;
    }

    /**
     * A batch of a stream: the elements as the server sent them.
     *
     * @return list<mixed>
     */
    public static function decodeList(TaskResultDto $result): array
    {
        $decoded = static::decode($result);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
