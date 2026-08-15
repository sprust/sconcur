<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Connection;

use SConcur\Tests\Feature\BaseTestCase;

/**
 * Payloads carry PHP objects inside MessagePack (the BSON value objects of the
 * MongoDB feature), and ext-msgpack only reads and writes those with
 * msgpack.php_only enabled. Extension init forces the setting, because with it
 * off nothing fails loudly — documents just quietly lose their types.
 *
 * The forcing happens once, when the singleton is built, so it can only be
 * observed in a process that starts with the setting off.
 */
class MsgpackObjectSupportTest extends BaseTestCase
{
    public function testObjectSupportIsOnAfterInit(): void
    {
        self::assertSame('1', ini_get('msgpack.php_only'));
    }

    public function testInitTurnsTheSettingOnWhenItStartsOff(): void
    {
        $output = self::runChild(<<<'PHP'
            require $argv[1] . '/vendor/autoload.php';

            echo 'BEFORE:', ini_get('msgpack.php_only'), "\n";

            SConcur\Connection\Extension::get();

            echo 'AFTER:', ini_get('msgpack.php_only'), "\n";

            $objectId = new SConcur\Bson\ObjectId('6919e3d1a3673d3f4d9137a3');
            $restored = msgpack_unpack(msgpack_pack($objectId));

            echo 'RESTORED:', get_debug_type($restored), "\n";
            PHP);

        self::assertStringContainsString('BEFORE:0', $output);
        self::assertStringContainsString('AFTER:1', $output);
        self::assertStringContainsString('RESTORED:SConcur\Bson\ObjectId', $output);
    }

    /** Runs the snippet in a fresh process started with msgpack.php_only off. */
    private static function runChild(string $code): string
    {
        $projectRoot = dirname(__DIR__, 3);

        $command = sprintf(
            'timeout 15 %s -d extension=%s -d msgpack.php_only=0 -r %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($projectRoot . '/ext/build/sconcur.so'),
            escapeshellarg($code),
            escapeshellarg($projectRoot),
        );

        return (string) shell_exec($command);
    }
}
