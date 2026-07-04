<?php

declare(strict_types=1);

/**
 * Honest DB lifecycle benchmark.
 *
 * For each DB feature (MongoDB — representative ops only, MySQL, PostgreSQL) and each
 * mode it works on a freshly created, uniquely named table/collection pre-seeded with
 * N rows via the NATIVE driver (unmeasured), then measures:
 *
 *     create -> seed N (native) -> read N (by id) -> delete N (by id) -> drop
 *
 * Read and delete are measured in three modes: native (raw driver / PDO), sync
 * (SConcur outside a WaitGroup) and async (SConcur fanned out in a WaitGroup). No
 * artificial latency — plain in-memory loopback (DB data lives in tmpfs).
 *
 * Honesty by construction:
 *   - Every mode runs on its OWN uniquely named table/collection, so modes and
 *     previous/interrupted runs never contaminate each other.
 *   - The dataset is identical for every mode: N rows seeded through the native
 *     driver before timing, ids 1..N explicit (no AUTO_INCREMENT/SERIAL, explicit
 *     Mongo _id), so read/delete by id are deterministic and symmetric across DBs.
 *   - A short unmeasured warm-up (reads over the seeded table) primes the connection
 *     pool and the fiber machinery before timing.
 *   - Peak RSS is reset per measured phase; the median over `runs` is reported.
 *   - Every created table/collection is dropped in a finally block.
 *
 * Usage: php db-lifecycle.php [N=10000] [runs=3] [pool=100]
 * Make:  make bench-db-lifecycle c=10000 runs=3 pool=100
 */

use SConcur\Features\Mongodb\Connection\Collection as SconcurCollection;
use SConcur\Features\Sql\Connection as SqlConnection;
use SConcur\Tests\Impl\TestApplication;
use SConcur\Tests\Impl\TestMongodbResolver;
use SConcur\Tests\Impl\TestMysqlResolver;
use SConcur\Tests\Impl\TestPgsqlResolver;
use SConcur\WaitGroup;

error_reporting(E_ALL);
ini_set('memory_limit', '1024M');

require_once __DIR__ . '/../../vendor/autoload.php';

TestApplication::init();

$count = (int) ($_SERVER['argv'][1] ?? 10000);
$runs  = (int) ($_SERVER['argv'][2] ?? 3);
$pool  = (int) ($_SERVER['argv'][3] ?? 100);

$modes  = ['native', 'sync', 'async'];
$phases = ['read', 'delete'];

/**
 * Runs `count` operations one after another (native and sync paths).
 */
function runSerial(int $count, Closure $operation): void
{
    for ($index = 1; $index <= $count; $index++) {
        $operation($index);
    }
}

/**
 * Fans `count` operations out across coroutines and drains the results (async path).
 * Actual concurrency is capped by WaitGroup's maxConcurrency and the Go-side pool.
 */
function runFanout(int $count, Closure $operation): void
{
    $waitGroup = WaitGroup::create();

    for ($index = 1; $index <= $count; $index++) {
        $waitGroup->add(
            callback: static fn() => $operation($index),
        );
    }

    foreach ($waitGroup->waitResults() as $ignored) {
        // drain the generator so every coroutine finishes
    }
}

function runPhase(int $count, Closure $operation, bool $fanout): void
{
    if ($fanout) {
        runFanout(count: $count, operation: $operation);

        return;
    }

    runSerial(count: $count, operation: $operation);
}

/**
 * Times one phase and captures its peak RSS.
 *
 * @return array{ms: float, mb: float}
 */
function measurePhase(int $count, Closure $operation, bool $fanout): array
{
    memory_reset_peak_usage();

    $start = microtime(true);

    runPhase(count: $count, operation: $operation, fanout: $fanout);

    return [
        'ms' => (microtime(true) - $start) * 1000,
        'mb' => memory_get_peak_usage(true) / 1024 / 1024,
    ];
}

/**
 * Runs the measured phases (read then delete) for a single mode over the already
 * seeded table and returns the per-phase measurements.
 *
 * @param array{read: Closure, delete: Closure} $operations
 *
 * @return array<string, array{ms: float, mb: float}>
 */
function runLifecycle(int $count, array $operations, bool $fanout): array
{
    // Unmeasured warm-up: a few reads over the seeded table prime the pool and fibers.
    runPhase(count: min(10, $count), operation: $operations['read'], fanout: $fanout);

    return [
        'read'   => measurePhase(count: $count, operation: $operations['read'], fanout: $fanout),
        'delete' => measurePhase(count: $count, operation: $operations['delete'], fanout: $fanout),
    ];
}

function median(array $values): float
{
    sort($values);

    $itemsCount = count($values);
    $middle     = intdiv($itemsCount, 2);

    if ($itemsCount % 2 === 1) {
        return $values[$middle];
    }

    return ($values[$middle - 1] + $values[$middle]) / 2;
}

// --- Native seeding (unmeasured, identical dataset for every mode) ------------

/**
 * Bulk-inserts ids 1..count into a SQL table via native PDO (batched multi-row
 * INSERT in one transaction). Works for both MySQL and PostgreSQL — PDO maps the
 * `?` placeholders to each driver's dialect.
 */
function seedSqlTableNative(PDO $pdo, string $table, int $count): void
{
    $chunkSize = 500;

    $pdo->beginTransaction();

    for ($start = 1; $start <= $count; $start += $chunkSize) {
        $end         = min($start + $chunkSize - 1, $count);
        $rowsInChunk = $end - $start + 1;

        $rowPlaceholders = implode(', ', array_fill(0, $rowsInChunk, '(?, ?, ?)'));
        $statement       = $pdo->prepare("INSERT INTO $table (id, name, amount) VALUES $rowPlaceholders");

        $bindings = [];

        for ($id = $start; $id <= $end; $id++) {
            $bindings[] = $id;
            $bindings[] = "row-$id";
            $bindings[] = $id;
        }

        $statement->execute($bindings);
    }

    $pdo->commit();
}

/**
 * Bulk-inserts _id 1..count into a Mongo collection via the native driver (batched
 * insertMany).
 */
function seedMongoCollectionNative(string $collectionName, int $count): void
{
    $collection = TestMongodbResolver::getDriverTestCollection($collectionName);

    $chunkSize = 1000;

    for ($start = 1; $start <= $count; $start += $chunkSize) {
        $end = min($start + $chunkSize - 1, $count);

        $documents = [];

        for ($id = $start; $id <= $end; $id++) {
            $documents[] = ['_id' => $id, 'name' => "row-$id", 'amount' => $id];
        }

        $collection->insertMany($documents);
    }
}

// --- Per-feature operation factories (read + delete over the seeded table) ----

/**
 * @return array{read: Closure, delete: Closure}
 */
function sqlNativeOperations(PDO $pdo, string $table): array
{
    $read   = $pdo->prepare("SELECT id, name, amount FROM $table WHERE id = ?");
    $delete = $pdo->prepare("DELETE FROM $table WHERE id = ?");

    return [
        'read' => static function (int $index) use ($read): array {
            $read->execute([$index]);

            return $read->fetchAll(PDO::FETCH_ASSOC);
        },
        'delete' => static function (int $index) use ($delete): void {
            $delete->execute([$index]);
        },
    ];
}

/**
 * @return array{read: Closure, delete: Closure}
 */
function sqlSconcurOperations(SqlConnection $connection, string $table, string $placeholderStyle): array
{
    // MySQL (go-sql-driver) uses `?`; PostgreSQL (pgx) uses positional `$1`.
    $one = $placeholderStyle === 'pgsql' ? '$1' : '?';

    $readSql   = "SELECT id, name, amount FROM $table WHERE id = $one";
    $deleteSql = "DELETE FROM $table WHERE id = $one";

    return [
        'read' => static function (int $index) use ($connection, $readSql): array {
            return $connection->fetchAll(
                sql: $readSql,
                bindings: [$index],
            );
        },
        'delete' => static function (int $index) use ($connection, $deleteSql): void {
            $connection->exec(
                sql: $deleteSql,
                bindings: [$index],
            );
        },
    ];
}

/**
 * @return array{read: Closure, delete: Closure}
 */
function mongoNativeOperations(string $collectionName): array
{
    $collection = TestMongodbResolver::getDriverTestCollection($collectionName);

    return [
        'read' => static function (int $index) use ($collection) {
            return $collection->findOne(['_id' => $index]);
        },
        'delete' => static function (int $index) use ($collection): void {
            $collection->deleteOne(['_id' => $index]);
        },
    ];
}

/**
 * @return array{read: Closure, delete: Closure}
 */
function mongoSconcurOperations(SconcurCollection $collection): array
{
    return [
        'read' => static function (int $index) use ($collection) {
            return $collection->findOne(
                filter: ['_id' => $index],
            );
        },
        'delete' => static function (int $index) use ($collection): void {
            $collection->deleteOne(
                filter: ['_id' => $index],
            );
        },
    ];
}

// --- Feature definitions ------------------------------------------------------
// Each feature: create/drop a named table, a native seeder, and a read+delete
// operations factory per mode.

$features = [
    'mysql' => [
        'setup'    => static fn(string $table) => TestMysqlResolver::createBenchmarkTable($table),
        'teardown' => static fn(string $table) => TestMysqlResolver::dropBenchmarkTable($table),
        'seed'     => static fn(string $table, int $count) => seedSqlTableNative(TestMysqlResolver::getPdo(), $table, $count),
        'operations' => static function (string $mode, string $table) use ($pool): array {
            if ($mode === 'native') {
                return sqlNativeOperations(TestMysqlResolver::getPdo(), $table);
            }

            return sqlSconcurOperations(
                connection: TestMysqlResolver::getConnection(maxOpenConns: $pool),
                table: $table,
                placeholderStyle: 'mysql',
            );
        },
    ],
    'pgsql' => [
        'setup'    => static fn(string $table) => TestPgsqlResolver::createBenchmarkTable($table),
        'teardown' => static fn(string $table) => TestPgsqlResolver::dropBenchmarkTable($table),
        'seed'     => static fn(string $table, int $count) => seedSqlTableNative(TestPgsqlResolver::getPdo(), $table, $count),
        'operations' => static function (string $mode, string $table) use ($pool): array {
            if ($mode === 'native') {
                return sqlNativeOperations(TestPgsqlResolver::getPdo(), $table);
            }

            return sqlSconcurOperations(
                connection: TestPgsqlResolver::getConnection(maxOpenConns: $pool),
                table: $table,
                placeholderStyle: 'pgsql',
            );
        },
    ],
    'mongodb' => [
        'setup'    => static fn(string $collectionName) => null,
        'teardown' => static fn(string $collectionName) => TestMongodbResolver::getDriverTestCollection($collectionName)->drop(),
        'seed'     => static fn(string $collectionName, int $count) => seedMongoCollectionNative($collectionName, $count),
        'operations' => static function (string $mode, string $collectionName): array {
            if ($mode === 'native') {
                return mongoNativeOperations($collectionName);
            }

            return mongoSconcurOperations(
                TestMongodbResolver::getSconcurTestCollection($collectionName),
            );
        },
    ],
];

// --- Run ----------------------------------------------------------------------

echo str_repeat('=', 90) . "\n";
echo "DB lifecycle benchmark  (N=$count, runs=$runs, async pool=$pool)\n";
echo "per mode: create -> seed xN (native) -> read xN (by id) -> delete xN (by id) -> drop\n";
echo "each mode isolated on its own uniquely named table/collection; no artificial latency\n";
echo str_repeat('=', 90) . "\n";

/** @var array<string, array<string, array<string, array{ms: float[], mb: float[]}>>> $samples */
$samples = [];

foreach (array_keys($features) as $featureName) {
    foreach ($modes as $mode) {
        foreach ($phases as $phase) {
            $samples[$featureName][$mode][$phase] = ['ms' => [], 'mb' => []];
        }
    }
}

for ($run = 1; $run <= $runs; $run++) {
    echo "\n-- run $run/$runs --\n";

    foreach ($features as $featureName => $feature) {
        foreach ($modes as $mode) {
            $tableName = "blc_{$mode}_" . uniqid();

            ($feature['setup'])($tableName);

            try {
                // Seed the identical dataset via the native driver (unmeasured).
                ($feature['seed'])($tableName, $count);

                $operations = ($feature['operations'])($mode, $tableName);

                $measurements = runLifecycle(
                    count: $count,
                    operations: $operations,
                    fanout: $mode === 'async',
                );

                foreach ($phases as $phase) {
                    $samples[$featureName][$mode][$phase]['ms'][] = $measurements[$phase]['ms'];
                    $samples[$featureName][$mode][$phase]['mb'][] = $measurements[$phase]['mb'];
                }

                $readMs   = round($measurements['read']['ms']);
                $deleteMs = round($measurements['delete']['ms']);

                echo sprintf(
                    "  %-8s %-6s  read %6d ms | delete %6d ms\n",
                    $featureName,
                    $mode,
                    $readMs,
                    $deleteMs,
                );
            } finally {
                ($feature['teardown'])($tableName);
            }
        }
    }
}

// --- Summary (median over runs) ----------------------------------------------

echo "\n" . str_repeat('=', 90) . "\n";
echo "SUMMARY — median over $runs run(s), N=$count\n";
echo str_repeat('=', 90) . "\n";

foreach ($features as $featureName => $feature) {
    echo "\n== $featureName ==\n";
    echo sprintf(
        "%-8s | %12s %12s %12s | %10s %10s %10s | %8s %8s %8s\n",
        'phase',
        'native ms',
        'sync ms',
        'async ms',
        'nat us/op',
        'syn us/op',
        'asy us/op',
        'nat MB',
        'syn MB',
        'asy MB',
    );
    echo str_repeat('-', 118) . "\n";

    foreach ($phases as $phase) {
        $totalMs = [];
        $perOpUs = [];
        $peakMb  = [];

        foreach ($modes as $mode) {
            $medianMs       = median($samples[$featureName][$mode][$phase]['ms']);
            $totalMs[$mode] = $medianMs;
            $perOpUs[$mode] = $count > 0 ? ($medianMs * 1000) / $count : 0.0;
            $peakMb[$mode]  = median($samples[$featureName][$mode][$phase]['mb']);
        }

        echo sprintf(
            "%-8s | %12.1f %12.1f %12.1f | %10.1f %10.1f %10.1f | %8.1f %8.1f %8.1f\n",
            $phase,
            $totalMs['native'],
            $totalMs['sync'],
            $totalMs['async'],
            $perOpUs['native'],
            $perOpUs['sync'],
            $perOpUs['async'],
            $peakMb['native'],
            $peakMb['sync'],
            $peakMb['async'],
        );
    }
}

echo "\n";
