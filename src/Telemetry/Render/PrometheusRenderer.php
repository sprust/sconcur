<?php

declare(strict_types=1);

namespace SConcur\Telemetry\Render;

use SConcur\Telemetry\Dto\Aggregate;
use SConcur\Telemetry\Dto\Connections;
use SConcur\Telemetry\Dto\Requests;
use SConcur\Telemetry\Dto\WorkerEntry;

/**
 * Renders the aggregate in the Prometheus text exposition format — the default
 * representation, schema-identical to the old Go renderer
 * (ext/internal/stats/prometheus.go): pool totals (sconcur_pool_*) plus per-worker
 * series (sconcur_worker_*, labelled with pid).
 */
class PrometheusRenderer
{
    public function contentType(): string
    {
        return 'text/plain; version=0.0.4; charset=utf-8';
    }

    public function render(Aggregate $aggregate): string
    {
        $name       = $this->escapeLabel($aggregate->name);
        $poolLabels = '{name="' . $name . '"}';
        $totals     = $aggregate->totals;

        $output = '';

        $output .= $this->family('sconcur_pool_workers', 'Live workers in the pool.', 'gauge', $poolLabels, (string) $aggregate->workersTotal);
        $output .= $this->family('sconcur_pool_workers_hung', 'Workers flagged hung (alive but stale snapshot).', 'gauge', $poolLabels, (string) $aggregate->workersHung);
        $output .= $this->family('sconcur_pool_memory_rss_bytes', 'Pool resident set size (with the extension).', 'gauge', $poolLabels, (string) $totals->memory->rssBytes);
        $output .= $this->family('sconcur_pool_memory_go_runtime_bytes', 'Pool Go-runtime memory footprint.', 'gauge', $poolLabels, (string) $totals->memory->goRuntimeBytes);
        $output .= $this->family('sconcur_pool_memory_non_extension_bytes', 'Pool memory outside the extension (PHP interpreter).', 'gauge', $poolLabels, (string) $totals->memory->nonExtensionBytes);
        $output .= $this->family('sconcur_pool_cpu_percent', 'Pool CPU usage (sum of per-process percentages).', 'gauge', $poolLabels, $this->float($totals->cpuPercent));
        $output .= $this->family('sconcur_pool_goroutines', 'Pool goroutine count.', 'gauge', $poolLabels, (string) $totals->goroutines);

        if ($totals->requests !== null) {
            $requests = $totals->requests;

            $output .= $this->family('sconcur_pool_requests_completed_total', 'Requests completed across the pool.', 'counter', $poolLabels, (string) $requests->completed);
            $output .= $this->family('sconcur_pool_requests_avg_ms', 'Average request duration across the pool (weighted by completed).', 'gauge', $poolLabels, $this->float($requests->avgMs));
            $output .= $this->family('sconcur_pool_requests_in_flight', 'Requests in flight across the pool.', 'gauge', $poolLabels, (string) $requests->inFlight);
            $output .= $this->family('sconcur_pool_requests_in_flight_1to5s', 'In-flight requests aged [1s, 5s).', 'gauge', $poolLabels, (string) $requests->inFlight1to5s);
            $output .= $this->family('sconcur_pool_requests_in_flight_5to15s', 'In-flight requests aged [5s, 15s).', 'gauge', $poolLabels, (string) $requests->inFlight5to15s);
            $output .= $this->family('sconcur_pool_requests_in_flight_over15s', 'In-flight requests aged >= 15s.', 'gauge', $poolLabels, (string) $requests->inFlightOver15s);
        }

        if ($totals->connections !== null) {
            $connections = $totals->connections;

            $output .= $this->family('sconcur_pool_connections_active', 'Open connections across the pool.', 'gauge', $poolLabels, (string) $connections->active);
            $output .= $this->family('sconcur_pool_connections_accepted_total', 'Connections accepted across the pool.', 'counter', $poolLabels, (string) $connections->totalAccepted);
        }

        $output .= $this->workerMetrics($aggregate, $name);

        return $output;
    }

    protected function workerMetrics(Aggregate $aggregate, string $name): string
    {
        $output = '';

        /** @var array<int, array{0: string, 1: string, 2: callable(WorkerEntry): string}> $processMetrics */
        $processMetrics = [
            ['sconcur_worker_hung', 'Whether the worker is flagged hung (1) or not (0).', fn(WorkerEntry $worker): string => $worker->hung ? '1' : '0'],
            ['sconcur_worker_snapshot_age_ms', "Age of the worker's last snapshot, in milliseconds.", fn(WorkerEntry $worker): string => (string) $worker->snapshotAgeMs],
            ['sconcur_worker_uptime_seconds', 'Worker serve-loop uptime, in seconds.', fn(WorkerEntry $worker): string => $this->float($worker->uptimeSeconds)],
            ['sconcur_worker_cpu_percent', 'Worker CPU usage percent.', fn(WorkerEntry $worker): string => $this->float($worker->cpuPercent)],
            ['sconcur_worker_goroutines', 'Worker goroutine count.', fn(WorkerEntry $worker): string => (string) $worker->goroutines],
            ['sconcur_worker_memory_rss_bytes', 'Worker resident set size (with the extension).', fn(WorkerEntry $worker): string => (string) $worker->memory->rssBytes],
            ['sconcur_worker_memory_go_runtime_bytes', 'Worker Go-runtime memory footprint.', fn(WorkerEntry $worker): string => (string) $worker->memory->goRuntimeBytes],
            ['sconcur_worker_memory_non_extension_bytes', 'Worker memory outside the extension (PHP interpreter).', fn(WorkerEntry $worker): string => (string) $worker->memory->nonExtensionBytes],
        ];

        foreach ($processMetrics as [$metricName, $help, $value]) {
            $output .= $this->header($metricName, $help, 'gauge');

            foreach ($aggregate->workers as $worker) {
                $output .= $metricName . $this->workerLabels($name, $worker->pid) . ' ' . $value($worker) . "\n";
            }
        }

        if ($aggregate->totals->requests !== null) {
            $output .= $this->workerRequests($aggregate, $name);
        }

        if ($aggregate->totals->connections !== null) {
            $output .= $this->workerConnections($aggregate, $name);
        }

        return $output;
    }

    protected function workerRequests(Aggregate $aggregate, string $name): string
    {
        $output = '';

        /** @var array<int, array{0: string, 1: string, 2: string, 3: callable(Requests): string}> $metrics */
        $metrics = [
            ['sconcur_worker_requests_completed_total', 'Requests completed by the worker.', 'counter', fn(Requests $requests): string => (string) $requests->completed],
            ['sconcur_worker_requests_avg_ms', 'Average request duration for the worker.', 'gauge', fn(Requests $requests): string => $this->float($requests->avgMs)],
            ['sconcur_worker_requests_in_flight', 'Requests in flight on the worker.', 'gauge', fn(Requests $requests): string => (string) $requests->inFlight],
        ];

        foreach ($metrics as [$metricName, $help, $type, $value]) {
            $output .= $this->header($metricName, $help, $type);

            foreach ($aggregate->workers as $worker) {
                if ($worker->requests === null) {
                    continue;
                }

                $output .= $metricName . $this->workerLabels($name, $worker->pid) . ' ' . $value($worker->requests) . "\n";
            }
        }

        return $output;
    }

    protected function workerConnections(Aggregate $aggregate, string $name): string
    {
        $output = '';

        /** @var array<int, array{0: string, 1: string, 2: string, 3: callable(Connections): string}> $metrics */
        $metrics = [
            ['sconcur_worker_connections_active', 'Open connections on the worker.', 'gauge', fn(Connections $connections): string => (string) $connections->active],
            ['sconcur_worker_connections_accepted_total', 'Connections accepted by the worker.', 'counter', fn(Connections $connections): string => (string) $connections->totalAccepted],
        ];

        foreach ($metrics as [$metricName, $help, $type, $value]) {
            $output .= $this->header($metricName, $help, $type);

            foreach ($aggregate->workers as $worker) {
                if ($worker->connections === null) {
                    continue;
                }

                $output .= $metricName . $this->workerLabels($name, $worker->pid) . ' ' . $value($worker->connections) . "\n";
            }
        }

        return $output;
    }

    protected function family(string $name, string $help, string $type, string $labels, string $value): string
    {
        return $this->header($name, $help, $type) . $name . $labels . ' ' . $value . "\n";
    }

    protected function header(string $name, string $help, string $type): string
    {
        return '# HELP ' . $name . ' ' . $help . "\n" . '# TYPE ' . $name . ' ' . $type . "\n";
    }

    protected function workerLabels(string $name, int $pid): string
    {
        return '{name="' . $name . '",pid="' . $pid . '"}';
    }

    protected function escapeLabel(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }

    protected function float(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.4f', $value), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
