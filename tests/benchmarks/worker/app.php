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

/**
 * One request's worth of the application: a value exported, two texts diffed, two values
 * compared, the way a framework's request runs code its bootstrap loaded. It is what makes
 * a worker touch the pages of compiled code and class data the fork server loaded, and so
 * what a long run measures; a handler that only answered its pid would leave them shared
 * for ever and prove nothing.
 */
function memoryBenchmarkWork(): string
{
    $value = [
        'user' => ['id' => 42, 'roles' => ['admin', 'editor'], 'meta' => new ArrayObject(['score' => 1.5])],
        'list' => range(1, 20),
    ];

    $exported = (new SebastianBergmann\Exporter\Exporter())->export($value);

    $diff = (new SebastianBergmann\Diff\Differ(new SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder()))
        ->diff("a\nb\nc\nd\n", "a\nB\nc\nd\ne\n");

    (new SebastianBergmann\Comparator\Factory())->getComparatorFor($value, $value)->assertEquals($value, $value);

    return $exported . $diff;
}
