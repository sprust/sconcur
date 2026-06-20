<?php

declare(strict_types=1);

namespace SConcur\Exceptions\SocketServer;

use LogicException;

/**
 * A socket handler returned something other than a string, a MessageResponse or
 * null. A usage bug: the handler contract is Closure(Message): (string|MessageResponse|null).
 */
class InvalidHandlerResponseException extends LogicException
{
}
