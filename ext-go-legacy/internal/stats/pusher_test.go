package stats

import (
	"encoding/json"
	"net"
	"path/filepath"
	"sconcur/internal/socket"
	"testing"
	"time"
)

type fakeProvider struct {
	workload Workload
}

func (provider fakeProvider) WorkloadSnapshot() Workload {
	return provider.workload
}

// acceptOneFrame accepts a single connection on the listener and returns the first
// decoded snapshot frame, or fails the test on timeout.
func acceptOneFrame(t *testing.T, listener net.Listener) snapshotFrame {
	t.Helper()

	_ = listener.(*net.UnixListener).SetDeadline(time.Now().Add(3 * time.Second))

	connection, err := listener.Accept()

	if err != nil {
		t.Fatalf("accept: %v", err)
	}

	defer connection.Close()

	_ = connection.SetReadDeadline(time.Now().Add(3 * time.Second))

	body, err := socket.ReadFrame(connection, 1<<20)

	if err != nil {
		t.Fatalf("read frame: %v", err)
	}

	var frame snapshotFrame

	if err := json.Unmarshal(body, &frame); err != nil {
		t.Fatalf("decode frame: %v", err)
	}

	return frame
}

func TestPusherPushesSnapshotFrame(t *testing.T) {
	socketPath := filepath.Join(t.TempDir(), "t.sock")

	listener, err := net.Listen("unix", socketPath)

	if err != nil {
		t.Fatalf("listen: %v", err)
	}

	defer listener.Close()

	provider := fakeProvider{workload: Workload{Requests: &Requests{Completed: 7}}}

	pusher := NewPusher("srv", socketPath, 20, time.Now(), provider)

	pusher.Start()

	defer pusher.Stop()

	frame := acceptOneFrame(t, listener)

	if frame.Type != "snapshot" {
		t.Errorf("frame type = %q, want snapshot", frame.Type)
	}

	if frame.Snapshot.Name != "srv" {
		t.Errorf("snapshot name = %q, want srv", frame.Snapshot.Name)
	}

	if frame.Snapshot.Requests == nil || frame.Snapshot.Requests.Completed != 7 {
		t.Errorf("snapshot workload not carried: %+v", frame.Snapshot.Requests)
	}

	if frame.Snapshot.Pid <= 0 {
		t.Errorf("snapshot pid = %d, want > 0", frame.Snapshot.Pid)
	}
}

func TestPusherReconnectsAfterDrop(t *testing.T) {
	socketPath := filepath.Join(t.TempDir(), "t.sock")

	listener, err := net.Listen("unix", socketPath)

	if err != nil {
		t.Fatalf("listen: %v", err)
	}

	defer listener.Close()

	pusher := NewPusher("srv", socketPath, 20, time.Now(), fakeProvider{})

	pusher.Start()

	defer pusher.Stop()

	// First connection: accept and immediately drop it (acceptOneFrame closes it).
	acceptOneFrame(t, listener)

	// The pusher must redial and push again on a subsequent tick.
	frame := acceptOneFrame(t, listener)

	if frame.Type != "snapshot" {
		t.Errorf("frame type after reconnect = %q, want snapshot", frame.Type)
	}
}

func TestPusherEmptySocketIsNoop(t *testing.T) {
	pusher := NewPusher("srv", "", 20, time.Now(), fakeProvider{})

	// Must not panic, must not block, must not dial anything.
	pusher.Start()
	pusher.Stop()
}
