<?php

declare(strict_types=1);

/*
 * Runs a coroutine that dies of a fatal error and reports from a shutdown
 * function what error_get_last() says about it. The engine bails out of the
 * fiber on a fatal, and the record the shutdown functions read must survive
 * the switch out of the dying fiber — which is how Laravel, Symfony and Monolog
 * learn about a fatal at all. Started as a separate process by
 * CoroutineEngineStateTest, because a fatal ends the process it happens in.
 *
 *   php -d extension=<sconcur.so> tests/impl/EngineState/fatal-in-coroutine.php
 */

use SConcur\WaitGroup;

require __DIR__ . '/../../../vendor/autoload.php';

register_shutdown_function(static function (): void {
    $lastError = error_get_last();

    // The test asserts this line too: without the extension the engine alone
    // keeps the record, and the test would pass for the wrong reason.
    echo 'extension: ', extension_loaded('sconcur') ? 'loaded' : 'missing', PHP_EOL;
    echo 'last error: ', $lastError['message'] ?? 'none', PHP_EOL;
});

$waitGroup = WaitGroup::create();

$waitGroup->add(
    callback: static function (): void {
        // A compile error is a fatal the engine bails out of, not an exception.
        eval('final class FatalInCoroutineBase {} class FatalInCoroutineChild extends FatalInCoroutineBase {}');
    },
);

$waitGroup->waitAll();
