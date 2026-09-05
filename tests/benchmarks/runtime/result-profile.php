<?php

declare(strict_types=1);

/**
 * What taking a result out of the batch costs on the PHP side, frame by frame.
 *
 * The sampling profile of the HTTP server puts `waitAnyTimeoutBatch` at 17% of
 * PHP-side CPU, and most of that is not the crossing: `parseWaitBatchResponse`
 * (11.6%) and `parseResultFrame` (7.6%) are PHP reading a binary frame the
 * extension built. It is the largest article on the hot path that has never
 * been taken apart — the push was, and it turned out to be paying for something
 * it did not need.
 *
 * Parsing is measured on a captured batch rather than inside a live wait, which
 * is a deliberate exception to the rule boundary-profile.php learned (a
 * component measured alone is measured in a state the whole never has): the
 * parse is a pure function of the bytes, its input is identical either way, and
 * measuring it live would price the wait around it instead. What the captured
 * form cannot show is cache behaviour, so the absolute numbers here are a floor
 * — the shares between stages are the point.
 *
 * Batch widths matter: the scheduler asks for up to 64 results per crossing
 * (Scheduler::WAIT_BATCH_SIZE), and a server under load gets what is ready,
 * which is usually a handful. Both ends are measured.
 *
 * Run: make bench-result
 */

use SConcur\Connection\Extension;
use SConcur\Dto\TaskResultDto;
use SConcur\Features\MethodEnum;
use SConcur\Tests\Impl\TestApplication;

error_reporting(E_ALL);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../../../vendor/autoload.php';

TestApplication::init();

const ITERATIONS = 20_000;
const WARMUP     = 2_000;
const ROUNDS     = 5;

/** Frame counts per batch: one result ready, a handful, and the scheduler's ceiling. */
const WIDTHS = [1, 8, 64];

/** A payload the size a small feature result actually carries (a MessagePack blob). */
const PAYLOAD_BYTES = 64;

const FRAME_HEADER_SIZE = 18;

/**
 * One result frame in the wire format ext/src/lib.rs writes:
 * [flags C][methodLen C][executionMs N][flowKeyLen n][taskKeyLen n][ownerFiberId J]
 * followed by the method, the flow key, the task key and the payload.
 */
function frame(string $method, string $flowKey, string $taskKey, string $payload): string
{
    return pack(
        'CCNnnJ',
        0,
        strlen($method),
        7,
        strlen($flowKey),
        strlen($taskKey),
        4242,
    ) . $method . $flowKey . $taskKey . $payload;
}

/**
 * The multiframe: [count uint16] then [frameLen uint32][frame] per result.
 */
function batch(int $frames): string
{
    $method  = MethodEnum::Sleep->value;
    $payload = str_repeat("\x91", PAYLOAD_BYTES);

    $response = pack('n', $frames);

    for ($i = 0; $i < $frames; $i++) {
        $one = frame($method, '68b5e2f1c9a04', "68b5e2f1c9a04:$i", $payload);

        $response .= pack('N', strlen($one)) . $one;
    }

    return $response;
}

/**
 * @param list<float> $values
 */
function median(array $values): float
{
    sort($values);

    $middle = intdiv(count($values), 2);

    return count($values) % 2 === 1
        ? $values[$middle]
        : ($values[$middle - 1] + $values[$middle]) / 2;
}

/**
 * @param Closure(): mixed $subject
 *
 * @return float microseconds per call
 */
function timePerCall(Closure $subject): float
{
    $started = hrtime(true);

    for ($i = 0; $i < ITERATIONS; $i++) {
        $subject();
    }

    return (hrtime(true) - $started) / 1_000 / ITERATIONS;
}

// Bound rather than reflected per call: ReflectionMethod::invoke costs about as
// much as the parse it would be measuring.
$parseBatch = Closure::bind(
    static fn (string $response, float $start): array => Extension::parseWaitBatchResponse(
        $response,
        'bench',
        $start,
    ),
    null,
    Extension::class,
);

$batches = [];

foreach (WIDTHS as $width) {
    $batches[$width] = batch($width);
}

$start = microtime(true);

/** @var array<string, list<float>> $rounds */
$rounds = [];

$stages = [];

foreach (WIDTHS as $width) {
    $stages["parse a batch of $width"] = static fn (): array => $parseBatch($batches[$width], $start);
}

// The pieces one frame is made of, priced on their own so the parse above can
// be accounted for rather than just observed.
$oneFrame = $batches[1];

$stages['unpack the frame header'] = static fn () => unpack(
    'Cflags/CmethodLen/NexecutionMs/nflowKeyLen/ntaskKeyLen/JownerFiberId',
    $oneFrame,
    6,
);

$stages['4 substr of one frame'] = static function () use ($oneFrame): string {
    $cursor = 6 + FRAME_HEADER_SIZE;

    $method = substr($oneFrame, $cursor, 3);
    $cursor += 3;
    $flowKey = substr($oneFrame, $cursor, 13);
    $cursor += 13;
    $taskKey = substr($oneFrame, $cursor, 15);
    $cursor += 15;

    return $method . $flowKey . $taskKey . substr($oneFrame, $cursor, PAYLOAD_BYTES);
};

// What the parse does today per frame: the batch loop unpacks the frame length,
// then the frame parser unpacks the header — two calls, two arrays.
$stages['2 unpacks (today)'] = static function () use ($oneFrame): array {
    $length = unpack('NframeLength', $oneFrame, 2);

    return unpack(
        'Cflags/CmethodLen/NexecutionMs/nflowKeyLen/ntaskKeyLen/JownerFiberId',
        $oneFrame,
        6,
    ) + $length;
};

// The same fields read in one call, the length included: the two are adjacent
// in the frame, so nothing but the format string has to change.
$stages['1 merged unpack'] = static fn (): array => unpack(
    'NframeLength/Cflags/CmethodLen/NexecutionMs/nflowKeyLen/ntaskKeyLen/JownerFiberId',
    $oneFrame,
    2,
);

$stages['MethodEnum::from'] = static fn (): MethodEnum => MethodEnum::from(MethodEnum::Sleep->value);

// The object each frame ends as. Priced alone because it is the one part of the
// parse that would survive moving the frame reading into the C glue.
$stages['new TaskResultDto'] = static fn (): TaskResultDto => new TaskResultDto(
    flowKey: '68b5e2f1c9a04',
    method: MethodEnum::Sleep,
    key: '68b5e2f1c9a04:1',
    isError: false,
    payload: 'x',
    hasNext: false,
    executionMs: 7,
    totalExecutionMs: 0,
    ownerFiberId: 4242,
);

$stages['microtime(true)'] = static fn (): float => microtime(true);

foreach ($stages as $subject) {
    for ($i = 0; $i < WARMUP; $i++) {
        $subject();
    }
}

for ($round = 0; $round < ROUNDS; $round++) {
    foreach ($stages as $name => $subject) {
        $rounds[$name][] = timePerCall($subject);
    }
}

printf("%d iterations per stage, %d warm-up, %d interleaved rounds (median)\n\n", ITERATIONS, WARMUP, ROUNDS);
printf("%-28s %10s %10s %10s\n", 'stage', 'us/call', 'min', 'max');
printf("%-28s %10s %10s %10s\n", str_repeat('-', 28), str_repeat('-', 10), str_repeat('-', 10), str_repeat('-', 10));

foreach ($rounds as $name => $values) {
    printf("%-28s %10.3f %10.3f %10.3f\n", $name, median($values), min($values), max($values));
}

printf("\n  per frame, from the widest batch: %.3f us\n", median($rounds['parse a batch of 64']) / 64);
