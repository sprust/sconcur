<?php

declare(strict_types=1);

namespace SConcur\Transport;

readonly class SerializableObject
{
    public function __construct(private mixed $value)
    {
    }

    public function serialize(): string
    {
        return MessagePackTransport::pack($this->value);
    }
}
