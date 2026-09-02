<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

/**
 * Sub-operations of the amqp feature, carried in the payload envelope (the `cm` field)
 * under the single MethodEnum::Amqp — one case per AMQP 0-9-1 method the feature exposes.
 *
 * Every case names the Rust struct its parameters are decoded into. That cross-reference
 * lives here rather than on a class per command, because the parameters themselves are
 * written as short-key maps where they are built (see Payloads\AmqpPayload); the structs
 * are all in ext/src/features/amqp/payloads.rs.
 *
 * Rust: the command values matched in ext/src/features/amqp/commands.rs.
 */
enum AmqpCommandEnum: string
{
    /** Open (or reuse from the pool) a connection to the broker. Rust: payloads::ConnectParams. */
    case Connect = 'con';

    /** Release the connection handle. Rust: payloads::ConnectionParams. */
    case Disconnect = 'dis';

    /** Open a channel on a connection. Rust: payloads::ChannelOpenParams. */
    case ChannelOpen = 'cho';

    /** Close a channel. Rust: payloads::ChannelParams. */
    case ChannelClose = 'chc';

    /** basic.qos — prefetch settings of a channel. Rust: payloads::QosParams. */
    case Qos = 'qos';

    /** exchange.declare (or exchange.declare-passive). Rust: payloads::ExchangeDeclareParams. */
    case ExchangeDeclare = 'exd';

    /** exchange.delete. Rust: payloads::ExchangeDeleteParams. */
    case ExchangeDelete = 'exx';

    /** exchange.bind. Rust: payloads::ExchangeBindParams. */
    case ExchangeBind = 'exb';

    /** exchange.unbind. Rust: payloads::ExchangeBindParams. */
    case ExchangeUnbind = 'exu';

    /** queue.declare (or queue.declare-passive). Rust: payloads::QueueDeclareParams. */
    case QueueDeclare = 'qud';

    /** queue.delete. Rust: payloads::QueueDeleteParams. */
    case QueueDelete = 'qux';

    /** queue.bind. Rust: payloads::QueueBindParams. */
    case QueueBind = 'qub';

    /** queue.unbind. Rust: payloads::QueueBindParams. */
    case QueueUnbind = 'quu';

    /** queue.purge. Rust: payloads::QueuePurgeParams. */
    case QueuePurge = 'qup';

    /** basic.publish. Rust: payloads::PublishParams. */
    case Publish = 'pub';

    /** basic.get — one message or nothing, immediately. Rust: payloads::GetParams. */
    case Get = 'get';

    /** basic.consume — the streaming command: every next() yields one delivery. Rust: payloads::ConsumeParams. */
    case Consume = 'csm';

    /**
     * The consumers of one supervised worker, streamed under a single task: every result is
     * a delivery, from any of its queues. Rust: payloads::ConsumeServeParams.
     *
     * The self-pumping counterpart of Consume — the Go side publishes the next delivery by
     * itself, so a worker pays no next() crossing per message — and the channels behind the
     * consumers belong to the Go side, so a stop cancels them without PHP releasing
     * anything. Driven by Scheduler::serve(), like the three servers.
     */
    case ConsumeServe = 'csv';

    /** basic.cancel. Rust: payloads::CancelParams. */
    case Cancel = 'cnl';

    /** basic.ack. Rust: payloads::AckParams. */
    case Ack = 'ack';

    /** basic.nack. Rust: payloads::NackParams. */
    case Nack = 'nck';

    /** basic.reject. Rust: payloads::RejectParams. */
    case Reject = 'rej';

    /** confirm.select — put the channel into publisher-confirm mode. Rust: payloads::ConfirmSelectParams. */
    case ConfirmSelect = 'cfs';

    /** Wait for the outstanding publisher confirms of a channel. Rust: payloads::ChannelParams. */
    case ConfirmWait = 'cfw';

    /** How many channels the connection handle has open. Rust: payloads::ConnectionParams. */
    case UsedChannels = 'usc';
}
