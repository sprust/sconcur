<?php

declare(strict_types=1);

/**
 * Readiness probe for the FPM check. Deliberately does not touch the extension:
 * it answers whether the pool is up and serving, which is a different question
 * from whether a fan-out works. A core that cannot survive the fork binds the
 * socket and answers this page perfectly well, and only hangs once a coroutine
 * is actually awaited — so probing with the real page would make the control
 * run wait out one timeout per attempt before it ever got started.
 */

header('Content-Type: text/plain');

echo 'pong', PHP_EOL;
