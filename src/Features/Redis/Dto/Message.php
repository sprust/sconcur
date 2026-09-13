<?php

declare(strict_types=1);

namespace SConcur\Features\Redis\Dto;

/**
 * One message delivered to a subscription.
 *
 * `pattern` is empty unless the message arrived through a pattern subscription, in which
 * case it is the pattern that matched — the channel is still the channel it was published
 * to.
 *
 * Rust: subscribe_state::encode_message (ext/src/features/redis/subscribe_state.rs).
 */
readonly class Message
{
    public function __construct(
        public string $channel,
        public string $payload,
        public string $pattern = '',
        /**
         * Whether this message arrived through a pattern subscription. Told by the
         * core rather than inferred from the pattern being empty, which would be
         * wrong for a subscription to the empty pattern.
         */
        protected bool $fromPattern = false,
    ) {
    }

    public function fromPattern(): bool
    {
        return $this->fromPattern;
    }
}
