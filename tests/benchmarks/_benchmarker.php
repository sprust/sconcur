<?php

use SConcur\Entities\Context;
use SConcur\SConcur;
use SConcur\Tests\Impl\TestContainer;

ini_set('memory_limit', '1024M');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_benchmarker.php';

TestContainer::resolve();

readonly class Benchmarker
{
    public function __construct(
        private string $name,
        private int $total,
        private int $timeout,
        private int $limitCount,
    ) {
    }

    public function run(
        ?Closure $nativeCallback = null,
        ?Closure $syncCallback = null,
        ?Closure $asyncCallback = null
    ): void {
        echo "\nBenchmarking $this->name...\n";
        echo "Total call:\t$this->total\n";
        echo "Timeout:\t$this->timeout\n";
        echo "Limit:\t$this->limitCount\n";
        echo "\n";

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

        echo "\n\n---- Native call ----\n";

        $nativeTotalTime = '-';
        $nativeMemPeak   = '-';

        if (count($nativeCallbacks) > 0) {
            memory_reset_peak_usage();

            $start = microtime(true);

            $keys = array_keys($nativeCallbacks);

            foreach ($keys as $key) {
                $callback = $nativeCallbacks[$key];

                unset($nativeCallbacks[$key]);

                $callback();

                $key = "$this->name: $key";

                echo "success: $key\n";
            }

            $nativeTotalTime = microtime(true) - $start;
            $nativeMemPeak   = round(memory_get_peak_usage(true) / 1024 / 1024, 4);
        }

        echo "\n\n---- Sync call ----\n";

        $syncTotalTime = '-';
        $syncMemPeak   = '-';

        if (count($syncCallbacks) > 0) {
            memory_reset_peak_usage();

            $start = microtime(true);

            $context = new Context(
                timeoutSeconds: $this->timeout
            );

            $keys = array_keys($syncCallbacks);

            foreach ($keys as $key) {
                $callback = $syncCallbacks[$key];

                unset($syncCallbacks[$key]);

                $callback($context);

                $key = "$this->name: $key";

                echo "success: $key\n";
            }

            $syncTotalTime = microtime(true) - $start;
            $syncMemPeak   = round(memory_get_peak_usage(true) / 1024 / 1024, 4);
        }

        echo "\n\n---- Async call ----\n";

        $asyncTotalTime = '-';
        $asyncMemPeak   = '-';

        if (count($asyncCallbacks) > 0) {
            memory_reset_peak_usage();

            $start = microtime(true);

            $generator = SConcur::run(
                callbacks: $asyncCallbacks,
                timeoutSeconds: $this->timeout,
                limitCount: $this->limitCount,
            );

            foreach ($generator as $result) {
                $key = "$this->name: $result->key";

                echo "success: $key\n";
            }

            $asyncTotalTime = microtime(true) - $start;
            $asyncMemPeak   = round(memory_get_peak_usage(true) / 1024 / 1024, 4);
        }

        echo "\n\nTotal call:\t$this->total\n";
        echo "Thr limit:\t$this->limitCount\n";
        echo "Mem peak native/sync/async:\t$nativeMemPeak/$syncMemPeak/$asyncMemPeak\n";
        echo "Total time native/sync/async:\t$nativeTotalTime/$syncTotalTime/$asyncTotalTime\n";
    }
}
