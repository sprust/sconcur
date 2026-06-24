package stats

import (
	"crypto/subtle"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"time"
)

const bearerPrefix = "Bearer "

// AuthorizeBearer reports whether the Authorization header carries the expected
// token as a Bearer credential. The compare is constant-time so a caller cannot
// probe the token byte by byte.
func AuthorizeBearer(authorizationHeader string, token string) bool {
	if token == "" {
		return false
	}

	if !strings.HasPrefix(authorizationHeader, bearerPrefix) {
		return false
	}

	provided := authorizationHeader[len(bearerPrefix):]

	return subtle.ConstantTimeCompare([]byte(provided), []byte(token)) == 1
}

// Aggregate reads every sibling snapshot file for the given server name, prunes
// the files of dead workers (under a directory lock), flags live-but-stale ones
// as hung, and sums the rest into an AggregateResponse. now is the reference
// clock for snapshot ages and the GeneratedAt timestamp.
func Aggregate(statsDir string, name string, now time.Time) AggregateResponse {
	response := AggregateResponse{
		GeneratedAt: now.Format(time.RFC3339),
		Name:        name,
		Workers:     []WorkerEntry{},
	}

	pattern := filepath.Join(statsDir, name+"-stats-*.json")

	files, err := filepath.Glob(pattern)

	if err != nil {
		return response
	}

	var deadFiles []string

	for _, file := range files {
		snapshot, ok := readSnapshot(file)

		if !ok {
			continue
		}

		if snapshot.Pid <= 0 {
			continue
		}

		if !isProcessAlive(snapshot.Pid) {
			deadFiles = append(deadFiles, file)

			continue
		}

		snapshotAgeMs := now.UnixMilli() - snapshot.UpdatedAtMs
		hung := time.Duration(snapshotAgeMs)*time.Millisecond > hungThreshold

		response.Workers = append(response.Workers, WorkerEntry{
			Pid:           snapshot.Pid,
			Hung:          hung,
			SnapshotAgeMs: snapshotAgeMs,
			UptimeSeconds: snapshot.UptimeSeconds,
			Memory:        snapshot.Memory,
			CpuPercent:    snapshot.CpuPercent,
			Goroutines:    snapshot.Goroutines,
			Requests:      snapshot.Requests,
			Connections:   snapshot.Connections,
		})
	}

	pruneDeadFiles(statsDir, deadFiles)

	fillTotals(&response)

	return response
}

// readSnapshot loads and decodes one snapshot file. A read or decode error (e.g.
// a file being rewritten, or a leftover from an old format) is treated as "skip".
func readSnapshot(file string) (Snapshot, bool) {
	data, err := os.ReadFile(file)

	if err != nil {
		return Snapshot{}, false
	}

	var snapshot Snapshot

	if err := json.Unmarshal(data, &snapshot); err != nil {
		return Snapshot{}, false
	}

	return snapshot, true
}

// fillTotals sums the worker entries into response.Totals and the worker/hung
// counts. Only the workload section present in the snapshots is filled: HTTP pools
// fill Requests (average weighted by completed), socket pools fill Connections.
func fillTotals(response *AggregateResponse) {
	response.WorkersTotal = len(response.Workers)

	var requestsTotal Requests
	var connectionsTotal Connections
	var weightedDurationMs float64

	hasRequests := false
	hasConnections := false

	for _, worker := range response.Workers {
		if worker.Hung {
			response.WorkersHung++
		}

		response.Totals.Memory.RssBytes += worker.Memory.RssBytes
		response.Totals.Memory.GoRuntimeBytes += worker.Memory.GoRuntimeBytes
		response.Totals.Memory.NonExtensionBytes += worker.Memory.NonExtensionBytes
		response.Totals.CpuPercent += worker.CpuPercent
		response.Totals.Goroutines += worker.Goroutines

		if worker.Requests != nil {
			hasRequests = true

			requestsTotal.Completed += worker.Requests.Completed
			requestsTotal.InFlight += worker.Requests.InFlight
			requestsTotal.InFlight1to5s += worker.Requests.InFlight1to5s
			requestsTotal.InFlight5to15s += worker.Requests.InFlight5to15s
			requestsTotal.InFlightOver15s += worker.Requests.InFlightOver15s

			weightedDurationMs += worker.Requests.AvgMs * float64(worker.Requests.Completed)
		}

		if worker.Connections != nil {
			hasConnections = true

			connectionsTotal.Active += worker.Connections.Active
			connectionsTotal.TotalAccepted += worker.Connections.TotalAccepted
		}
	}

	if hasRequests {
		if requestsTotal.Completed > 0 {
			requestsTotal.AvgMs = weightedDurationMs / float64(requestsTotal.Completed)
		}

		response.Totals.Requests = &requestsTotal
	}

	if hasConnections {
		response.Totals.Connections = &connectionsTotal
	}
}

// pruneDeadFiles removes the snapshot files of crashed workers. It takes a
// non-blocking exclusive lock on a shared lock file so two concurrent aggregators
// do not race on the deletes; if the lock is held, pruning is simply skipped this
// time (another aggregator is already doing it).
func pruneDeadFiles(statsDir string, deadFiles []string) {
	if len(deadFiles) == 0 {
		return
	}

	lockFile, err := os.OpenFile(filepath.Join(statsDir, ".prune.lock"), os.O_CREATE|os.O_RDWR, 0o644)

	if err != nil {
		return
	}

	defer lockFile.Close()

	if err := syscall.Flock(int(lockFile.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		return
	}

	defer syscall.Flock(int(lockFile.Fd()), syscall.LOCK_UN)

	for _, file := range deadFiles {
		_ = os.Remove(file)
	}
}

// isProcessAlive reports whether a process with the given pid exists. Signal 0
// performs the existence/permission check without delivering a signal; EPERM
// means the process exists but is owned by another user.
func isProcessAlive(pid int) bool {
	err := syscall.Kill(pid, 0)

	return err == nil || errors.Is(err, syscall.EPERM)
}
