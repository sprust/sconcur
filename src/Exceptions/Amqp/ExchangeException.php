<?php

declare(strict_types=1);

namespace SConcur\Exceptions\Amqp;

/**
 * The broker refused an exchange method, or a publish could not be handed over.
 */
class ExchangeException extends AmqpException
{
}
