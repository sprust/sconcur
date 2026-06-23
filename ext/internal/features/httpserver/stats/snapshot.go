// Package stats implements the HTTP-server admin statistics: each worker process
// periodically writes its own snapshot file into a shared directory, and any
// worker can serve an aggregated view by reading every sibling's file. The whole
// feature lives on the Go side — the PHP cooperative loop is never involved, not
// at snapshot time and not when an admin request is served.
//
// Why out-of-band files and not the listener socket: with SO_REUSEPORT each
// worker is a separate process with its own counters, and the kernel routes an
// admin request to a single random worker. Reading the shared snapshot files lets
// that one worker still answer for the whole pool.
package stats

import "time"

// AdminStatsPath is the reserved request path the Go handler intercepts (before
// any crossing into PHP) to serve the aggregated statistics. It is active only
// when an admin token is configured.
const AdminStatsPath = "/sconcur-server-api/admin/stats"

const (
	// snapshotInterval is how often a worker rewrites its own snapshot file.
	// Hard-coded (not configurable) on purpose.
	snapshotInterval = 5 * time.Second

	// hungThreshold marks a worker whose snapshot has not been refreshed for this
	// long as "hung": its process is alive but its serve loop stopped updating
	// (3 missed ticks). Such a file is reported, never pruned.
	hungThreshold = 15 * time.Second
)

// Memory holds the process memory split. RssBytes is the whole process resident
// set (with the extension); GoRuntimeBytes is the Go runtime's own footprint;
// NonExtensionBytes is the remainder (the PHP interpreter + Zend heap), derived
// as RssBytes - GoRuntimeBytes.
type Memory struct {
	RssBytes          int64 `json:"rssBytes"`
	GoRuntimeBytes    int64 `json:"goRuntimeBytes"`
	NonExtensionBytes int64 `json:"nonExtensionBytes"`
}

// Requests holds the request counters. The in-flight buckets are exclusive: a
// request in flight for 7s counts only in InFlight5to15s, not in InFlight1to5s.
type Requests struct {
	Completed       int64   `json:"completed"`
	AvgMs           float64 `json:"avgMs"`
	InFlight        int     `json:"inFlight"`
	InFlight1to5s   int     `json:"inFlight1to5s"`
	InFlight5to15s  int     `json:"inFlight5to15s"`
	InFlightOver15s int     `json:"inFlightOver15s"`
}

// Snapshot is one worker's statistics, written to
// <statsDir>/<name>-stats-<pid>.json. UpdatedAtMs is epoch-ms so a reader can
// compute the snapshot age (and the hung flag).
type Snapshot struct {
	Name          string   `json:"name"`
	Pid           int      `json:"pid"`
	UpdatedAtMs   int64    `json:"updatedAtMs"`
	UptimeSeconds float64  `json:"uptimeSeconds"`
	Memory        Memory   `json:"memory"`
	CpuPercent    float64  `json:"cpuPercent"`
	Goroutines    int      `json:"goroutines"`
	Requests      Requests `json:"requests"`
}

// WorkerEntry is one worker in the aggregated response: its last snapshot plus
// the derived SnapshotAgeMs and Hung flag.
type WorkerEntry struct {
	Pid           int      `json:"pid"`
	Hung          bool     `json:"hung"`
	SnapshotAgeMs int64    `json:"snapshotAgeMs"`
	UptimeSeconds float64  `json:"uptimeSeconds"`
	Memory        Memory   `json:"memory"`
	CpuPercent    float64  `json:"cpuPercent"`
	Goroutines    int      `json:"goroutines"`
	Requests      Requests `json:"requests"`
}

// Totals is the pool-wide sum. CpuPercent is the sum of per-process percentages
// (so it may exceed 100%); Requests.AvgMs is weighted by each worker's Completed.
type Totals struct {
	Memory     Memory   `json:"memory"`
	CpuPercent float64  `json:"cpuPercent"`
	Goroutines int      `json:"goroutines"`
	Requests   Requests `json:"requests"`
}

// AggregateResponse is the JSON body returned by the admin endpoint. GeneratedAt
// is a human-readable RFC3339 timestamp of when the response was built.
type AggregateResponse struct {
	GeneratedAt  string        `json:"generatedAt"`
	Name         string        `json:"name"`
	WorkersTotal int           `json:"workersTotal"`
	WorkersHung  int           `json:"workersHung"`
	Totals       Totals        `json:"totals"`
	Workers      []WorkerEntry `json:"workers"`
}
