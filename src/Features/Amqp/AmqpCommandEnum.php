<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

/**
 * Sub-operations of the amqp feature, carried in the payload envelope (the `cm` field)
 * under the single MethodEnum::Amqp — mirrors WsClientCommandEnum, one case per AMQP
 * 0-9-1 method the calque exposes.
 *
 * Go: types.AmqpCommand (ext/internal/types/amqp.go).
 */
enum AmqpCommandEnum: string
{
    /** Open (or reuse from the pool) a connection to the broker. */
    case Connect = 'con';

    /** Release the connection handle. */
    case Disconnect = 'dis';

    /** Open a channel on a connection. */
    case ChannelOpen = 'cho';

    /** Close a channel. */
    case ChannelClose = 'chc';

    /** basic.qos — prefetch settings of a channel. */
    case Qos = 'qos';

    /** exchange.declare (or exchange.declare-passive). */
    case ExchangeDeclare = 'exd';

    /** exchange.delete. */
    case ExchangeDelete = 'exx';

    /** exchange.bind. */
    case ExchangeBind = 'exb';

    /** exchange.unbind. */
    case ExchangeUnbind = 'exu';

    /** queue.declare (or queue.declare-passive). */
    case QueueDeclare = 'qud';

    /** queue.delete. */
    case QueueDelete = 'qux';

    /** queue.bind. */
    case QueueBind = 'qub';

    /** queue.unbind. */
    case QueueUnbind = 'quu';

    /** queue.purge. */
    case QueuePurge = 'qup';

    /** basic.publish. */
    case Publish = 'pub';

    /** basic.get — one message or nothing, immediately. */
    case Get = 'get';

    /** basic.consume — the streaming command: every next() yields one delivery. */
    case Consume = 'csm';

    /** basic.cancel. */
    case Cancel = 'cnl';

    /** basic.ack. */
    case Ack = 'ack';

    /** basic.nack. */
    case Nack = 'nck';

    /** basic.reject. */
    case Reject = 'rej';

    /** basic.recover. */
    case Recover = 'rcv';

    /** tx.select. */
    case TransactionSelect = 'txs';

    /** tx.commit. */
    case TransactionCommit = 'txc';

    /** tx.rollback. */
    case TransactionRollback = 'txr';

    /** confirm.select — put the channel into publisher-confirm mode. */
    case ConfirmSelect = 'cfs';

    /** Wait for the outstanding publisher confirms of a channel. */
    case ConfirmWait = 'cfw';

    /** Wait for the basic.return messages of a channel. */
    case ReturnWait = 'rtw';

    /** How many channels the connection handle has open. */
    case UsedChannels = 'usc';
}
