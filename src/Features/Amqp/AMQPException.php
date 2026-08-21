<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp;

use RuntimeException;

/**
 * Base of the calque's exception hierarchy, so `catch (AMQPException $exception)` from
 * ext-amqp code keeps working after the `use` lines are switched over.
 *
 * The one deliberate difference from the extension: it extends RuntimeException rather
 * than Exception, following the project rule for runtime failures. Both are caught by
 * `catch (Exception)`, so no ext-amqp catch block changes meaning.
 */
class AMQPException extends RuntimeException
{
}
