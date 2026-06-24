package stats

import (
	"encoding/json"
	"io"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"
)

type fakeProvider struct {
	workload Workload
}

func (provider fakeProvider) WorkloadSnapshot() Workload {
	return provider.workload
}

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

// freePort returns a currently-free TCP port on the loopback interface.
func freePort(t *testing.T) int {
	t.Helper()

	listener, err := net.Listen("tcp", "127.0.0.1:0")

	if err != nil {
		t.Fatalf("listen: %v", err)
	}

	defer listener.Close()

	return listener.Addr().(*net.TCPAddr).Port
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

	// Two live HTTP workers (this process and its parent) of the target pool.
	writeSnapshotFile(t, dir, "srv", Snapshot{
		Name:        "srv",
		Pid:         os.Getpid(),
		UpdatedAtMs: nowMs,
		Memory:      Memory{RssBytes: 1000, GoRuntimeBytes: 400, NonExtensionBytes: 600},
		CpuPercent:  5,
		Goroutines:  3,
		Requests:    &Requests{Completed: 10, AvgMs: 2.0, InFlight: 2, InFlight1to5s: 1},
	})

	writeSnapshotFile(t, dir, "srv", Snapshot{
		Name:        "srv",
		Pid:         os.Getppid(),
		UpdatedAtMs: nowMs,
		Memory:      Memory{RssBytes: 2000, GoRuntimeBytes: 600, NonExtensionBytes: 1400},
		CpuPercent:  7,
		Goroutines:  5,
		Requests:    &Requests{Completed: 30, AvgMs: 6.0, InFlight: 1, InFlight5to15s: 1},
	})

	// A dead worker: its file must be pruned and excluded from the totals.
	deadPath := writeSnapshotFile(t, dir, "srv", Snapshot{
		Name:        "srv",
		Pid:         2147483647, // above pid_max → no such process
		UpdatedAtMs: nowMs,
		Requests:    &Requests{Completed: 999},
	})

	// A different pool's file must be ignored (name scope).
	writeSnapshotFile(t, dir, "other", Snapshot{
		Name:        "other",
		Pid:         os.Getpid(),
		UpdatedAtMs: nowMs,
		Requests:    &Requests{Completed: 7},
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

	if response.Totals.CpuPercent != 12 {
		t.Errorf("Totals CpuPercent = %v, want 12", response.Totals.CpuPercent)
	}

	if response.Totals.Goroutines != 8 {
		t.Errorf("Totals Goroutines = %d, want 8", response.Totals.Goroutines)
	}

	if response.Totals.Requests == nil {
		t.Fatal("Totals.Requests is nil, want HTTP totals")
	}

	if response.Totals.Requests.Completed != 40 {
		t.Errorf("Totals Completed = %d, want 40", response.Totals.Requests.Completed)
	}

	// Weighted average: (2.0*10 + 6.0*30) / 40 = 5.0.
	if response.Totals.Requests.AvgMs != 5.0 {
		t.Errorf("Totals AvgMs = %v, want 5.0", response.Totals.Requests.AvgMs)
	}

	if response.Totals.Connections != nil {
		t.Error("Totals.Connections should be nil for an HTTP pool")
	}

	if _, err := os.Stat(deadPath); !os.IsNotExist(err) {
		t.Errorf("dead worker file was not pruned: %v", err)
	}
}

func TestAggregateSumsConnections(t *testing.T) {
	dir := t.TempDir()
	now := time.Now()
	nowMs := now.UnixMilli()

	writeSnapshotFile(t, dir, "sock", Snapshot{
		Name:        "sock",
		Pid:         os.Getpid(),
		UpdatedAtMs: nowMs,
		Connections: &Connections{Active: 5, TotalAccepted: 100},
	})

	writeSnapshotFile(t, dir, "sock", Snapshot{
		Name:        "sock",
		Pid:         os.Getppid(),
		UpdatedAtMs: nowMs,
		Connections: &Connections{Active: 7, TotalAccepted: 250},
	})

	response := Aggregate(dir, "sock", now)

	if response.Totals.Connections == nil {
		t.Fatal("Totals.Connections is nil, want socket totals")
	}

	if response.Totals.Connections.Active != 12 {
		t.Errorf("Totals Active = %d, want 12", response.Totals.Connections.Active)
	}

	if response.Totals.Connections.TotalAccepted != 350 {
		t.Errorf("Totals TotalAccepted = %d, want 350", response.Totals.Connections.TotalAccepted)
	}

	if response.Totals.Requests != nil {
		t.Error("Totals.Requests should be nil for a socket pool")
	}
}

func TestAggregateFlagsHung(t *testing.T) {
	dir := t.TempDir()
	now := time.Now()

	writeSnapshotFile(t, dir, "srv", Snapshot{
		Name:        "srv",
		Pid:         os.Getpid(),
		UpdatedAtMs: now.Add(-20 * time.Second).UnixMilli(),
	})

	response := Aggregate(dir, "srv", now)

	if response.WorkersHung != 1 || !response.Workers[0].Hung {
		t.Errorf("worker should be flagged hung: hung=%d", response.WorkersHung)
	}

	if response.Workers[0].SnapshotAgeMs < 19000 {
		t.Errorf("SnapshotAgeMs = %d, want >= 19000", response.Workers[0].SnapshotAgeMs)
	}
}

func TestCollectorWritesAndRemovesSnapshotFile(t *testing.T) {
	dir := t.TempDir()

	provider := fakeProvider{workload: Workload{Requests: &Requests{Completed: 1}}}

	collector := NewCollector("srv", dir, time.Now(), provider)

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

func TestServerServesAggregate(t *testing.T) {
	dir := t.TempDir()

	writeSnapshotFile(t, dir, "srv", Snapshot{
		Name:        "srv",
		Pid:         os.Getpid(),
		UpdatedAtMs: time.Now().UnixMilli(),
		Requests:    &Requests{Completed: 42},
	})

	address := "127.0.0.1:" + strconv.Itoa(freePort(t))

	server, err := NewServer(address, "secret", dir, "srv")

	if err != nil {
		t.Fatalf("NewServer: %v", err)
	}

	defer server.Close()

	baseUrl := "http://" + address

	// Valid token + Accept: application/json → 200 + aggregate JSON.
	status, contentType, body := getStats(t, baseUrl+StatsPath, "Bearer secret", "application/json")

	if status != 200 {
		t.Fatalf("valid token status = %d, want 200", status)
	}

	if !strings.Contains(contentType, "application/json") {
		t.Errorf("content type = %q, want application/json", contentType)
	}

	var response AggregateResponse

	if err := json.Unmarshal([]byte(body), &response); err != nil {
		t.Fatalf("decode aggregate: %v", err)
	}

	if response.Totals.Requests == nil || response.Totals.Requests.Completed != 42 {
		t.Errorf("aggregate did not include the snapshot: %s", body)
	}

	// Wrong token → 404.
	if status, _ := doGet(t, baseUrl+StatsPath, "Bearer wrong"); status != 404 {
		t.Errorf("wrong token status = %d, want 404", status)
	}

	// No token → 404.
	if status, _ := doGet(t, baseUrl+StatsPath, ""); status != 404 {
		t.Errorf("no token status = %d, want 404", status)
	}

	// Unknown path → 404.
	if status, _ := doGet(t, baseUrl+"/other", "Bearer secret"); status != 404 {
		t.Errorf("unknown path status = %d, want 404", status)
	}
}

func TestServerNegotiatesFormat(t *testing.T) {
	dir := t.TempDir()

	writeSnapshotFile(t, dir, "srv", Snapshot{
		Name:        "srv",
		Pid:         os.Getpid(),
		UpdatedAtMs: time.Now().UnixMilli(),
		Requests:    &Requests{Completed: 42},
	})

	address := "127.0.0.1:" + strconv.Itoa(freePort(t))

	server, err := NewServer(address, "secret", dir, "srv")

	if err != nil {
		t.Fatalf("NewServer: %v", err)
	}

	defer server.Close()

	url := "http://" + address + StatsPath

	// Accept: text/html → 200 HTML page.
	status, contentType, body := getStats(t, url, "Bearer secret", "text/html,application/xhtml+xml")

	if status != 200 {
		t.Fatalf("html status = %d, want 200", status)
	}

	if !strings.Contains(contentType, "text/html") {
		t.Errorf("html content type = %q, want text/html", contentType)
	}

	if !strings.Contains(body, "<table") || !strings.Contains(body, "srv") {
		t.Errorf("html body does not look like the stats page: %s", body)
	}

	// No Accept → 200 Prometheus metrics (the default).
	status, contentType, body = getStats(t, url, "Bearer secret", "")

	if status != 200 {
		t.Fatalf("metrics status = %d, want 200", status)
	}

	if !strings.Contains(contentType, "text/plain") {
		t.Errorf("metrics content type = %q, want text/plain", contentType)
	}

	if !strings.Contains(body, `sconcur_pool_requests_completed_total{name="srv"} 42`) {
		t.Errorf("metrics body missing the completed total: %s", body)
	}

	if !strings.Contains(body, "sconcur_pool_workers{") {
		t.Errorf("metrics body missing the pool workers gauge: %s", body)
	}
}

func TestNegotiateFormat(t *testing.T) {
	cases := []struct {
		accept string
		want   responseFormat
	}{
		{"application/json", formatJSON},
		{"text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8", formatHTML},
		{"text/html", formatHTML},
		{"application/json, text/html", formatJSON}, // explicit JSON wins over html
		{"", formatMetrics},                         // no header → metrics
		{"*/*", formatMetrics},                      // curl default → metrics
		{"text/plain;version=0.0.4", formatMetrics}, // Prometheus scraper → metrics
	}

	for _, testCase := range cases {
		if got := negotiateFormat(testCase.accept); got != testCase.want {
			t.Errorf("negotiateFormat(%q) = %d, want %d", testCase.accept, got, testCase.want)
		}
	}
}

func TestServerRejectsNonGetWith405(t *testing.T) {
	dir := t.TempDir()

	writeSnapshotFile(t, dir, "srv", Snapshot{
		Name:        "srv",
		Pid:         os.Getpid(),
		UpdatedAtMs: time.Now().UnixMilli(),
	})

	address := "127.0.0.1:" + strconv.Itoa(freePort(t))

	server, err := NewServer(address, "secret", dir, "srv")

	if err != nil {
		t.Fatalf("NewServer: %v", err)
	}

	defer server.Close()

	request, err := http.NewRequest(http.MethodPost, "http://"+address+StatsPath, nil)

	if err != nil {
		t.Fatalf("new request: %v", err)
	}

	request.Header.Set("Authorization", "Bearer secret")

	response, err := http.DefaultClient.Do(request)

	if err != nil {
		t.Fatalf("do request: %v", err)
	}

	defer response.Body.Close()

	// A valid token but a non-GET method is 405 (the path exists for this caller).
	if response.StatusCode != http.StatusMethodNotAllowed {
		t.Errorf("POST status = %d, want 405", response.StatusCode)
	}
}

func TestMaybeStartServerGating(t *testing.T) {
	dir := t.TempDir()

	// No port → not started.
	if server := MaybeStartServer(0, "secret", dir, "srv"); server != nil {
		server.Close()
		t.Error("MaybeStartServer started with port 0, want nil")
	}

	// No token → not started.
	if server := MaybeStartServer(freePort(t), "", dir, "srv"); server != nil {
		server.Close()
		t.Error("MaybeStartServer started without a token, want nil")
	}

	// Bind failure (port out of range) → logged, returns nil, never panics.
	if server := MaybeStartServer(99999999, "secret", dir, "srv"); server != nil {
		server.Close()
		t.Error("MaybeStartServer started on an invalid port, want nil")
	}

	// Both set and bindable → started; Close is safe.
	server := MaybeStartServer(freePort(t), "secret", dir, "srv")

	if server == nil {
		t.Fatal("MaybeStartServer returned nil with a valid port and token")
	}

	server.Close()
}

func TestAggregateEmptyPool(t *testing.T) {
	response := Aggregate(t.TempDir(), "srv", time.Now())

	if response.WorkersTotal != 0 || response.WorkersHung != 0 {
		t.Errorf("empty pool counts = %d/%d, want 0/0", response.WorkersTotal, response.WorkersHung)
	}

	// Workers must be a non-nil empty slice so it renders as [] in JSON, not null.
	if response.Workers == nil {
		t.Error("Workers is nil, want an empty slice")
	}

	if len(response.Workers) != 0 {
		t.Errorf("Workers = %d entries, want 0", len(response.Workers))
	}

	if response.Totals.Requests != nil || response.Totals.Connections != nil {
		t.Error("empty pool must have no workload section in totals")
	}
}

func TestRenderMetricsConnectionsAndEscaping(t *testing.T) {
	response := AggregateResponse{
		GeneratedAt:  "2026-01-01T00:00:00Z",
		Name:         `po"ol`, // exercises label escaping
		WorkersTotal: 2,
		WorkersHung:  1,
		Totals: Totals{
			Memory:      Memory{RssBytes: 100, GoRuntimeBytes: 40, NonExtensionBytes: 60},
			CpuPercent:  5,
			Goroutines:  7,
			Connections: &Connections{Active: 3, TotalAccepted: 50},
		},
		Workers: []WorkerEntry{
			{Pid: 11, Connections: &Connections{Active: 1, TotalAccepted: 20}},
			{Pid: 12, Hung: true, Connections: &Connections{Active: 2, TotalAccepted: 30}},
		},
	}

	body := string(renderMetrics(response))

	wants := []string{
		`sconcur_pool_workers{name="po\"ol"} 2`,
		`sconcur_pool_workers_hung{name="po\"ol"} 1`,
		`sconcur_pool_connections_active{name="po\"ol"} 3`,
		`sconcur_pool_connections_accepted_total{name="po\"ol"} 50`,
		`sconcur_worker_connections_active{name="po\"ol",pid="11"} 1`,
		`sconcur_worker_hung{name="po\"ol",pid="12"} 1`,
		"# TYPE sconcur_pool_connections_accepted_total counter",
	}

	for _, want := range wants {
		if !strings.Contains(body, want) {
			t.Errorf("metrics output missing %q\n--- got ---\n%s", want, body)
		}
	}

	// A connections pool must not emit request metrics.
	if strings.Contains(body, "sconcur_pool_requests") {
		t.Errorf("connections pool emitted request metrics:\n%s", body)
	}
}

func TestRenderHTMLConnectionsHungAndDashes(t *testing.T) {
	response := AggregateResponse{
		GeneratedAt:  "2026-01-01T00:00:00Z",
		Name:         "sock",
		WorkersTotal: 2,
		WorkersHung:  1,
		Totals: Totals{
			Connections: &Connections{Active: 3, TotalAccepted: 50},
		},
		Workers: []WorkerEntry{
			{Pid: 11, Hung: true, Connections: &Connections{Active: 2, TotalAccepted: 30}},
			{Pid: 12}, // no Connections section → dashes
		},
	}

	body, err := renderHTML(response)

	if err != nil {
		t.Fatalf("renderHTML: %v", err)
	}

	page := string(body)

	wants := []string{
		"<th>active</th>", // connections columns chosen
		"<th>accepted</th>",
		`class="hung"`, // hung worker highlighted
		"—",            // worker without a connections section shows dashes
	}

	for _, want := range wants {
		if !strings.Contains(page, want) {
			t.Errorf("html output missing %q\n--- got ---\n%s", want, page)
		}
	}

	if strings.Contains(page, "completed") {
		t.Errorf("connections pool HTML showed request columns:\n%s", page)
	}
}

func TestPruneDeadFilesRechecksLiveness(t *testing.T) {
	dir := t.TempDir()

	reusedPath := filepath.Join(dir, "reused.json")
	deadPath := filepath.Join(dir, "dead.json")

	if err := os.WriteFile(reusedPath, []byte("{}"), 0o644); err != nil {
		t.Fatalf("write reused: %v", err)
	}

	if err := os.WriteFile(deadPath, []byte("{}"), 0o644); err != nil {
		t.Fatalf("write dead: %v", err)
	}

	// reusedPath was scanned as dead, but by prune time its pid is alive again (pid
	// reused by a fresh worker) — it must be kept. deadPath stays genuinely dead.
	pruneDeadFiles(dir, []deadSnapshot{
		{path: reusedPath, pid: os.Getpid()},
		{path: deadPath, pid: 2147483647}, // above pid_max → no such process
	})

	if _, err := os.Stat(reusedPath); err != nil {
		t.Errorf("file of a reused (now-alive) pid was deleted: %v", err)
	}

	if _, err := os.Stat(deadPath); !os.IsNotExist(err) {
		t.Errorf("file of a genuinely dead pid was not pruned: %v", err)
	}
}

func TestCollectorEmptyStatsDirIsNoop(t *testing.T) {
	collector := NewCollector("srv", "", time.Now(), fakeProvider{})

	// Must not panic, must not block, must write no file.
	collector.Start()
	collector.Stop()
}

// doGet performs a GET with an optional Authorization header and returns the
// status and body.
func doGet(t *testing.T, url string, authorization string) (int, string) {
	t.Helper()

	request, err := http.NewRequest(http.MethodGet, url, nil)

	if err != nil {
		t.Fatalf("new request: %v", err)
	}

	if authorization != "" {
		request.Header.Set("Authorization", authorization)
	}

	response, err := http.DefaultClient.Do(request)

	if err != nil {
		t.Fatalf("do request: %v", err)
	}

	defer response.Body.Close()

	body, _ := io.ReadAll(response.Body)

	return response.StatusCode, string(body)
}

// getStats performs a GET with an Authorization and an optional Accept header and
// returns the status, the Content-Type and the body.
func getStats(t *testing.T, url string, authorization string, accept string) (int, string, string) {
	t.Helper()

	request, err := http.NewRequest(http.MethodGet, url, nil)

	if err != nil {
		t.Fatalf("new request: %v", err)
	}

	if authorization != "" {
		request.Header.Set("Authorization", authorization)
	}

	if accept != "" {
		request.Header.Set("Accept", accept)
	}

	response, err := http.DefaultClient.Do(request)

	if err != nil {
		t.Fatalf("do request: %v", err)
	}

	defer response.Body.Close()

	body, _ := io.ReadAll(response.Body)

	return response.StatusCode, response.Header.Get("Content-Type"), string(body)
}
