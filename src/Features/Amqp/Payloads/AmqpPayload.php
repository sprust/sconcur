<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Payloads;

use SConcur\Features\Amqp\AmqpCommandEnum;
use SConcur\Features\MethodEnum;
use SConcur\Transport\PayloadInterface;

/**
 * The envelope every AMQP command travels in: the method to run (`cm`) and its parameters
 * (`p`).
 *
 * One class for all of them, unlike the pair-per-command the other multi-command features
 * use: AMQP has two dozen methods whose parameters are flat maps of short keys with no
 * logic of their own, so the callers write the keys where the values are. The Go struct
 * each command's `p` is decoded into is named on its AmqpCommandEnum case.
 *
 * Go: payloads.Envelope (ext/internal/features/amqp/payloads/payloads.go).
 */
readonly class AmqpPayload implements PayloadInterface
{
    /**
     * @param array<string, mixed> $data the command's parameters, by their wire keys
     */
    public function __construct(
        protected AmqpCommandEnum $command,
        protected array $data,
    ) {
    }

    public function getMethod(): MethodEnum
    {
        return MethodEnum::Amqp;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return [
            'cm' => $this->command->value,
            'p'  => $this->data,
        ];
    }
}
