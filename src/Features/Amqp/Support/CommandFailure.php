<?php

declare(strict_types=1);

namespace SConcur\Features\Amqp\Support;

/**
 * A failed command, as the Go side described it: what the failure did to the resource, the
 * AMQP reply code the broker named (0 when it named none) and the message.
 */
readonly class CommandFailure
{
    public function __construct(
        public FailureScopeEnum $scope,
        public int $code,
        public string $message,
    ) {
    }
}
