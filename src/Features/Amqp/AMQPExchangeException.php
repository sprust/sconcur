<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

/**
 * A failure of an exchange operation (declare, bind, publish, delete).
 */
class AMQPExchangeException extends AMQPException
{
}
