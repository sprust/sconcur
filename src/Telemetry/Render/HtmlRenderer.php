<?php

declare(strict_types=1);

namespace SConcur\Telemetry\Render;

use SConcur\Telemetry\Dto\Aggregate;
use SConcur\Telemetry\Dto\Connections;
use SConcur\Telemetry\Dto\Consumers;
use SConcur\Telemetry\Dto\Requests;
use SConcur\Telemetry\Dto\WorkerEntry;

/**
 * Renders the aggregate as a compact, dependency-free admin page — a header line, a totals
 * row and a per-worker table.
 *
 * The workload columns are whichever sections the pools reported — requests, connections,
 * consumers, or several of them side by side under one master. They are chosen once from
 * the pool totals so every row has the same shape, and a worker missing that section shows
 * dashes. Hung workers are highlighted, and every interpolated value is escaped.
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
        $consumers   = $totals->consumers;
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

        // Every section that is present, not the first of them: one master runs unlike
        // pools, and showing only requests would hide a consumer pool's numbers entirely.
        $workloadTotalsHead = '';
        $workloadTotalsRow  = '';

        if ($requests !== null) {
            $workloadTotalsHead .= '<th>completed</th><th>avg ms</th><th>in-flight</th><th>1–5s</th><th>5–15s</th><th>&gt;15s</th>';
            $workloadTotalsRow .= '<td>' . $requests->completed . '</td><td>' . $this->f1($requests->avgMs) . '</td><td>' . $requests->inFlight . '</td><td>' . $requests->inFlight1to5s . '</td><td>' . $requests->inFlight5to15s . '</td><td>' . $requests->inFlightOver15s . '</td>';
        }

        if ($connections !== null) {
            $workloadTotalsHead .= '<th>active</th><th>accepted</th>';
            $workloadTotalsRow .= '<td>' . $connections->active . '</td><td>' . $connections->totalAccepted . '</td>';
        }

        if ($consumers !== null) {
            $workloadTotalsHead .= '<th>coroutines</th><th>delivered</th><th>acked</th><th>refused</th><th>avg ms</th><th>in-flight</th>';
            $workloadTotalsRow .= '<td>' . $consumers->coroutines . '</td><td>' . $consumers->delivered . '</td><td>' . $consumers->acked . '</td><td>' . $consumers->refused . '</td><td>' . $this->f1($consumers->avgMs) . '</td><td>' . $consumers->inFlight . '</td>';
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

        $groupsTable = $this->groupsTable($aggregate);

        $rows = '';

        foreach ($aggregate->workers as $worker) {
            $rows .= $this->workerRow($worker);
        }

        $workersTable = '
<table>
<caption>Workers</caption>
<tr>
<th>group</th><th>pid</th><th>started (UTC)</th><th>uptime s</th><th>snap age ms</th><th>CPU %</th><th>RSS, MiB</th><th>goroutines</th><th>workload</th>
</tr>' . $rows . '
</table>
</body>
</html>';

        return $head . $totalsTable . $groupsTable . $workersTable;
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

    /**
     * One row per pool. A master runs several, and their workload numbers are not
     * comparable, so this is the table an operator actually reads; the totals above it
     * are only meaningful for memory and CPU. Omitted when there is a single pool —
     * it would just repeat the totals.
     */
    protected function groupsTable(Aggregate $aggregate): string
    {
        if (count($aggregate->groups) < 2) {
            return '';
        }

        $rows = '';

        foreach ($aggregate->groups as $group) {
            $rows .= '
<tr>
<td>' . $this->escape($group->name) . '</td>
<td>' . $group->workersTotal . '</td>
<td>' . $group->workersHung . '</td>
<td>' . $this->mib($group->totals->memory->rssBytes) . '</td>
<td>' . $this->f1($group->totals->cpuPercent) . '</td>
<td>' . $group->totals->goroutines . '</td>
<td>' . $this->workloadCell(
                $group->totals->requests,
                $group->totals->connections,
                $group->totals->consumers,
            ) . '</td>
</tr>';
        }

        return '
<table>
<caption>Groups</caption>
<tr>
<th>group</th><th>workers</th><th>hung</th><th>RSS, MiB</th><th>CPU %</th><th>goroutines</th><th>workload</th>
</tr>' . $rows . '
</table>';
    }

    /**
     * A workload in one cell, because the columns differ by kind: one master runs
     * unlike pools, and a shared column set would show one of them and leave the rest
     * with dashes.
     */
    protected function workloadCell(
        ?Requests $requests,
        ?Connections $connections,
        ?Consumers $consumers,
    ): string {
        if ($requests !== null) {
            return 'requests ' . $requests->completed
                . ', avg ' . $this->f1($requests->avgMs) . ' ms'
                . ', in-flight ' . $requests->inFlight;
        }

        if ($connections !== null) {
            return 'connections ' . $connections->active
                . ', accepted ' . $connections->totalAccepted;
        }

        if ($consumers !== null) {
            return 'coroutines ' . $consumers->coroutines
                . ', delivered ' . $consumers->delivered
                . ', acked ' . $consumers->acked
                . ', refused ' . $consumers->refused
                . ', avg ' . $this->f1($consumers->avgMs) . ' ms'
                . ', in-flight ' . $consumers->inFlight;
        }

        return '—';
    }

    protected function workerRow(WorkerEntry $worker): string
    {
        $class   = $worker->hung ? ' class="hung"' : '';
        $pidMark = $worker->hung ? ' ⚠' : '';

        return '
<tr' . $class . '>
<td>' . $this->escape($worker->group) . '</td>
<td>' . $worker->pid . $pidMark . '</td>
<td>' . $this->utc($worker->startedAtMs) . '</td>
<td>' . $this->f1($worker->uptimeSeconds) . '</td>
<td>' . $worker->snapshotAgeMs . '</td>
<td>' . $this->f1($worker->cpuPercent) . '</td>
<td>' . $this->mib($worker->memory->rssBytes) . '</td>
<td>' . $worker->goroutines . '</td>
<td>' . $this->workloadCell(
            requests: $worker->requests,
            connections: $worker->connections,
            consumers: $worker->consumers,
        ) . '</td>
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
