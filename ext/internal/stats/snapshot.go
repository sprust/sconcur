// Package stats produces one worker's statistics snapshot and pushes it, framed,
// over a unix socket to an external collector (the PHP worker master, or any other
// supervisor that speaks the same contract). Aggregation, the live panel and the
// /api/stats HTTP endpoint live in the collector, not here — this package only
// samples and pushes.
//
// The whole feature lives on the Go side of a worker — the PHP cooperative loop is
// never involved. Pushing is best-effort and lossy: if the collector is absent the
// frame is dropped and the worker keeps serving traffic unaffected.
//
// Wire contract (so a third-party collector can consume it): a unix SOCK_STREAM
// connection carrying length-prefixed frames (internal/socket codec — 4-byte
// big-endian length + body); each body is UTF-8 JSON {"t":"snapshot","s":<Snapshot>}.
//
// The process-level metrics (memory, CPU, goroutines, uptime) are universal; the
// workload section is feature-specific and supplied through a WorkloadProvider
// (HTTP fills Requests, socket fills Connections).
package stats

// Memory holds the process memory split. RssBytes is the whole process resident
// set (with the extension); GoRuntimeBytes is the Go runtime's own footprint;
// NonExtensionBytes is the remainder (the PHP interpreter + Zend heap), derived
// as RssBytes - GoRuntimeBytes.
type Memory struct {
	RssBytes          int64 `json:"rssBytes"`
	GoRuntimeBytes    int64 `json:"goRuntimeBytes"`
	NonExtensionBytes int64 `json:"nonExtensionBytes"`
}

// Requests is the HTTP-server workload section. The in-flight buckets are
// exclusive: a request in flight for 7s counts only in InFlight5to15s.
type Requests struct {
	Completed       int64   `json:"completed"`
	AvgMs           float64 `json:"avgMs"`
	InFlight        int     `json:"inFlight"`
	InFlight1to5s   int     `json:"inFlight1to5s"`
	InFlight5to15s  int     `json:"inFlight5to15s"`
	InFlightOver15s int     `json:"inFlightOver15s"`
}

// Connections is the socket-server workload section: Active is the current open
// connection count, TotalAccepted is the lifetime number accepted.
type Connections struct {
	Active        int   `json:"active"`
	TotalAccepted int64 `json:"totalAccepted"`
}

// Workload is the feature-specific part of a snapshot, supplied by a
// WorkloadProvider. Exactly one section is set per server kind.
type Workload struct {
	Requests    *Requests    `json:"requests,omitempty"`
	Connections *Connections `json:"connections,omitempty"`
}

// WorkloadProvider yields the current feature-specific counters at snapshot time.
// HTTP returns Requests, socket returns Connections.
type WorkloadProvider interface {
	WorkloadSnapshot() Workload
}

// Snapshot is one worker's statistics, pushed as the "s" field of a snapshot frame.
// UpdatedAtMs is epoch-ms so the collector can compute the snapshot age (and a hung
// flag). The collector keys workers by the connection (and Pid for display).
type Snapshot struct {
	Name          string       `json:"name"`
	Pid           int          `json:"pid"`
	UpdatedAtMs   int64        `json:"updatedAtMs"`
	UptimeSeconds float64      `json:"uptimeSeconds"`
	Memory        Memory       `json:"memory"`
	CpuPercent    float64      `json:"cpuPercent"`
	Goroutines    int          `json:"goroutines"`
	Requests      *Requests    `json:"requests,omitempty"`
	Connections   *Connections `json:"connections,omitempty"`
}
