<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Amqp;

use PHPUnit\Framework\Attributes\DataProvider;
use SConcur\Exceptions\Amqp\ConnectionException;
use SConcur\Features\Amqp\ConnectionOptions;
use SConcur\Features\Amqp\SaslMethodEnum;
use SConcur\Features\Amqp\TlsOptions;
use SConcur\Tests\Feature\BaseTestCase;

/**
 * The options a connection is built from: the AMQP URI they can be read out of, and the
 * ranges the protocol puts on them. Nothing here talks to a broker.
 */
class AmqpConnectionOptionsTest extends BaseTestCase
{
    public function testTheDefaultsAreTheOnesEveryAmqpClientUses(): void
    {
        $options = new ConnectionOptions();

        self::assertSame('localhost', $options->host);
        self::assertSame(5672, $options->port);
        self::assertSame('guest', $options->login);
        self::assertSame('guest', $options->password);
        self::assertSame('/', $options->vhost);
        self::assertNull($options->tls);
        self::assertSame(SaslMethodEnum::Plain, $options->saslMethod);
    }

    public function testAUriIsReadIntoTheOptions(): void
    {
        $options = ConnectionOptions::fromDsn('amqp://sc_user:sc_pass@broker:5673/app');

        self::assertSame('broker', $options->host);
        self::assertSame(5673, $options->port);
        self::assertSame('sc_user', $options->login);
        self::assertSame('sc_pass', $options->password);
        self::assertSame('app', $options->vhost);
        self::assertNull($options->tls);
    }

    /**
     * The vhost is the URI's path, and its encoding follows the specification rather than
     * intuition: no path at all is the default `/`, a bare `/` is the empty vhost — a legal
     * one and not the same thing — and a vhost containing a slash is written `%2f`.
     */
    #[DataProvider('vhostProvider')]
    public function testTheVhostFollowsTheUriSpecification(string $dsn, string $expected): void
    {
        self::assertSame($expected, ConnectionOptions::fromDsn($dsn)->vhost);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function vhostProvider(): array
    {
        return [
            'no path at all'    => ['amqp://broker', '/'],
            'a bare slash'      => ['amqp://broker/', ''],
            'a named vhost'     => ['amqp://broker/app', 'app'],
            'an encoded slash'  => ['amqp://broker/%2f', '/'],
            'a nested vhost'    => ['amqp://broker/team%2Fapp', 'team/app'],
        ];
    }

    public function testAnAmqpsUriTurnsOnTlsAndTheSecurePort(): void
    {
        $options = ConnectionOptions::fromDsn('amqps://broker/');

        self::assertSame(5671, $options->port);
        self::assertNotNull($options->tls);
        self::assertTrue($options->tls->verify);
    }

    public function testTheQueryParametersAreTheOnesTheBrokerDocuments(): void
    {
        $options = ConnectionOptions::fromDsn(
            'amqps://broker/app?heartbeat=30&connection_timeout=2500&channel_max=64'
                . '&frame_max=65536&cacertfile=/certs/ca.pem&certfile=/certs/client.pem'
                . '&keyfile=/certs/client.key&auth_mechanism=external&connection_name=api',
        );

        self::assertSame(30, $options->heartbeat);
        self::assertSame(2.5, $options->connectTimeout);
        self::assertSame(64, $options->channelMax);
        self::assertSame(65536, $options->frameMax);
        self::assertSame('api', $options->connectionName);
        self::assertSame(SaslMethodEnum::External, $options->saslMethod);

        self::assertNotNull($options->tls);
        self::assertSame('/certs/ca.pem', $options->tls->caCert);
        self::assertSame('/certs/client.pem', $options->tls->cert);
        self::assertSame('/certs/client.key', $options->tls->key);
    }

    public function testVerificationCanBeTurnedOffTheWayTheBrokerSpellsIt(): void
    {
        self::assertFalse(ConnectionOptions::fromDsn('amqps://broker/?verify=verify_none')->tls?->verify);
        self::assertFalse(ConnectionOptions::fromDsn('amqps://broker/?verify=0')->tls?->verify);
        self::assertTrue(ConnectionOptions::fromDsn('amqps://broker/?verify=verify_peer')->tls?->verify);
    }

    public function testAUriWithAnUnknownSchemeIsRefused(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage("Unknown AMQP URI scheme 'http'");

        ConnectionOptions::fromDsn('http://broker/');
    }

    public function testAUriThatCannotBeReadIsRefused(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Could not parse the AMQP URI');

        ConnectionOptions::fromDsn('not a uri at all');
    }

    public function testAPortOutsideTheRangeIsRefused(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage("Parameter 'port' must be between 1 and 65535.");

        new ConnectionOptions(port: 70_000);
    }

    public function testANegativeTimeoutIsRefused(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage("Parameter 'rpcTimeout' must not be negative.");

        new ConnectionOptions(rpcTimeout: -1.0);
    }

    public function testAChannelLimitPastWhatTheProtocolAllowsIsRefused(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage("Parameter 'channelMax' must be between 1 and 256.");

        new ConnectionOptions(channelMax: ConnectionOptions::MAX_CHANNELS + 1);
    }

    /**
     * SASL EXTERNAL takes the user's identity from the client certificate, so asking for it
     * without one would connect as nobody — a misconfiguration worth catching where it is
     * written rather than at the handshake.
     */
    public function testSaslExternalWithoutAClientCertificateIsRefused(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('SASL EXTERNAL authenticates with a client certificate');

        new ConnectionOptions(
            saslMethod: SaslMethodEnum::External,
            tls: new TlsOptions(caCert: '/certs/ca.pem'),
        );
    }

    public function testSaslExternalWithACertificateIsAccepted(): void
    {
        $options = new ConnectionOptions(
            saslMethod: SaslMethodEnum::External,
            tls: new TlsOptions(cert: '/certs/client.pem', key: '/certs/client.key'),
        );

        self::assertSame(SaslMethodEnum::External, $options->saslMethod);
    }
}
