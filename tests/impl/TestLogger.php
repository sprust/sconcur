<?php

declare(strict_types=1);

namespace SConcur\Tests\Impl;

use DateTime;
use Psr\Log\LoggerInterface;
use Stringable;

readonly class TestLogger implements LoggerInterface
{
    private string $filePath;

    public function __construct()
    {
        $this->filePath = __DIR__ . '/../storage/logs/test.log';
    }

    public function emergency(Stringable|string $message, array $context = []): void
    {
        $this->log(__FUNCTION__, $message, $context);
    }

    public function alert(Stringable|string $message, array $context = []): void
    {
        $this->log(__FUNCTION__, $message, $context);
    }

    public function critical(Stringable|string $message, array $context = []): void
    {
        $this->log(__FUNCTION__, $message, $context);
    }

    public function error(Stringable|string $message, array $context = []): void
    {
        $this->log(__FUNCTION__, $message, $context);
    }

    public function warning(Stringable|string $message, array $context = []): void
    {
        $this->log(__FUNCTION__, $message, $context);
    }

    public function notice(Stringable|string $message, array $context = []): void
    {
        $this->log(__FUNCTION__, $message, $context);
    }

    public function info(Stringable|string $message, array $context = []): void
    {
        $this->log(__FUNCTION__, $message, $context);
    }

    public function debug(Stringable|string $message, array $context = []): void
    {
        $this->log(__FUNCTION__, $message, $context);
    }

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $text = sprintf(
            "%s: %s: %s [%s]\n",
            (new DateTime())->format('Y-m-d H:i:s.u'),
            strtoupper($level),
            $message,
            json_encode($context),
        );

        file_put_contents($this->filePath, $text, FILE_APPEND);

        //echo $text;
    }
}
