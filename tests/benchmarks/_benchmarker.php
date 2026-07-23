<?php

use SConcur\Tests\Impl\TestApplication;
use SConcur\WaitGroup;

error_reporting(E_ALL);
ini_set('memory_limit', '1024M');

require_once __DIR__ . '/../../vendor/autoload.php';

TestApplication::init();

readonly class Benchmarker
{
    private int $total;
    private bool $logProcess;
    private int $datasetRows;

    public function __construct(private string $name)
    {
        $this->total       = (int) ($_SERVER['argv'][1] ?? 5);
        $this->logProcess  = (bool) ((int) ($_SERVER['argv'][2] ?? 0));
        $this->datasetRows = (int) (getenv('SCONCUR_BENCH_DATASET') ?: 100000);
    }

    public function isLogProcess(): bool
    {
        return $this->logProcess;
    }

    /**
     * Size of the pre-seeded dataset (rows/documents) a DB benchmark works
     * against. Overridable via SCONCUR_BENCH_DATASET for smoke runs.
     */
    public function getDatasetRows(): int
    {
        return $this->datasetRows;
    }

    /**
     * Base id of a mode's private id range inside the seeded dataset. Every
     * callback invocation (warm-up included) gets a sequential call index, so
     * `base + callIndex + 1` yields an id no other mode touches — reads,
     * updates and deletes hit their own rows and never turn into no-ops.
     */
    public function getModeIdBase(int $modeNumber): int
    {
        return intdiv($this->datasetRows, 3) * $modeNumber;
    }

    public function run(
        ?Closure $nativeCallback = null,
        ?Closure $syncCallback = null,
        ?Closure $asyncCallback = null,
        bool $warmup = true,
    ): void {
        echo str_repeat('*', 80) . "\n";
        echo str_repeat('*', 80) . "\n";

        echo "Benchmarking $this->name...\n";
        echo "Total call:\t$this->total\n";
        echo "Dataset rows:\t$this->datasetRows\n";

        $nativeCallIndex = 0;
        $syncCallIndex   = 0;
        $asyncCallIndex  = 0;

        /** @var Closure[] $nativeCallbacks */
        $nativeCallbacks = [];

        if (!is_null($nativeCallback)) {
            for ($index = 0; $index < $this->total; $index++) {
                $nativeCallbacks["$this->name: $index"] = $nativeCallback;
            }
        }

        /** @var Closure[] $syncCallbacks */
        $syncCallbacks = [];

        if (!is_null($syncCallback)) {
            for ($index = 0; $index < $this->total; $index++) {
                $syncCallbacks["$this->name: $index"] = $syncCallback;
            }
        }

        /** @var Closure[] $asyncCallbacks */
        $asyncCallbacks = [];

        if (!is_null($asyncCallback)) {
            for ($index = 0; $index < $this->total; $index++) {
                $asyncCallbacks["$this->name: $index"] = $asyncCallback;
            }
        }

        // Warm-up (discarded): native/sync run a few sequential calls, async runs
        // one full-size fan. This is symmetric to native, which enters the
        // measurement with an established connection (and usually a prepared
        // statement) anyway; without it the async fan pays the whole backend
        // connection-pool ramp-up inside the measured phase (Go database/sql and
        // the mongo driver open connections on demand up to the fan width).
        if ($warmup) {
            $this->logProcess("\n\n---- Warm-up ----\n");

            $sequentialWarmupCount = min(20, $this->total);

            for ($index = 0; $index < $sequentialWarmupCount; $index++) {
                if (!is_null($nativeCallback)) {
                    $nativeCallback($nativeCallIndex++);
                }

                if (!is_null($syncCallback)) {
                    $syncCallback($syncCallIndex++);
                }
            }

            if (!is_null($asyncCallback)) {
                $warmupGroup = WaitGroup::create();

                for ($index = 0; $index < $this->total; $index++) {
                    $warmupCallIndex = $asyncCallIndex++;

                    $warmupGroup->add(callback: static fn() => $asyncCallback($warmupCallIndex));
                }

                $warmupGroup->waitAll();
            }
        }

        $this->logProcess("\n\n---- Native call ----\n");

        $nativeTotalTime = '-';
        $nativeMemPeak   = '-';

        if (count($nativeCallbacks) > 0) {
            memory_reset_peak_usage();

            $start = microtime(true);

            $keys = array_keys($nativeCallbacks);

            foreach ($keys as $key) {
                $callback = $nativeCallbacks[$key];

                unset($nativeCallbacks[$key]);

                $callback($nativeCallIndex++);

                $key = "$this->name: $key";

                $this->logProcess("success: $key\n");
            }

            $nativeTotalTime = microtime(true) - $start;
            $nativeMemPeak   = round(memory_get_peak_usage(true) / 1024 / 1024, 4);
        }

        $this->logProcess("\n\n---- Sync call ----\n");

        $syncTotalTime = '-';
        $syncMemPeak   = '-';

        if (count($syncCallbacks) > 0) {
            memory_reset_peak_usage();

            $start = microtime(true);

            $keys = array_keys($syncCallbacks);

            foreach ($keys as $key) {
                $callback = $syncCallbacks[$key];

                unset($syncCallbacks[$key]);

                $callback($syncCallIndex++);

                $key = "$this->name: $key";

                $this->logProcess("success: $key\n");
            }

            $syncTotalTime = microtime(true) - $start;
            $syncMemPeak   = round(memory_get_peak_usage(true) / 1024 / 1024, 4);
        }

        $this->logProcess("\n\n---- Async call ----\n");

        $asyncTotalTime = '-';
        $asyncMemPeak   = '-';

        if (count($asyncCallbacks) > 0) {
            memory_reset_peak_usage();

            $start = microtime(true);

            $waitGroup = WaitGroup::create();

            $callbackKeys = [];

            foreach ($asyncCallbacks as $key => $callback) {
                $taskCallIndex = $asyncCallIndex++;

                $taskKey = $waitGroup->add(callback: static fn() => $callback($taskCallIndex));

                $callbackKeys[$taskKey] = $key;
            }

            $generator = $waitGroup->waitResults();

            foreach ($generator as $key => $result) {
                $callbackKey = $callbackKeys[$key];

                $key = "$this->name: $callbackKey";

                $this->logProcess("success: $key\n");
            }

            $asyncTotalTime = microtime(true) - $start;
            $asyncMemPeak   = round(memory_get_peak_usage(true) / 1024 / 1024, 4);
        }

        echo "\nResult $this->name:\n";
        echo "Total call:\t$this->total\n";
        echo "Mem peak native/sync/async:\t$nativeMemPeak/$syncMemPeak/$asyncMemPeak\n";
        echo "Total time native/sync/async:\t$nativeTotalTime/$syncTotalTime/$asyncTotalTime\n";
        echo str_repeat('-', 80) . "\n";
        echo str_repeat('-', 80) . "\n";
    }

    private function logProcess(string $message): void
    {
        if (!$this->logProcess) {
            return;
        }

        echo $message;
    }
}
