package stats

import (
	"encoding/json"
	"os"
	"path/filepath"
	"runtime"
	"strconv"
	"sync"
	"sync/atomic"
	"time"
)

// Collector accumulates one worker's request counters and periodically writes the
// worker's snapshot file. It is created per server instance, started with Start
// and stopped with Stop (which removes the file for a clean exit).
type Collector struct {
	name      string
	statsDir  string
	pid       int
	startTime time.Time

	completed           atomic.Int64
	totalDurationMicros atomic.Int64
	// inFlight maps a request id to its start time, so a snapshot can bucket the
	// currently-running requests by age.
	inFlight sync.Map

	cpu  cpuSampler
	stop chan struct{}
	done chan struct{}
}

// NewCollector builds a collector for one server. name and statsDir define the
// snapshot file path <statsDir>/<name>-stats-<pid>.json.
func NewCollector(name string, statsDir string, startTime time.Time) *Collector {
	return &Collector{
		name:      name,
		statsDir:  statsDir,
		pid:       os.Getpid(),
		startTime: startTime,
		stop:      make(chan struct{}),
		done:      make(chan struct{}),
	}
}

// RequestBegan records a request entering handling, keyed by its id for the
// in-flight age buckets.
func (collector *Collector) RequestBegan(requestId string, start time.Time) {
	collector.inFlight.Store(requestId, start)
}

// RequestEnded records a finished request: drop it from the in-flight set and add
// its duration to the completed counters (for the running average).
func (collector *Collector) RequestEnded(requestId string, start time.Time) {
	collector.inFlight.Delete(requestId)
	collector.completed.Add(1)
	collector.totalDurationMicros.Add(time.Since(start).Microseconds())
}

// Start writes an initial snapshot (so the file exists promptly) and launches the
// background writer loop.
func (collector *Collector) Start() {
	if collector.statsDir == "" {
		return
	}

	_ = os.MkdirAll(collector.statsDir, 0o755)

	collector.writeSnapshot()

	go collector.loop()
}

// Stop ends the writer loop and removes the worker's snapshot file: a clean exit
// leaves no orphan. A crash leaves the file, to be pruned by a reader once the pid
// is gone.
func (collector *Collector) Stop() {
	if collector.statsDir == "" {
		return
	}

	close(collector.stop)
	<-collector.done

	_ = os.Remove(collector.filePath())
}

func (collector *Collector) loop() {
	defer close(collector.done)

	ticker := time.NewTicker(snapshotInterval)
	defer ticker.Stop()

	for {
		select {
		case <-ticker.C:
			collector.writeSnapshot()
		case <-collector.stop:
			return
		}
	}
}

func (collector *Collector) filePath() string {
	return filepath.Join(collector.statsDir, collector.name+"-stats-"+strconv.Itoa(collector.pid)+".json")
}

// writeSnapshot builds the current snapshot and writes it atomically (temp file +
// rename) so a reader never observes a half-written file.
func (collector *Collector) writeSnapshot() {
	now := time.Now()

	snapshot := Snapshot{
		Name:          collector.name,
		Pid:           collector.pid,
		UpdatedAtMs:   now.UnixMilli(),
		UptimeSeconds: now.Sub(collector.startTime).Seconds(),
		Memory:        readMemory(),
		CpuPercent:    collector.cpu.sample(now),
		Goroutines:    runtime.NumGoroutine(),
		Requests:      collector.snapshotRequests(now),
	}

	data, err := json.Marshal(snapshot)

	if err != nil {
		return
	}

	path := collector.filePath()
	temporaryPath := path + "." + strconv.Itoa(collector.pid) + ".tmp"

	if err := os.WriteFile(temporaryPath, data, 0o644); err != nil {
		return
	}

	if err := os.Rename(temporaryPath, path); err != nil {
		_ = os.Remove(temporaryPath)
	}
}

// snapshotRequests reads the request counters and buckets the in-flight requests
// by age (exclusive buckets).
func (collector *Collector) snapshotRequests(now time.Time) Requests {
	completed := collector.completed.Load()
	totalMicros := collector.totalDurationMicros.Load()

	averageMs := 0.0

	if completed > 0 {
		averageMs = float64(totalMicros) / float64(completed) / 1000.0
	}

	requests := Requests{
		Completed: completed,
		AvgMs:     averageMs,
	}

	collector.inFlight.Range(func(_ any, value any) bool {
		start, ok := value.(time.Time)

		if !ok {
			return true
		}

		age := now.Sub(start)

		requests.InFlight++

		switch {
		case age >= 15*time.Second:
			requests.InFlightOver15s++
		case age >= 5*time.Second:
			requests.InFlight5to15s++
		case age >= 1*time.Second:
			requests.InFlight1to5s++
		}

		return true
	})

	return requests
}
