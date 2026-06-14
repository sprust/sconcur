<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\HttpServer;

use PHPUnit\Framework\Attributes\DataProvider;

class HttpServerMethodsTest extends BaseHttpServerTestCase
{
    /**
     * @return array<int, array{string}>
     */
    public static function methodProvider(): array
    {
        return [
            ['GET'],
            ['POST'],
            ['PUT'],
            ['PATCH'],
            ['DELETE'],
        ];
    }

    #[DataProvider('methodProvider')]
    public function testMethodIsRoutedAndEchoed(string $method): void
    {
        [$status, $body] = $this->request($method, '/method');

        self::assertSame(200, $status);
        self::assertSame($method, $body);
    }

    public function testPostBodyIsReceived(): void
    {
        [$status, $body] = $this->request('GET', '/');

        self::assertSame(200, $status);
        self::assertSame('ok', $body);
    }
}
