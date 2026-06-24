package stats

import (
	"encoding/json"
	"net"
	"os"
	"runtime"
	"sconcur/internal/socket"
	"time"
)

const (
	// defaultIntervalMs is the snapshot-sample/push cadence when unset.
	defaultIntervalMs = 1000

	// pushWriteTimeout bounds one frame write so a slow or wedged collector never
	// blocks the push loop for long; on timeout the frame is dropped.
	pushWriteTimeout = 1 * time.Second
)

// snapshotFrame is the JSON envelope pushed over the wire. Type is the frame kind
// ("snapshot"); future kinds (worker.start, worker.stop, ...) add new Type values
// without breaking existing consumers.
type snapshotFrame struct {
	Type     string   `json:"t"`
	Snapshot Snapshot `json:"s"`
}

// Pusher samples one worker's snapshot and pushes it, framed, to the collector's
// unix socket. Best-effort: when the socket is absent it keeps retrying and drops
// frames in the meantime, never blocking the worker. A full snapshot (process
// metrics + workload) is sampled every interval — none of its sources stop the world
// (RSS/CPU from /proc, Go runtime memory from runtime/metrics), so the hot cadence is
// cheap.
type Pusher struct {
	name       string
	socketPath string
	interval   time.Duration
	pid        int
	startTime  time.Time
	provider   WorkloadProvider

	cpu cpuSampler

	connection net.Conn

	stop chan struct{}
	done chan struct{}
}

// NewPusher builds a pusher for one server. name labels the snapshot (pool scope);
// socketPath is the collector's unix socket; intervalMs is the sample/push cadence
// (0 = default). provider supplies the feature-specific counters.
func NewPusher(
	name string,
	socketPath string,
	intervalMs int,
	startTime time.Time,
	provider WorkloadProvider,
) *Pusher {
	return &Pusher{
		name:       name,
		socketPath: socketPath,
		interval:   msOrDefaultDuration(intervalMs, defaultIntervalMs),
		pid:        os.Getpid(),
		startTime:  startTime,
		provider:   provider,
		stop:       make(chan struct{}),
		done:       make(chan struct{}),
	}
}

// Start launches the background push loop. A pusher with no socket path does
// nothing (push disabled).
func (pusher *Pusher) Start() {
	if pusher.socketPath == "" {
		return
	}

	go pusher.loop()
}

// Stop ends the push loop and closes the connection. Safe to call when Start was a
// no-op (no socket path).
func (pusher *Pusher) Stop() {
	if pusher.socketPath == "" {
		return
	}

	close(pusher.stop)
	<-pusher.done

	if pusher.connection != nil {
		_ = pusher.connection.Close()
	}
}

func (pusher *Pusher) loop() {
	defer close(pusher.done)

	ticker := time.NewTicker(pusher.interval)
	defer ticker.Stop()

	for {
		select {
		case <-ticker.C:
			pusher.pushOnce()
		case <-pusher.stop:
			return
		}
	}
}

// pushOnce builds the current snapshot and writes one frame, dialing the collector
// first if not yet connected. Any write error drops the connection so the next tick
// redials; the frame itself is lost (best-effort, at-most-once).
func (pusher *Pusher) pushOnce() {
	if pusher.connection == nil {
		connection, err := net.DialTimeout("unix", pusher.socketPath, pushWriteTimeout)

		if err != nil {
			return
		}

		pusher.connection = connection
	}

	snapshot := pusher.buildSnapshot(time.Now())

	body, err := json.Marshal(snapshotFrame{Type: "snapshot", Snapshot: snapshot})

	if err != nil {
		return
	}

	_ = pusher.connection.SetWriteDeadline(time.Now().Add(pushWriteTimeout))

	if err := socket.WriteFrame(pusher.connection, body); err != nil {
		_ = pusher.connection.Close()

		pusher.connection = nil
	}
}

// buildSnapshot assembles a full snapshot: a fresh workload section plus the process
// metrics, all sampled now. CpuPercent is the rate since the previous sample.
func (pusher *Pusher) buildSnapshot(now time.Time) Snapshot {
	workload := pusher.provider.WorkloadSnapshot()

	return Snapshot{
		Name:          pusher.name,
		Pid:           pusher.pid,
		UpdatedAtMs:   now.UnixMilli(),
		StartedAtMs:   pusher.startTime.UnixMilli(),
		UptimeSeconds: now.Sub(pusher.startTime).Seconds(),
		Memory:        readMemory(),
		CpuPercent:    pusher.cpu.sample(now),
		Goroutines:    runtime.NumGoroutine(),
		Requests:      workload.Requests,
		Connections:   workload.Connections,
	}
}

func msOrDefaultDuration(ms int, fallback int) time.Duration {
	if ms <= 0 {
		ms = fallback
	}

	return time.Duration(ms) * time.Millisecond
}
