<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

/**
 * Sub-operations of the amqp feature, carried in the payload envelope (the `cm` field)
 * under the single MethodEnum::Amqp — one case per AMQP 0-9-1 method the feature exposes.
 *
 * Every case names the Go struct its parameters are decoded into. That cross-reference
 * lives here rather than on a class per command, because the parameters themselves are
 * written as short-key maps where they are built (see Payloads\AmqpPayload); the structs
 * are all in ext/internal/features/amqp/payloads/payloads.go.
 *
 * Go: types.AmqpCommand (ext/internal/types/amqp.go).
 */
enum AmqpCommandEnum: string
{
    /** Open (or reuse from the pool) a connection to the broker. Go: payloads.ConnectParams. */
    case Connect = 'con';

    /** Release the connection handle. Go: payloads.ConnectionParams. */
    case Disconnect = 'dis';

    /** Open a channel on a connection. Go: payloads.ChannelOpenParams. */
    case ChannelOpen = 'cho';

    /** Close a channel. Go: payloads.ChannelParams. */
    case ChannelClose = 'chc';

    /** basic.qos — prefetch settings of a channel. Go: payloads.QosParams. */
    case Qos = 'qos';

    /** exchange.declare (or exchange.declare-passive). Go: payloads.ExchangeDeclareParams. */
    case ExchangeDeclare = 'exd';

    /** exchange.delete. Go: payloads.ExchangeDeleteParams. */
    case ExchangeDelete = 'exx';

    /** exchange.bind. Go: payloads.ExchangeBindParams. */
    case ExchangeBind = 'exb';

    /** exchange.unbind. Go: payloads.ExchangeBindParams. */
    case ExchangeUnbind = 'exu';

    /** queue.declare (or queue.declare-passive). Go: payloads.QueueDeclareParams. */
    case QueueDeclare = 'qud';

    /** queue.delete. Go: payloads.QueueDeleteParams. */
    case QueueDelete = 'qux';

    /** queue.bind. Go: payloads.QueueBindParams. */
    case QueueBind = 'qub';

    /** queue.unbind. Go: payloads.QueueBindParams. */
    case QueueUnbind = 'quu';

    /** queue.purge. Go: payloads.QueuePurgeParams. */
    case QueuePurge = 'qup';

    /** basic.publish. Go: payloads.PublishParams. */
    case Publish = 'pub';

    /** basic.get — one message or nothing, immediately. Go: payloads.GetParams. */
    case Get = 'get';

    /** basic.consume — the streaming command: every next() yields one delivery. Go: payloads.ConsumeParams. */
    case Consume = 'csm';

    /** basic.cancel. Go: payloads.CancelParams. */
    case Cancel = 'cnl';

    /** basic.ack. Go: payloads.AckParams. */
    case Ack = 'ack';

    /** basic.nack. Go: payloads.NackParams. */
    case Nack = 'nck';

    /** basic.reject. Go: payloads.RejectParams. */
    case Reject = 'rej';

    /** confirm.select — put the channel into publisher-confirm mode. Go: payloads.ConfirmSelectParams. */
    case ConfirmSelect = 'cfs';

    /** Wait for the outstanding publisher confirms of a channel. Go: payloads.ChannelParams. */
    case ConfirmWait = 'cfw';

    /** How many channels the connection handle has open. Go: payloads.ConnectionParams. */
    case UsedChannels = 'usc';
}
