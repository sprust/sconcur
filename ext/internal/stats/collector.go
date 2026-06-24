package stats

import (
	"encoding/json"
	"os"
	"path/filepath"
	"runtime"
	"strconv"
	"time"
)

// Collector periodically writes one worker's snapshot file. It owns the universal
// process metrics; the feature-specific workload section comes from the
// WorkloadProvider it is built with. Create per server instance, Start to launch
// the writer, Stop to end it and remove the file (clean exit leaves no orphan).
type Collector struct {
	name      string
	statsDir  string
	pid       int
	startTime time.Time
	provider  WorkloadProvider

	cpu  cpuSampler
	stop chan struct{}
	done chan struct{}
}

// NewCollector builds a collector for one server. name and statsDir define the
// snapshot file path <statsDir>/<name>-stats-<pid>.json; provider supplies the
// feature-specific counters at each snapshot.
func NewCollector(name string, statsDir string, startTime time.Time, provider WorkloadProvider) *Collector {
	return &Collector{
		name:      name,
		statsDir:  statsDir,
		pid:       os.Getpid(),
		startTime: startTime,
		provider:  provider,
		stop:      make(chan struct{}),
		done:      make(chan struct{}),
	}
}

// Start writes an initial snapshot (so the file exists promptly) and launches the
// background writer loop. A collector with no statsDir does nothing.
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

	workload := collector.provider.WorkloadSnapshot()

	snapshot := Snapshot{
		Name:          collector.name,
		Pid:           collector.pid,
		UpdatedAtMs:   now.UnixMilli(),
		UptimeSeconds: now.Sub(collector.startTime).Seconds(),
		Memory:        readMemory(),
		CpuPercent:    collector.cpu.sample(now),
		Goroutines:    runtime.NumGoroutine(),
		Requests:      workload.Requests,
		Connections:   workload.Connections,
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
