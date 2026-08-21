<?php

declare(strict_types=1);

/**
 * The AMQP_* constants of the ext-amqp calque, declared in the feature namespace (the
 * global names belong to the PECL extension itself and would collide with it). Import
 * them with `use const SConcur\Features\Amqp\AMQP_DURABLE;`.
 *
 * Every value mirrors the pinned ext-amqp release (composer.json → require-dev
 * "ext-amqp"); AmqpDriverParityTest compares them against the live extension, so a
 * value that drifts fails the build instead of silently changing behaviour.
 *
 * The file is loaded through composer's autoload.files — constants have no autoloading
 * of their own.
 */

namespace SConcur\Features\Amqp;

/** Forcefully disables all other flags. */
const AMQP_NOPARAM = 0;

/** Do not send a basic.consume request during consume(); read an already open consumer. */
const AMQP_JUST_CONSUME = 1;

/** Durable exchanges and queues survive a broker restart. */
const AMQP_DURABLE = 2;

/** Do not redeclare; fail if the exchange or queue does not exist. */
const AMQP_PASSIVE = 4;

/** Queues only: only one client may consume from this queue. */
const AMQP_EXCLUSIVE = 8;

/** Delete the exchange or queue once nothing is bound to or consuming from it. */
const AMQP_AUTODELETE = 16;

/** Clients may not publish to an exchange declared with this flag. */
const AMQP_INTERNAL = 32;

/** Do not deliver back the messages this connection published. */
const AMQP_NOLOCAL = 64;

/** Mark messages acknowledged by the broker as soon as they are delivered. */
const AMQP_AUTOACK = 128;

/** Delete the queue only if it is empty. */
const AMQP_IFEMPTY = 256;

/** Delete the queue or exchange only if nothing uses it. */
const AMQP_IFUNUSED = 512;

/** A published message must be routable to a queue, otherwise it is returned. */
const AMQP_MANDATORY = 1024;

/** Deliver the published message immediately or return it (unsupported by RabbitMQ). */
const AMQP_IMMEDIATE = 2048;

/** Acknowledge every delivery up to and including the given tag. */
const AMQP_MULTIPLE = 4096;

/** Do not wait for the broker's reply to the method. */
const AMQP_NOWAIT = 8192;

/** Put the rejected message back into the queue. */
const AMQP_REQUEUE = 16384;

/** A direct exchange type. */
const AMQP_EX_TYPE_DIRECT = 'direct';

/** A fanout exchange type. */
const AMQP_EX_TYPE_FANOUT = 'fanout';

/** A topic exchange type. */
const AMQP_EX_TYPE_TOPIC = 'topic';

/** A headers exchange type. */
const AMQP_EX_TYPE_HEADERS = 'headers';

/** The errno ext-amqp reports for a socket timeout. */
const AMQP_OS_SOCKET_TIMEOUT_ERRNO = 536870923;

/** The highest channel number a connection may open. */
const PHP_AMQP_MAX_CHANNELS = 256;

/** Authenticate with a login and a password. */
const AMQP_SASL_METHOD_PLAIN = 0;

/** Authenticate with the TLS client certificate. */
const AMQP_SASL_METHOD_EXTERNAL = 1;

/** Keep the message in memory while it sits in a queue. */
const AMQP_DELIVERY_MODE_TRANSIENT = 1;

/** Write the message to disk when it is placed in a durable queue. */
const AMQP_DELIVERY_MODE_PERSISTENT = 2;

/** The ext-amqp release this calque mirrors. */
const AMQP_EXTENSION_VERSION = '2.2.0';

/** Major version of the mirrored ext-amqp release. */
const AMQP_EXTENSION_VERSION_MAJOR = 2;

/** Minor version of the mirrored ext-amqp release. */
const AMQP_EXTENSION_VERSION_MINOR = 2;

/** Patch version of the mirrored ext-amqp release. */
const AMQP_EXTENSION_VERSION_PATCH = 0;

/** Pre-release suffix of the mirrored ext-amqp release. */
const AMQP_EXTENSION_VERSION_EXTRA = '';

/** Numeric id of the mirrored ext-amqp release. */
const AMQP_EXTENSION_VERSION_ID = 20200;
