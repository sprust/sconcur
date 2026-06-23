package stats

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strconv"
	"testing"
	"time"
)

// writeSnapshotFile marshals a snapshot to <dir>/<name>-stats-<pid>.json so the
// aggregator can pick it up.
func writeSnapshotFile(t *testing.T, dir string, name string, snapshot Snapshot) string {
	t.Helper()

	path := filepath.Join(dir, name+"-stats-"+strconv.Itoa(snapshot.Pid)+".json")

	data, err := json.Marshal(snapshot)

	if err != nil {
		t.Fatalf("marshal snapshot: %v", err)
	}

	if err := os.WriteFile(path, data, 0o644); err != nil {
		t.Fatalf("write snapshot: %v", err)
	}

	return path
}

func TestAuthorizeBearer(t *testing.T) {
	cases := []struct {
		header string
		token  string
		want   bool
	}{
		{"Bearer secret", "secret", true},
		{"Bearer wrong", "secret", false},
		{"secret", "secret", false},        // missing Bearer prefix
		{"Bearer secret", "", false},       // no token configured
		{"", "secret", false},              // no header
		{"Bearer ", "secret", false},       // empty credential
		{"bearer secret", "secret", false}, // case-sensitive scheme
	}

	for _, testCase := range cases {
		if got := AuthorizeBearer(testCase.header, testCase.token); got != testCase.want {
			t.Errorf("AuthorizeBearer(%q, %q) = %v, want %v", testCase.header, testCase.token, got, testCase.want)
		}
	}
}

func TestAggregateSumsPrunesAndScopes(t *testing.T) {
	dir := t.TempDir()
	now := time.Now()
	nowMs := now.UnixMilli()

	// Two live workers (this process and its parent) of the target pool.
	writeSnapshotFile(t, dir, "srv", Snapshot{
		Name:        "srv",
		Pid:         os.Getpid(),
		UpdatedAtMs: nowMs,
		Memory:      Memory{RssBytes: 1000, GoRuntimeBytes: 400, NonExtensionBytes: 600},
		CpuPercent:  5,
		Goroutines:  3,
		Requests:    Requests{Completed: 10, AvgMs: 2.0, InFlight: 2, InFlight1to5s: 1},
	})

	writeSnapshotFile(t, dir, "srv", Snapshot{
		Name:        "srv",
		Pid:         os.Getppid(),
		UpdatedAtMs: nowMs,
		Memory:      Memory{RssBytes: 2000, GoRuntimeBytes: 600, NonExtensionBytes: 1400},
		CpuPercent:  7,
		Goroutines:  5,
		Requests:    Requests{Completed: 30, AvgMs: 6.0, InFlight: 1, InFlight5to15s: 1},
	})

	// A dead worker: its file must be pruned and excluded from the totals.
	deadPath := writeSnapshotFile(t, dir, "srv", Snapshot{
		Name:        "srv",
		Pid:         2147483647, // above pid_max → no such process
		UpdatedAtMs: nowMs,
		Requests:    Requests{Completed: 999},
	})

	// A different pool's file must be ignored (name scope).
	writeSnapshotFile(t, dir, "other", Snapshot{
		Name:        "other",
		Pid:         os.Getpid(),
		UpdatedAtMs: nowMs,
		Requests:    Requests{Completed: 7},
	})

	response := Aggregate(dir, "srv", now)

	if response.WorkersTotal != 2 {
		t.Fatalf("WorkersTotal = %d, want 2 (dead and other-named excluded)", response.WorkersTotal)
	}

	if response.WorkersHung != 0 {
		t.Errorf("WorkersHung = %d, want 0", response.WorkersHung)
	}

	if response.Totals.Memory.RssBytes != 3000 {
		t.Errorf("Totals RssBytes = %d, want 3000", response.Totals.Memory.RssBytes)
	}

	if response.Totals.Memory.NonExtensionBytes != 2000 {
		t.Errorf("Totals NonExtensionBytes = %d, want 2000", response.Totals.Memory.NonExtensionBytes)
	}

	if response.Totals.CpuPercent != 12 {
		t.Errorf("Totals CpuPercent = %v, want 12", response.Totals.CpuPercent)
	}

	if response.Totals.Goroutines != 8 {
		t.Errorf("Totals Goroutines = %d, want 8", response.Totals.Goroutines)
	}

	if response.Totals.Requests.Completed != 40 {
		t.Errorf("Totals Completed = %d, want 40", response.Totals.Requests.Completed)
	}

	// Weighted average: (2.0*10 + 6.0*30) / 40 = 5.0.
	if response.Totals.Requests.AvgMs != 5.0 {
		t.Errorf("Totals AvgMs = %v, want 5.0", response.Totals.Requests.AvgMs)
	}

	if response.Totals.Requests.InFlight != 3 {
		t.Errorf("Totals InFlight = %d, want 3", response.Totals.Requests.InFlight)
	}

	if response.GeneratedAt == "" {
		t.Error("GeneratedAt is empty")
	}

	if _, err := os.Stat(deadPath); !os.IsNotExist(err) {
		t.Errorf("dead worker file was not pruned: %v", err)
	}
}

func TestAggregateFlagsHung(t *testing.T) {
	dir := t.TempDir()
	now := time.Now()

	// A live worker whose snapshot has not been refreshed for 20s (> hungThreshold).
	writeSnapshotFile(t, dir, "srv", Snapshot{
		Name:        "srv",
		Pid:         os.Getpid(),
		UpdatedAtMs: now.Add(-20 * time.Second).UnixMilli(),
	})

	response := Aggregate(dir, "srv", now)

	if response.WorkersTotal != 1 {
		t.Fatalf("WorkersTotal = %d, want 1", response.WorkersTotal)
	}

	if response.WorkersHung != 1 {
		t.Errorf("WorkersHung = %d, want 1", response.WorkersHung)
	}

	if !response.Workers[0].Hung {
		t.Error("worker should be flagged hung")
	}

	if response.Workers[0].SnapshotAgeMs < 19000 {
		t.Errorf("SnapshotAgeMs = %d, want >= 19000", response.Workers[0].SnapshotAgeMs)
	}
}

func TestCollectorInFlightBucketsAreExclusive(t *testing.T) {
	now := time.Now()

	collector := NewCollector("srv", "", now)

	collector.RequestBegan("fresh", now.Add(-500*time.Millisecond)) // < 1s: counted, no bucket
	collector.RequestBegan("one", now.Add(-2*time.Second))          // [1s, 5s)
	collector.RequestBegan("five", now.Add(-7*time.Second))         // [5s, 15s)
	collector.RequestBegan("fifteen", now.Add(-20*time.Second))     // >= 15s

	requests := collector.snapshotRequests(now)

	if requests.InFlight != 4 {
		t.Errorf("InFlight = %d, want 4", requests.InFlight)
	}

	if requests.InFlight1to5s != 1 {
		t.Errorf("InFlight1to5s = %d, want 1", requests.InFlight1to5s)
	}

	if requests.InFlight5to15s != 1 {
		t.Errorf("InFlight5to15s = %d, want 1", requests.InFlight5to15s)
	}

	if requests.InFlightOver15s != 1 {
		t.Errorf("InFlightOver15s = %d, want 1", requests.InFlightOver15s)
	}
}

func TestCollectorCompletedCounters(t *testing.T) {
	collector := NewCollector("srv", "", time.Now())

	start := time.Now()

	collector.RequestBegan("a", start)
	collector.RequestEnded("a", start)
	collector.RequestBegan("b", start)
	collector.RequestEnded("b", start)

	requests := collector.snapshotRequests(time.Now())

	if requests.Completed != 2 {
		t.Errorf("Completed = %d, want 2", requests.Completed)
	}

	if requests.InFlight != 0 {
		t.Errorf("InFlight = %d, want 0 (both ended)", requests.InFlight)
	}
}

func TestCollectorWritesAndRemovesSnapshotFile(t *testing.T) {
	dir := t.TempDir()

	collector := NewCollector("srv", dir, time.Now())

	collector.Start()

	path := filepath.Join(dir, "srv-stats-"+strconv.Itoa(os.Getpid())+".json")

	if _, err := os.Stat(path); err != nil {
		t.Fatalf("snapshot file not written on Start: %v", err)
	}

	collector.Stop()

	if _, err := os.Stat(path); !os.IsNotExist(err) {
		t.Errorf("snapshot file not removed on Stop: %v", err)
	}
}
