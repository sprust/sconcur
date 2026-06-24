package stats

import (
	"strconv"
	"strings"
)

// metricsContentType is the Prometheus text exposition format content type.
const metricsContentType = "text/plain; version=0.0.4; charset=utf-8"

// workerMetric describes one per-worker metric family: its name, help text and a
// function extracting the sample value from a worker entry.
type workerMetric struct {
	name  string
	help  string
	value func(worker WorkerEntry) string
}

// renderMetrics builds the Prometheus text exposition of the aggregate response:
// pool-wide totals (sconcur_pool_*) plus per-worker series (sconcur_worker_*),
// both labelled with the pool name. This is the default representation, served
// when the client does not explicitly ask for JSON or HTML.
func renderMetrics(response AggregateResponse) []byte {
	var builder strings.Builder

	name := escapeMetricLabel(response.Name)
	poolLabels := `{name="` + name + `"}`

	gauge(&builder, "sconcur_pool_workers", "Live workers in the pool.", poolLabels, strconv.Itoa(response.WorkersTotal))
	gauge(&builder, "sconcur_pool_workers_hung", "Workers flagged hung (alive but stale snapshot).", poolLabels, strconv.Itoa(response.WorkersHung))

	gauge(&builder, "sconcur_pool_memory_rss_bytes", "Pool resident set size (with the extension).", poolLabels, strconv.FormatInt(response.Totals.Memory.RssBytes, 10))
	gauge(&builder, "sconcur_pool_memory_go_runtime_bytes", "Pool Go-runtime memory footprint.", poolLabels, strconv.FormatInt(response.Totals.Memory.GoRuntimeBytes, 10))
	gauge(&builder, "sconcur_pool_memory_non_extension_bytes", "Pool memory outside the extension (PHP interpreter).", poolLabels, strconv.FormatInt(response.Totals.Memory.NonExtensionBytes, 10))
	gauge(&builder, "sconcur_pool_cpu_percent", "Pool CPU usage (sum of per-process percentages).", poolLabels, formatFloat(response.Totals.CpuPercent))
	gauge(&builder, "sconcur_pool_goroutines", "Pool goroutine count.", poolLabels, strconv.Itoa(response.Totals.Goroutines))

	if requests := response.Totals.Requests; requests != nil {
		counter(&builder, "sconcur_pool_requests_completed_total", "Requests completed across the pool.", poolLabels, strconv.FormatInt(requests.Completed, 10))
		gauge(&builder, "sconcur_pool_requests_avg_ms", "Average request duration across the pool (weighted by completed).", poolLabels, formatFloat(requests.AvgMs))
		gauge(&builder, "sconcur_pool_requests_in_flight", "Requests in flight across the pool.", poolLabels, strconv.Itoa(requests.InFlight))
		gauge(&builder, "sconcur_pool_requests_in_flight_1to5s", "In-flight requests aged [1s, 5s).", poolLabels, strconv.Itoa(requests.InFlight1to5s))
		gauge(&builder, "sconcur_pool_requests_in_flight_5to15s", "In-flight requests aged [5s, 15s).", poolLabels, strconv.Itoa(requests.InFlight5to15s))
		gauge(&builder, "sconcur_pool_requests_in_flight_over15s", "In-flight requests aged >= 15s.", poolLabels, strconv.Itoa(requests.InFlightOver15s))
	}

	if connections := response.Totals.Connections; connections != nil {
		gauge(&builder, "sconcur_pool_connections_active", "Open connections across the pool.", poolLabels, strconv.Itoa(connections.Active))
		counter(&builder, "sconcur_pool_connections_accepted_total", "Connections accepted across the pool.", poolLabels, strconv.FormatInt(connections.TotalAccepted, 10))
	}

	writeWorkerMetrics(&builder, response, name)

	return []byte(builder.String())
}

// writeWorkerMetrics emits the per-worker series. The process metrics are always
// present; the workload families (requests or connections) are emitted only for
// the matching pool kind, and only for workers that carry the section.
func writeWorkerMetrics(builder *strings.Builder, response AggregateResponse, name string) {
	processMetrics := []workerMetric{
		{"sconcur_worker_hung", "Whether the worker is flagged hung (1) or not (0).", func(worker WorkerEntry) string { return boolMetric(worker.Hung) }},
		{"sconcur_worker_snapshot_age_ms", "Age of the worker's last snapshot, in milliseconds.", func(worker WorkerEntry) string { return strconv.FormatInt(worker.SnapshotAgeMs, 10) }},
		{"sconcur_worker_uptime_seconds", "Worker serve-loop uptime, in seconds.", func(worker WorkerEntry) string { return formatFloat(worker.UptimeSeconds) }},
		{"sconcur_worker_cpu_percent", "Worker CPU usage percent.", func(worker WorkerEntry) string { return formatFloat(worker.CpuPercent) }},
		{"sconcur_worker_goroutines", "Worker goroutine count.", func(worker WorkerEntry) string { return strconv.Itoa(worker.Goroutines) }},
		{"sconcur_worker_memory_rss_bytes", "Worker resident set size (with the extension).", func(worker WorkerEntry) string { return strconv.FormatInt(worker.Memory.RssBytes, 10) }},
		{"sconcur_worker_memory_go_runtime_bytes", "Worker Go-runtime memory footprint.", func(worker WorkerEntry) string { return strconv.FormatInt(worker.Memory.GoRuntimeBytes, 10) }},
		{"sconcur_worker_memory_non_extension_bytes", "Worker memory outside the extension (PHP interpreter).", func(worker WorkerEntry) string { return strconv.FormatInt(worker.Memory.NonExtensionBytes, 10) }},
	}

	for _, metric := range processMetrics {
		writeHeader(builder, metric.name, metric.help, "gauge")

		for _, worker := range response.Workers {
			builder.WriteString(metric.name + workerLabels(name, worker.Pid) + " " + metric.value(worker) + "\n")
		}
	}

	if response.Totals.Requests != nil {
		writeWorkerRequests(builder, response, name)
	}

	if response.Totals.Connections != nil {
		writeWorkerConnections(builder, response, name)
	}
}

// writeWorkerRequests emits the HTTP workload families per worker.
func writeWorkerRequests(builder *strings.Builder, response AggregateResponse, name string) {
	requestMetrics := []struct {
		name  string
		help  string
		mtype string
		value func(requests Requests) string
	}{
		{"sconcur_worker_requests_completed_total", "Requests completed by the worker.", "counter", func(requests Requests) string { return strconv.FormatInt(requests.Completed, 10) }},
		{"sconcur_worker_requests_avg_ms", "Average request duration for the worker.", "gauge", func(requests Requests) string { return formatFloat(requests.AvgMs) }},
		{"sconcur_worker_requests_in_flight", "Requests in flight on the worker.", "gauge", func(requests Requests) string { return strconv.Itoa(requests.InFlight) }},
	}

	for _, metric := range requestMetrics {
		writeHeader(builder, metric.name, metric.help, metric.mtype)

		for _, worker := range response.Workers {
			if worker.Requests == nil {
				continue
			}

			builder.WriteString(metric.name + workerLabels(name, worker.Pid) + " " + metric.value(*worker.Requests) + "\n")
		}
	}
}

// writeWorkerConnections emits the socket workload families per worker.
func writeWorkerConnections(builder *strings.Builder, response AggregateResponse, name string) {
	connectionMetrics := []struct {
		name  string
		help  string
		mtype string
		value func(connections Connections) string
	}{
		{"sconcur_worker_connections_active", "Open connections on the worker.", "gauge", func(connections Connections) string { return strconv.Itoa(connections.Active) }},
		{"sconcur_worker_connections_accepted_total", "Connections accepted by the worker.", "counter", func(connections Connections) string { return strconv.FormatInt(connections.TotalAccepted, 10) }},
	}

	for _, metric := range connectionMetrics {
		writeHeader(builder, metric.name, metric.help, metric.mtype)

		for _, worker := range response.Workers {
			if worker.Connections == nil {
				continue
			}

			builder.WriteString(metric.name + workerLabels(name, worker.Pid) + " " + metric.value(*worker.Connections) + "\n")
		}
	}
}

// gauge writes a single-sample gauge family (HELP + TYPE + the sample).
func gauge(builder *strings.Builder, name string, help string, labels string, value string) {
	writeHeader(builder, name, help, "gauge")
	builder.WriteString(name + labels + " " + value + "\n")
}

// counter writes a single-sample counter family (HELP + TYPE + the sample).
func counter(builder *strings.Builder, name string, help string, labels string, value string) {
	writeHeader(builder, name, help, "counter")
	builder.WriteString(name + labels + " " + value + "\n")
}

// writeHeader writes the HELP and TYPE comment lines for a metric family.
func writeHeader(builder *strings.Builder, name string, help string, mtype string) {
	builder.WriteString("# HELP " + name + " " + help + "\n")
	builder.WriteString("# TYPE " + name + " " + mtype + "\n")
}

// workerLabels builds the {name="...",pid="..."} label set for a worker series.
func workerLabels(name string, pid int) string {
	return `{name="` + name + `",pid="` + strconv.Itoa(pid) + `"}`
}

// boolMetric maps a boolean to the Prometheus convention of 1 (true) / 0 (false).
func boolMetric(value bool) string {
	if value {
		return "1"
	}

	return "0"
}

// formatFloat renders a float in the shortest exact decimal form Prometheus accepts.
func formatFloat(value float64) string {
	return strconv.FormatFloat(value, 'g', -1, 64)
}

// escapeMetricLabel escapes a label value per the exposition format (backslash,
// double quote and newline). The pool name is operator-controlled, but escaping
// keeps the output well-formed regardless.
func escapeMetricLabel(value string) string {
	value = strings.ReplaceAll(value, `\`, `\\`)
	value = strings.ReplaceAll(value, `"`, `\"`)
	value = strings.ReplaceAll(value, "\n", `\n`)

	return value
}
