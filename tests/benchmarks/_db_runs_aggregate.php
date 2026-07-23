<?php

declare(strict_types=1);

/**
 * Aggregates the per-run CSVs written by db-bench-runs.sh into the markdown
 * rows used by docs/benchmarks.md: per-mode median/min/max over the runs (ms)
 * with the async-vs-native percent per cell, plus the per-mode peak-memory
 * median. CSV line format: calls;nativeS/syncS/asyncS;memN/memS/memA
 */

$outDir = $argv[1] ?? '.bench-runs';

$csvPaths = glob("$outDir/*.csv");

if ($csvPaths === false || count($csvPaths) === 0) {
    fwrite(STDERR, "no CSVs found in $outDir\n");

    exit(1);
}

function median(array $values): float
{
    sort($values);

    $count  = count($values);
    $middle = intdiv($count, 2);

    if ($count % 2 === 1) {
        return $values[$middle];
    }

    return ($values[$middle - 1] + $values[$middle]) / 2;
}

function formatMs(float $milliseconds): string
{
    if ($milliseconds >= 100) {
        return (string) round($milliseconds);
    }

    return number_format($milliseconds, 1);
}

function formatPercent(float $nativeMs, float $asyncMs): string
{
    if ($nativeMs <= 0.0) {
        return '(0%)';
    }

    $percent = (int) round(($nativeMs - $asyncMs) / $nativeMs * 100);

    if ($percent > 0) {
        return "(+$percent% ✅)";
    }

    if ($percent < 0) {
        return '(−' . abs($percent) . '% ❌)';
    }

    return '(0%)';
}

function formatCell(float $nativeMs, float $syncMs, float $asyncMs): string
{
    return formatMs($nativeMs)
        . ' / ' . formatMs($syncMs)
        . ' / ' . formatMs($asyncMs)
        . ' ' . formatPercent($nativeMs, $asyncMs);
}

foreach ($csvPaths as $csvPath) {
    $benchName = basename($csvPath, '.csv');
    $lines     = file($csvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false || count($lines) === 0) {
        fwrite(STDERR, "$benchName: empty CSV, skipped\n");

        continue;
    }

    $calls          = 0;
    $timesMs        = ['native' => [], 'sync' => [], 'async' => []];
    $memoriesMb     = ['native' => [], 'sync' => [], 'async' => []];

    foreach ($lines as $line) {
        [$calls, $timesPart, $memoriesPart] = explode(';', $line);

        $timeValues   = explode('/', $timesPart);
        $memoryValues = explode('/', $memoriesPart);

        $timesMs['native'][] = (float) $timeValues[0] * 1000;
        $timesMs['sync'][]   = (float) $timeValues[1] * 1000;
        $timesMs['async'][]  = (float) $timeValues[2] * 1000;

        $memoriesMb['native'][] = (float) $memoryValues[0];
        $memoriesMb['sync'][]   = (float) $memoryValues[1];
        $memoriesMb['async'][]  = (float) $memoryValues[2];
    }

    $medianCell = formatCell(
        median($timesMs['native']),
        median($timesMs['sync']),
        median($timesMs['async']),
    );

    $minCell = formatCell(
        min($timesMs['native']),
        min($timesMs['sync']),
        min($timesMs['async']),
    );

    $maxCell = formatCell(
        max($timesMs['native']),
        max($timesMs['sync']),
        max($timesMs['async']),
    );

    $memoryCell = round(median($memoriesMb['native']))
        . ' / ' . round(median($memoriesMb['sync']))
        . ' / ' . round(median($memoriesMb['async']));

    echo "| $benchName | $calls | $medianCell | $minCell | $maxCell | $memoryCell |\n";
}
