<?php

/**
 * Reads what worker-soak.php printed, saved to a file, and lays it out for the questions its
 * verdict leaves open.
 *
 * Without a scenario: a line per scenario with the first read after the warm-up against the
 * last — the master's memory and descriptors, the workers' memory,
 * heap and tasks, the runtime files — then the requests the load lost per worker death
 * (or per event, where nothing dies), which is how a scenario that leaks nothing can still
 * cost requests, and how two launches of one scenario are compared.
 *
 * With a scenario ("preload crash-at-start", or "crash-at-start" for every launch): each
 * of its reads, the warm-up included, with the master, the zombies and the exits. That is
 * how a SUSPECT is told from a read that caught a worker being spawned or reaped: zombies
 * with new pids in every read are deaths not yet reaped, not a leak; the master's own
 * descriptors that swing both ways around a steady total are workers miscounted mid-crash.
 *
 *   php tests/mem-leak/worker-soak-summary.php <soak output> [scenario]
 */

declare(strict_types=1);

$path     = (string) ($_SERVER['argv'][1] ?? '');
$scenario = (string) ($_SERVER['argv'][2] ?? '');

if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "usage: worker-soak-summary.php <soak output> [scenario]\n");

    exit(1);
}

$lines = (array) file($path, FILE_IGNORE_NEW_LINES);
// The soak's first line says it; a file saved from make starts with the command make echoed.
$warmUpSeconds = preg_match('~judged after (\d+) s~', implode("\n", array_slice($lines, 0, 5)), $match) === 1 ? (int) $match[1] : 60;

/** @var array<string, list<array{seconds: int, text: string}>> $reads */
$reads = [];
/** @var array<string, string> $verdicts */
$verdicts = [];

foreach ($lines as $line) {
    $line = (string) $line;

    if (preg_match('~^(\S+ \S+)\s+t=(\d+)s\s+(.*)$~', $line, $match) === 1) {
        $reads[$match[1]][] = ['seconds' => (int) $match[2], 'text' => $match[3]];
    } elseif (preg_match('~^(\S+ \S+)\s+((?:ok|SUSPECT)\b.*)$~', $line, $match) === 1) {
        $verdicts[$match[1]] = $match[2];
    }
}

if ($scenario !== '') {
    foreach ($reads as $label => $scenarioReads) {
        if ($label !== $scenario && !str_ends_with($label, ' ' . $scenario)) {
            continue;
        }

        echo $label, PHP_EOL;

        foreach ($scenarioReads as $read) {
            printf(
                "  t=%-4d master %s | workers %s | zombies %s | exits %s\n",
                $read['seconds'],
                field('~master ([\d.]+ MiB fd \d+ \(-?\d+ own\))~', $read['text']),
                field('~(\d+) workers:~', $read['text']),
                field('~zombies ([^|]*?) \|~', $read['text']),
                field('~exits (\d+)~', $read['text']),
            );
        }
    }

    exit(0);
}

foreach ($reads as $label => $scenarioReads) {
    $afterWarmUp = array_values(array_filter(
        $scenarioReads,
        static fn(array $read): bool => $read['seconds'] >= $warmUpSeconds,
    ));

    if (count($afterWarmUp) < 2) {
        printf("%-28s running or cut short: %d reads\n", $label, count($scenarioReads));

        continue;
    }

    $first = $afterWarmUp[0]['text'];
    $last  = end($afterWarmUp)['text'];

    $span = static fn(string $pattern): string => field($pattern, $first) . '->' . field($pattern, $last);

    printf(
        "%-28s master %s MiB fd %s own fd %s | workers %s MiB heap %s tasks %s | runtime files %s | %s\n",
        $label,
        $span('~master ([\d.]+) MiB~'),
        $span('~master [\d.]+ MiB fd (\d+)~'),
        $span('~master [\d.]+ MiB fd \d+ \((-?\d+) own\)~'),
        $span('~workers: ([\d.]+) MiB~'),
        $span('~heap ([\d.]+) MiB~'),
        $span('~tasks (\d+)~'),
        $span('~runtime files (\d+)~'),
        lossOf($verdicts[$label] ?? ''),
    );
}

/**
 * The first group the pattern catches in the text, or "-".
 */
function field(string $pattern, string $text): string
{
    return preg_match($pattern, $text, $match) === 1 ? trim($match[1]) : '-';
}

/**
 * What the verdict says the load lost after the warm-up, per worker death, or per event of
 * the scenario where no worker died.
 */
function lossOf(string $verdict): string
{
    if (preg_match('~(\d+) events after the warm-up \((\d+) exits\).*/work (\d+) ok (\d+) failed~', $verdict, $match) !== 1) {
        return 'no verdict';
    }

    [, $events, $exits, $answered, $failed] = array_map('intval', $match);

    $per = $exits > 0 ? sprintf('%.2f a death', $failed / $exits) : sprintf('%.4f an event', $failed / max(1, $events));

    return sprintf('%s: %d answered, %d failed, %s', str_starts_with($verdict, 'ok') ? 'ok' : 'SUSPECT', $answered, $failed, $per);
}
