<?php

declare(strict_types=1);

namespace SConcur\Transport;

use RuntimeException;
use SConcur\Exceptions\ResponseIsNotMessagePackException;
use Throwable;

final readonly class MessagePackTransport
{
    public static function pack(mixed $payload): string
    {
        self::checkExtension();

        set_error_handler(
            static function (int $severity, string $message): never {
                throw new RuntimeException($message);
            }
        );

        try {
            $packed = msgpack_pack($payload);
        } catch (Throwable $exception) {
            throw new ResponseIsNotMessagePackException(
                message: $exception->getMessage(),
            );
        } finally {
            restore_error_handler();
        }

        return $packed;
    }

    /**
     * @return array<mixed>
     */
    public static function unpack(string $payload): array
    {
        self::checkExtension();

        set_error_handler(
            static function (int $severity, string $message): never {
                throw new RuntimeException($message);
            }
        );

        try {
            $decoded = msgpack_unpack($payload);
        } catch (Throwable $exception) {
            throw new ResponseIsNotMessagePackException(
                message: $exception->getMessage(),
            );
        } finally {
            restore_error_handler();
        }

        if (!is_array($decoded)) {
            throw new ResponseIsNotMessagePackException(
                message: 'Decoded MessagePack response is not an array.',
            );
        }

        return $decoded;
    }

    private static function checkExtension(): void
    {
        if (!function_exists('msgpack_pack') || !function_exists('msgpack_unpack')) {
            throw new RuntimeException(
                'The extension "msgpack" is not loaded.'
            );
        }
    }
}
