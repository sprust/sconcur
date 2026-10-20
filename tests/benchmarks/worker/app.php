<?php

/**
 * A stand-in for an application's bootstrap: loads every class of Composer's classmap
 * (PHPUnit and its sebastian/* packages, about 1 250 classes, some 11 MiB of compiled
 * code), the way a framework loads its own while it boots. Required once: a forked
 * worker whose master loaded it already finds it loaded.
 */

declare(strict_types=1);

foreach (require dirname(__DIR__, 3) . '/vendor/composer/autoload_classmap.php' as $class => $file) {
    class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class);
}
