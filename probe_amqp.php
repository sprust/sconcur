<?php
declare(strict_types=1);

use SConcur\Features\Amqp\AMQPChannel;
use SConcur\Features\Amqp\AMQPDecimal;
use SConcur\Features\Amqp\AMQPExchange;
use SConcur\Features\Amqp\AMQPQueue;
use SConcur\Features\Amqp\AMQPTimestamp;
use SConcur\Tests\Impl\TestAmqpResolver;
use SConcur\Tests\Impl\TestApplication;
use const SConcur\Features\Amqp\AMQP_EX_TYPE_DIRECT;
use const SConcur\Features\Amqp\AMQP_NOPARAM;

error_reporting(E_ALL);
require_once __DIR__ . '/vendor/autoload.php';
TestApplication::init();

function out(string $s): void { echo $s, "\n"; }
function show(mixed $v): string {
    if ($v instanceof AMQPDecimal) return 'AMQPDecimal(e=' . $v->getExponent() . ',s=' . $v->getSignificand() . ')';
    if ($v instanceof AMQPTimestamp) return 'AMQPTimestamp(' . $v->getTimestamp() . ')';
    if (is_array($v)) { $p = []; foreach ($v as $k => $x) { $p[] = var_export($k, true) . '=>' . show($x); } return '[' . implode(', ', $p) . ']'; }
    return var_export($v, true);
}

$connection = TestAmqpResolver::getConnection();
$channel    = new AMQPChannel($connection);
$exName = TestAmqpResolver::uniqueName('ex');
$exchange = new AMQPExchange($channel);
$exchange->setName($exName); $exchange->setType(AMQP_EX_TYPE_DIRECT); $exchange->setFlags(AMQP_NOPARAM);
$exchange->declareExchange();
$qName = TestAmqpResolver::uniqueName('q');
$queue = new AMQPQueue($channel);
$queue->setName($qName); $queue->setFlags(AMQP_NOPARAM); $queue->declareQueue();
$queue->bind($exName, 'rk');

// PROBE A: normal field-table round trip
$exchange->publish('b', 'rk', null, ['headers' => [
    'dec'    => new AMQPDecimal(exponent: 2, significand: 314),
    'ts'     => new AMQPTimestamp(1700000000.0),
    'nested' => ['a' => 1, 'list' => [1, 2, 3]],
    'list'   => [10, 20],
    'neg'    => -5,
    'big'    => PHP_INT_MAX,
    'flt'    => 1.5,
    'nul'    => null,
    'bool'   => true,
    'bin'    => "\x00\x01\xff",
    'mixedkeys' => [0 => 'zero', 'k' => 'v'],
]]);
usleep(300000);
$e = $queue->get();
out('PROBE A body=' . var_export($e?->getBody(), true));
out('PROBE A headers=' . show($e?->getHeaders()));
out('PROBE A contentType=' . var_export($e?->getContentType(), true) . ' dm=' . var_export($e?->getDeliveryMode(), true) . ' ts=' . var_export($e?->getTimestamp(), true) . ' tag=' . var_export($e?->getConsumerTag(), true) . ' ex=' . var_export($e?->getExchangeName(), true));
if ($e) $queue->ack($e->getDeliveryTag());

// PROBE B: consume() twice — does the first stream leak?
$exchange->publish('m1', 'rk');
$exchange->publish('m2', 'rk');
usleep(300000);
$tags = [];
$queue->consume(function ($env, $q) use (&$tags, $queue) { $tags[] = $env->getDeliveryTag(); $queue->ack($env->getDeliveryTag()); return false; }, null, null);
$firstTag = $queue->getConsumerTag();
out('PROBE B first consumer tag=' . var_export($firstTag, true));
$queue->consume(function ($env, $q) use (&$tags, $queue) { $tags[] = $env->getDeliveryTag(); $queue->ack($env->getDeliveryTag()); return false; }, null, null);
$secondTag = $queue->getConsumerTag();
out('PROBE B second consumer tag=' . var_export($secondTag, true));
out('PROBE B consumers registered on channel: ' . implode(',', array_keys($channel->getConsumers())));
out('PROBE B used channels=' . $connection->getUsedChannels());
out('PROBE B tasksCount=' . SConcur\Connection\Extension::get()->tasksCount());

// PROBE C: local guard after disconnect
$c2 = TestAmqpResolver::getConnection();
$ch2 = new AMQPChannel($c2);
$q2 = new AMQPQueue($ch2); $q2->setName($qName);
$c2->disconnect();
out('PROBE C channel isConnected=' . var_export($ch2->isConnected(), true));
try { $q2->purge(); out('PROBE C purge ok'); } catch (Throwable $t) { out('PROBE C purge threw ' . get_class($t) . ': ' . $t->getMessage()); }

echo "done\n";
