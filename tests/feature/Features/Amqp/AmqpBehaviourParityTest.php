<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use AMQPChannel as NativeChannel;
use AMQPConnection as NativeConnection;
use AMQPEnvelope as NativeEnvelope;
use AMQPExchange as NativeExchange;
use AMQPQueue as NativeQueue;
use SConcur\Features\Amqp\AMQPEnvelope as Envelope;
use SConcur\Features\Amqp\AMQPExchange;
use SConcur\Tests\Impl\TestAmqpResolver;
use Throwable;
// The values are identical on both sides — AmqpDriverParityTest is what proves it — so
// the calque's constants drive the native calls too, and the test needs no global names.
use const SConcur\Features\Amqp\AMQP_AUTOACK;
use const SConcur\Features\Amqp\AMQP_DURABLE;
use const SConcur\Features\Amqp\AMQP_NOPARAM;
use const SConcur\Features\Amqp\AMQP_PASSIVE;

/**
 * What the reflection parity test cannot see: the two implementations must put the same
 * bytes on the wire. A message published through ext-amqp is read back through the calque
 * and the other way round, and every property, header and routing field is compared.
 *
 * This is where a swapped argument, a misread flag or a mis-encoded field table shows up.
 */
class AmqpBehaviourParityTest extends AmqpTestCase
{
    /** The properties both implementations are asked to publish. */
    private const array PUBLISH_ATTRIBUTES = [
        'content_type'     => 'application/json',
        'content_encoding' => 'utf-8',
        'delivery_mode'    => 2,
        'priority'         => 3,
        'correlation_id'   => 'correlation-1',
        'reply_to'         => 'reply-queue',
        'expiration'       => '60000',
        'message_id'       => 'message-1',
        'timestamp'        => 1_700_000_000,
        'type'             => 'order.created',
        'app_id'           => 'parity',
        'headers'          => [
            'x-attempt' => 3,
            'x-flag'    => true,
            'x-name'    => 'parity',
            'x-ratio'   => 1.5,
        ],
    ];

    protected ?NativeConnection $nativeConnection = null;

    protected ?NativeChannel $nativeChannel = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('amqp')) {
            self::markTestSkipped('ext-amqp is not installed, there is nothing to compare against.');
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->nativeChannel = null;

            $this->nativeConnection?->disconnect();
        } catch (Throwable) {
            // The broker is gone; nothing to disconnect from.
        }

        $this->nativeConnection = null;

        parent::tearDown();
    }

    public function testTheCalqueReadsWhatTheExtensionPublished(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $queueName = (string) $queue->getName();

        $nativeExchange = new NativeExchange($this->nativeChannel());

        $nativeExchange->setName('');
        $nativeExchange->publish('{"id":1}', $queueName, AMQP_NOPARAM, self::PUBLISH_ATTRIBUTES);

        $envelope = $this->waitForMessage($queue);

        self::assertNotNull($envelope, 'the calque received nothing from the extension');

        self::assertSame($this->expectedFields($queueName), $this->fields($envelope));

        $queue->ack($envelope->getDeliveryTag());
    }

    public function testTheExtensionReadsWhatTheCalquePublished(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $queueName = (string) $queue->getName();

        $exchange = new AMQPExchange($channel);

        $exchange->setName('');
        $exchange->publish(
            message: '{"id":1}',
            routingKey: $queueName,
            headers: self::PUBLISH_ATTRIBUTES,
        );

        $envelope = $this->waitForNativeMessage($queueName);

        self::assertNotNull($envelope, 'the extension received nothing from the calque');

        self::assertSame($this->expectedFields($queueName), $this->fields($envelope));
    }

    public function testAQueueDeclaredByOneImplementationIsUsableByTheOther(): void
    {
        $channel = $this->channel();
        $queue   = $this->declareQueue(channel: $channel, flags: AMQP_DURABLE);

        $queueName = (string) $queue->getName();

        // The extension declares the same queue passively: it would fail if the calque had
        // declared it with different durability or arguments.
        $nativeQueue = new NativeQueue($this->nativeChannel());

        $nativeQueue->setName($queueName);
        $nativeQueue->setFlags(AMQP_PASSIVE);

        self::assertSame(0, $nativeQueue->declareQueue());
    }

    /**
     * The fields both sides must report, in one map so a mismatch is shown as a diff
     * rather than as the first failing assertion.
     *
     * @return array<string, mixed>
     */
    private function expectedFields(string $queueName): array
    {
        $headers = self::PUBLISH_ATTRIBUTES['headers'];

        ksort($headers);

        return [
            'body'            => '{"id":1}',
            'routingKey'      => $queueName,
            'exchange'        => '',
            'contentType'     => 'application/json',
            'contentEncoding' => 'utf-8',
            'deliveryMode'    => 2,
            'priority'        => 3,
            'correlationId'   => 'correlation-1',
            'replyTo'         => 'reply-queue',
            'expiration'      => '60000',
            'messageId'       => 'message-1',
            'timestamp'       => 1_700_000_000,
            'type'            => 'order.created',
            'appId'           => 'parity',
            'headers'         => $headers,
        ];
    }

    /**
     * The same fields read off a delivery, whichever implementation delivered it. The
     * headers are name-sorted: a field table has no order, and the two sides are free to
     * hand it over in a different one.
     *
     * @return array<string, mixed>
     */
    private function fields(Envelope|NativeEnvelope $envelope): array
    {
        $headers = $envelope->getHeaders();

        ksort($headers);

        return [
            'body'            => $envelope->getBody(),
            'routingKey'      => $envelope->getRoutingKey(),
            'exchange'        => $envelope->getExchangeName(),
            'contentType'     => $envelope->getContentType(),
            'contentEncoding' => $envelope->getContentEncoding(),
            'deliveryMode'    => $envelope->getDeliveryMode(),
            'priority'        => $envelope->getPriority(),
            'correlationId'   => $envelope->getCorrelationId(),
            'replyTo'         => $envelope->getReplyTo(),
            'expiration'      => $envelope->getExpiration(),
            'messageId'       => $envelope->getMessageId(),
            'timestamp'       => $envelope->getTimestamp(),
            'type'            => $envelope->getType(),
            'appId'           => $envelope->getAppId(),
            'headers'         => $headers,
        ];
    }

    private function nativeChannel(): NativeChannel
    {
        if ($this->nativeChannel !== null) {
            return $this->nativeChannel;
        }

        $this->nativeConnection = new NativeConnection(TestAmqpResolver::getCredentials());

        $this->nativeConnection->connect();

        return $this->nativeChannel = new NativeChannel($this->nativeConnection);
    }

    private function waitForNativeMessage(string $queueName): ?NativeEnvelope
    {
        $queue = new NativeQueue($this->nativeChannel());

        $queue->setName($queueName);
        $queue->setFlags(AMQP_DURABLE);

        $deadline = microtime(true) + 2;

        do {
            $envelope = $queue->get(flags: AMQP_AUTOACK);

            if ($envelope instanceof NativeEnvelope) {
                return $envelope;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        return null;
    }
}
