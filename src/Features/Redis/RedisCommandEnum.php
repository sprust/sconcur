<?php

declare(strict_types=1);

namespace SConcur\Features\Redis;

/**
 * Sub-operations of the redis feature, carried in the payload envelope (the `cm` field)
 * under the single MethodEnum::Redis.
 *
 * Every case names the Rust struct its parameters are decoded into. That cross-reference
 * lives here rather than on a class per command, because Redis commands are flat lists of
 * arguments with no logic of their own (see Payloads\RedisPayload) — the same reasoning as
 * AmqpCommandEnum.
 *
 * Rust: the command values matched in ext/src/features/redis/mod.rs.
 */
enum RedisCommandEnum: string
{
    /** One command on a shared connection, or on a dedicated one when it blocks. Rust: payloads::CommandParams. */
    case Command = 'cmd';

    /** A batch of commands in one round trip, optionally inside MULTI/EXEC. Rust: payloads::PipelineParams. */
    case Pipeline = 'pip';

    /** A cursor command (SCAN, HSCAN, SSCAN, ZSCAN), streamed batch by batch. Rust: payloads::ScanParams. */
    case Scan = 'scn';

    /** A subscription: the streaming command whose every next() yields a batch of messages. Rust: payloads::SubscribeParams. */
    case Subscribe = 'sub';

    /** Adds or removes channels on a live subscription. Rust: payloads::SubscriptionUpdateParams. */
    case SubscriptionUpdate = 'sup';

    /** Closes a subscription and releases its connection. Rust: payloads::SubscriptionRefParams. */
    case SubscriptionClose = 'suc';
}
