<?php

declare(strict_types=1);

namespace SConcur\Telemetry\Render;

use SConcur\Telemetry\Dto\Aggregate;
use SConcur\Telemetry\Dto\WorkerEntry;

/**
 * Renders the aggregate as a compact, dependency-free admin page — a header line, a
 * totals row and a per-worker table. Ports the Go renderer
 * (ext/internal/stats/html.go). The workload columns (requests vs connections) are
 * chosen once from the pool totals so every row has the same shape; a worker missing
 * that section shows dashes. Hung workers are highlighted. All interpolated values
 * are escaped.
 */
class HtmlRenderer
{
    public function contentType(): string
    {
        return 'text/html; charset=utf-8';
    }

    /**
     * @param null|string $refreshUrl when set, the page meta-refreshes to it every
     *                                2s — the live view for a browser (the URL carries
     *                                the token). Null renders a one-shot snapshot.
     */
    public function render(Aggregate $aggregate, ?string $refreshUrl = null): string
    {
        $totals      = $aggregate->totals;
        $requests    = $totals->requests;
        $connections = $totals->connections;
        $hasRequests = $requests !== null;
        $name        = $this->escape($aggregate->name);

        $refreshMeta = $refreshUrl !== null
            ? '<meta http-equiv="refresh" content="2;url=' . $this->escape($refreshUrl) . '">'
            : '';

        $hungMeta = $aggregate->workersHung > 0
            ? ' · <span class="hung">hung ' . $aggregate->workersHung . '</span>'
            : '';

        $head = '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">' . $refreshMeta . '
<title>' . $name . ' — stats</title>
<style>
 body{font:13px/1.4 ui-monospace,Menlo,Consolas,monospace;margin:1.2rem;color:#222}
 h1{font-size:1.05rem;margin:0 0 .2rem}
 .meta{color:#666;margin-bottom:1rem}
 .meta .hung{color:#a00}
 table{border-collapse:collapse;margin:.3rem 0 1.3rem}
 caption{text-align:left;font-weight:bold;margin-bottom:.3rem}
 th,td{border:1px solid #ddd;padding:.25rem .55rem;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
 th{background:#f4f4f4}
 th:first-child,td:first-child{text-align:left}
 tr.hung td{background:#fde8e8;color:#a00}
</style>
</head>
<body>
<h1>' . $name . '</h1>
<div class="meta">' . $this->escape($aggregate->generatedAt) . ' · workers ' . $aggregate->workersTotal . $hungMeta . '</div>'
            . $this->masterTable($aggregate);

        if ($requests !== null) {
            $workloadTotalsHead = '<th>completed</th><th>avg ms</th><th>in-flight</th><th>1–5s</th><th>5–15s</th><th>&gt;15s</th>';
            $workloadTotalsRow  = '<td>' . $requests->completed . '</td><td>' . $this->f1($requests->avgMs) . '</td><td>' . $requests->inFlight . '</td><td>' . $requests->inFlight1to5s . '</td><td>' . $requests->inFlight5to15s . '</td><td>' . $requests->inFlightOver15s . '</td>';
        } elseif ($connections !== null) {
            $workloadTotalsHead = '<th>active</th><th>accepted</th>';
            $workloadTotalsRow  = '<td>' . $connections->active . '</td><td>' . $connections->totalAccepted . '</td>';
        } else {
            $workloadTotalsHead = '';
            $workloadTotalsRow  = '';
        }

        $totalsTable = '
<table>
<caption>Totals</caption>
<tr>
<th>RSS, MiB</th><th>Go runtime, MiB</th><th>non-ext, MiB</th><th>CPU %</th><th>goroutines</th>
' . $workloadTotalsHead . '
</tr>
<tr>
<td>' . $this->mib($totals->memory->rssBytes) . '</td>
<td>' . $this->mib($totals->memory->goRuntimeBytes) . '</td>
<td>' . $this->mib($totals->memory->nonExtensionBytes) . '</td>
<td>' . $this->f1($totals->cpuPercent) . '</td>
<td>' . $totals->goroutines . '</td>
' . $workloadTotalsRow . '
</tr>
</table>';

        if ($requests !== null) {
            $workloadWorkersHead = '<th>completed</th><th>avg ms</th><th>in-flight</th>';
        } elseif ($connections !== null) {
            $workloadWorkersHead = '<th>active</th><th>accepted</th>';
        } else {
            $workloadWorkersHead = '';
        }

        $rows = '';

        foreach ($aggregate->workers as $worker) {
            $rows .= $this->workerRow($worker, $hasRequests);
        }

        $workersTable = '
<table>
<caption>Workers</caption>
<tr>
<th>pid</th><th>started (UTC)</th><th>uptime s</th><th>snap age ms</th><th>CPU %</th><th>RSS, MiB</th><th>goroutines</th>
' . $workloadWorkersHead . '
</tr>' . $rows . '
</table>
</body>
</html>';

        return $head . $totalsTable . $workersTable;
    }

    protected function masterTable(Aggregate $aggregate): string
    {
        $master = $aggregate->master;

        if ($master === null) {
            return '';
        }

        return '
<table>
<caption>Master</caption>
<tr>
<th>pid</th><th>started (UTC)</th><th>uptime s</th><th>CPU %</th><th>RSS, MiB</th>
</tr>
<tr>
<td>' . $master->pid . '</td>
<td>' . $this->utc($master->startedAtMs) . '</td>
<td>' . $this->f1($master->uptimeSeconds) . '</td>
<td>' . $this->f1($master->cpuPercent) . '</td>
<td>' . $this->mib($master->rssBytes) . '</td>
</tr>
</table>';
    }

    protected function workerRow(WorkerEntry $worker, bool $hasRequests): string
    {
        $class   = $worker->hung ? ' class="hung"' : '';
        $pidMark = $worker->hung ? ' ⚠' : '';

        if ($hasRequests) {
            $workload = $worker->requests !== null
                ? '<td>' . $worker->requests->completed . '</td><td>' . $this->f1($worker->requests->avgMs) . '</td><td>' . $worker->requests->inFlight . '</td>'
                : '<td>—</td><td>—</td><td>—</td>';
        } else {
            $workload = $worker->connections !== null
                ? '<td>' . $worker->connections->active . '</td><td>' . $worker->connections->totalAccepted . '</td>'
                : '<td>—</td><td>—</td>';
        }

        return '
<tr' . $class . '>
<td>' . $worker->pid . $pidMark . '</td>
<td>' . $this->utc($worker->startedAtMs) . '</td>
<td>' . $this->f1($worker->uptimeSeconds) . '</td>
<td>' . $worker->snapshotAgeMs . '</td>
<td>' . $this->f1($worker->cpuPercent) . '</td>
<td>' . $this->mib($worker->memory->rssBytes) . '</td>
<td>' . $worker->goroutines . '</td>
' . $workload . '
</tr>';
    }

    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    protected function utc(int $milliseconds): string
    {
        return $milliseconds > 0 ? gmdate('Y-m-d H:i:s', intdiv($milliseconds, 1000)) : '—';
    }

    protected function mib(int $bytes): string
    {
        return sprintf('%.1f', $bytes / (1024 * 1024));
    }

    protected function f1(float $value): string
    {
        return sprintf('%.1f', $value);
    }
}
