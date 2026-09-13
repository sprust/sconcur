<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Redis;

use SConcur\Exceptions\Redis\InvalidRedisArgumentException;
use SConcur\Exceptions\Redis\RedisConnectionException;
use SConcur\Exceptions\Redis\InvalidRedisDsnException;
use SConcur\Exceptions\Redis\RedisCommandException;
use SConcur\Exceptions\Redis\UnsupportedRedisCommandException;
use SConcur\Features\Redis\Connection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestRedisResolver;
use SConcur\WaitGroup;

/**
 * The command path: the raw command(), the typed facade over it, the reply types and the
 * failures each has.
 */
class RedisCommandTest extends BaseTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        TestRedisResolver::flush();

        $this->connection = TestRedisResolver::getConnection();
    }

    public function testRawCommandRoundTrip(): void
    {
        self::assertSame('OK', $this->connection->command('SET', ['k', 'v']));
        self::assertSame('v', $this->connection->command('GET', ['k']));
        self::assertSame(1, $this->connection->command('DEL', ['k']));
        self::assertNull($this->connection->command('GET', ['k']));
    }

    public function testCommandNameIsCaseInsensitive(): void
    {
        $this->connection->command('set', ['k', 'v']);

        self::assertSame('v', $this->connection->command('get', ['k']));
    }

    public function testValuesAreBinarySafe(): void
    {
        $value = "\x00\xff\x01binary\nvalue\x00";

        $this->connection->set('binary', $value);

        self::assertSame($value, $this->connection->get('binary'));
    }

    public function testIntegerAndFloatArgumentsAreConverted(): void
    {
        $this->connection->set('counter', '0');

        self::assertSame(5, $this->connection->incrBy('counter', 5));
        self::assertSame(2.5, $this->connection->incrByFloat('float', 2.5));
    }

    public function testAFloatArgumentKeepsItsPrecision(): void
    {
        $score = 1 / 3;

        $this->connection->zAdd('scores', ['member' => $score]);

        self::assertSame($score, $this->connection->zScore('scores', 'member'));
    }

    public function testABooleanArgumentIsRefused(): void
    {
        $this->expectException(InvalidRedisArgumentException::class);

        $this->connection->command('SET', ['k', true]);
    }

    public function testANullArgumentIsRefused(): void
    {
        $this->expectException(InvalidRedisArgumentException::class);

        $this->connection->command('SET', ['k', null]);
    }

    public function testAWrongTypeFailureCarriesItsCode(): void
    {
        $this->connection->set('string', 'value');

        try {
            $this->connection->command('LPUSH', ['string', 'x']);

            self::fail('LPUSH against a string should fail');
        } catch (RedisCommandException $exception) {
            self::assertSame('WRONGTYPE', $exception->errorCode);
        }
    }

    public function testAConnectionStateCommandIsRefusedWithItsReplacement(): void
    {
        try {
            $this->connection->command('MULTI');

            self::fail('MULTI should be refused');
        } catch (UnsupportedRedisCommandException $exception) {
            self::assertStringContainsString('transaction()', $exception->getMessage());
        }
    }

    public function testSubscribeIsRefusedOnTheSharedConnection(): void
    {
        $this->expectException(UnsupportedRedisCommandException::class);

        $this->connection->command('SUBSCRIBE', ['channel']);
    }

    public function testAnUnknownDsnParameterIsRefused(): void
    {
        $connection = new Connection(dsn: TestRedisResolver::getDsn() . '?pool_size=4');

        $this->expectException(InvalidRedisDsnException::class);

        $connection->ping();
    }

    public function testAnUnsupportedDsnSchemeIsRefused(): void
    {
        $connection = new Connection(dsn: 'http://127.0.0.1:6379');

        $this->expectException(InvalidRedisDsnException::class);

        $connection->ping();
    }

    public function testClientReplyIsRefusedWhileTheRestOfClientIsNot(): void
    {
        // CLIENT REPLY OFF|SKIP leaves the server owing no answer, which shifts the
        // multiplexer's queue by one for the life of the connection. Nothing errors
        // and nothing reconnects, so every later command on that pooled connection
        // reads the previous one's reply. Refusing it is the only defence.
        try {
            $this->connection->command('CLIENT', ['REPLY', 'SKIP']);

            self::fail('CLIENT REPLY should be refused');
        } catch (UnsupportedRedisCommandException $exception) {
            self::assertStringContainsString('CLIENT REPLY', $exception->getMessage());
        }

        // The connection is still usable, which is the point of refusing rather
        // than sending.
        self::assertTrue($this->connection->ping());

        // The read-only half of CLIENT is untouched — the soak test uses it.
        self::assertIsString($this->connection->command('CLIENT', ['LIST']));
    }

    public function testQuitIsRefused(): void
    {
        $this->expectException(UnsupportedRedisCommandException::class);

        // It would close a socket every other coroutine is using.
        $this->connection->command('QUIT');
    }

    public function testResp3IsRefused(): void
    {
        $connection = new Connection(dsn: TestRedisResolver::getDsn() . '?protocol=3');

        $this->expectExceptionMessageMatches('/RESP3/');

        $connection->ping();
    }

    public function testAWrongPasswordIsAConnectionFailure(): void
    {
        $host = $_ENV['REDIS_HOST'];
        $port = $_ENV['REDIS_PORT'];

        $connection = new Connection(dsn: "redis://:wrong-password@$host:$port/9", timeoutMs: 3000);

        $this->expectException(RedisConnectionException::class);

        $connection->ping();
    }

    public function testAServerErrorCannotChooseItsException(): void
    {
        // The failure kind is written by the core, not read out of the message —
        // and half of that message belongs to the server. A script answering with
        // text that looks like a connection failure, a dsn failure or a refusal is
        // still a command failure, or an application's catch blocks could be
        // steered by whatever is stored in Redis.
        $texts = [
            'connect: not really',
            'IOERR not really',
            'TIMEOUT not really',
            'unsupported dsn scheme redis://nope',
            'SUBSCRIBE would change the state of a shared connection: nope',
            'closed by task stop',
        ];

        foreach ($texts as $text) {
            try {
                $this->connection->eval("return redis.error_reply('" . $text . "')");

                self::fail("An error reply should throw: $text");
            } catch (RedisCommandException $exception) {
                self::assertStringContainsString($text, $exception->getMessage());
            }
        }
    }

    public function testAWholeFloatIsNotSentInScientificNotation(): void
    {
        // The shortest form %G finds for 60.0 is 6E+1, and that is not an integer to
        // Redis: EXPIRE refuses it outright, and SET would store those four bytes
        // where 60 was meant. Whole floats are ordinary arguments — a ttl computed
        // from a division arrives here as one.
        $this->connection->set('ttl-key', 'value');

        self::assertSame(1, $this->connection->command('EXPIRE', ['ttl-key', 60.0]));
        self::assertSame(60, $this->connection->ttl('ttl-key'));

        $this->connection->mSet(['whole' => 100.0]);

        self::assertSame('100', $this->connection->get('whole'));
    }

    public function testAFloatNoFixedFormCanCarryKeepsItsValue(): void
    {
        // The other half of the same rule: a magnitude no fixed form reaches still
        // goes out in exponent form, because a score is the only argument it can be
        // and Redis reads a score that way.
        $score = 1.0E-7;

        $this->connection->zAdd('tiny', ['member' => $score]);

        self::assertSame($score, $this->connection->zScore('tiny', 'member'));
    }

    public function testKeysThatAreNotAListAreLinedUpWithTheReply(): void
    {
        $this->connection->mSet([
            'a' => 'va',
            'b' => 'vb',
        ]);

        // array_filter keeps the positions it found, so these keys are [0 => 'a', 2 => 'b']
        // while the reply is dense. Lining the two up by the array's own keys reports a
        // key the server answered for as missing, and the caller writes a default over it.
        $keys = array_filter(['a', '', 'b'], static fn(string $key): bool => $key !== '');

        self::assertSame(
            [
                'a' => 'va',
                'b' => 'vb',
            ],
            $this->connection->mGet($keys),
        );
    }

    public function testSetRefusesNxAndXxTogether(): void
    {
        // SET k v NX XX is a syntax error at the server, and the same method already
        // guards the ttlSeconds/ttlMs pair rather than letting it be sent.
        $this->expectException(InvalidRedisArgumentException::class);

        $this->connection->set('k', 'v', ifNotExists: true, ifExists: true);
    }

    public function testAnIntegerLikeFieldComesBackAsAnIntegerKey(): void
    {
        // PHP casts canonical integer strings to int keys on assignment, so the folded
        // map cannot promise a string key. It says so instead of pretending otherwise.
        $this->connection->hSet(
            key: 'hash',
            fields: [
                '1'   => 'one',
                'two' => '2',
            ],
        );

        $map = $this->connection->hGetAll('hash');

        self::assertArrayHasKey(1, $map);
        self::assertArrayHasKey('two', $map);
        self::assertSame('one', $map[1]);
        self::assertSame('2', $map['two']);
    }

    public function testCommandsRunConcurrentlyInAWaitGroup(): void
    {
        $waitGroup = WaitGroup::create();

        for ($index = 0; $index < 20; ++$index) {
            $waitGroup->add(
                callback: function () use ($index): int {
                    $this->connection->set("key:$index", (string) $index);

                    return (int) $this->connection->get("key:$index");
                },
            );
        }

        $sum = 0;

        foreach ($waitGroup->iterate() as $value) {
            $sum += $value;
        }

        self::assertSame(190, $sum);
        self::assertSame(20, $this->connection->dbSize());
    }

    public function testTheServerIsNotAskedForOneConnectionPerCoroutine(): void
    {
        // The feature's headline claim: the commands of many coroutines share a
        // small pool of sockets rather than opening one each.
        $before = TestRedisResolver::countServerConnections();

        $waitGroup = WaitGroup::create();

        for ($index = 0; $index < 100; ++$index) {
            $waitGroup->add(
                callback: fn(): bool => $this->connection->set("shared:$index", '1'),
            );
        }

        $waitGroup->waitAll();

        $after = TestRedisResolver::countServerConnections();

        self::assertLessThanOrEqual(
            $before + 8,
            $after,
            "100 concurrent commands went from $before to $after server connections",
        );
    }
}
