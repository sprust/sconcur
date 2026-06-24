package stats

import (
	"context"
	"encoding/json"
	"net/http"
	"sconcur/internal/logger"
	"strconv"
	"time"
)

// shutdownTimeout bounds the dedicated stats server's graceful shutdown. It is a
// low-volume admin endpoint, so a short fixed timeout is enough.
const shutdownTimeout = 5 * time.Second

// Server is the dedicated HTTP stats server: one per worker, bound with
// SO_REUSEPORT on the configured port, answering only GET StatsPath. The catching
// worker aggregates the whole pool from the shared snapshot files. It is started
// only when both a port and a token are configured.
type Server struct {
	statsDir   string
	serverName string
	token      string
	httpServer *http.Server
}

// MaybeStartServer starts the dedicated stats server when both a port and a token
// are configured, binding ":<port>" (all interfaces — firewall it as needed). A
// bind failure is logged and nil is returned: the stats endpoint is optional and
// must never take down the main server. Returns nil when stats is not configured.
func MaybeStartServer(port int, token string, statsDir string, serverName string) *Server {
	if port <= 0 || token == "" {
		return nil
	}

	address := ":" + strconv.Itoa(port)

	server, err := NewServer(address, token, statsDir, serverName)

	if err != nil {
		logger.Write("statsServer: bind " + address + " failed: " + err.Error() + "\n")

		return nil
	}

	return server
}

// NewServer binds the stats listener and starts serving in the background. A bind
// failure is returned to the caller (MaybeStartServer logs it and continues).
func NewServer(address string, token string, statsDir string, serverName string) (*Server, error) {
	listener, err := listen(address)

	if err != nil {
		return nil, err
	}

	server := &Server{
		statsDir:   statsDir,
		serverName: serverName,
		token:      token,
	}

	server.httpServer = &http.Server{Handler: server}

	go func() {
		_ = server.httpServer.Serve(listener)
	}()

	return server, nil
}

// ServeHTTP answers the aggregated statistics for a valid GET StatsPath request.
// Any other path, or an unauthorized request, gets a 404 — not a 401 — so the
// endpoint's existence is not revealed to a caller without the token.
func (server *Server) ServeHTTP(writer http.ResponseWriter, request *http.Request) {
	if request.URL.Path != StatsPath {
		http.NotFound(writer, request)

		return
	}

	if !AuthorizeBearer(request.Header.Get("Authorization"), server.token) {
		http.NotFound(writer, request)

		return
	}

	if request.Method != http.MethodGet {
		http.Error(writer, "method not allowed", http.StatusMethodNotAllowed)

		return
	}

	response := Aggregate(server.statsDir, server.serverName, time.Now())

	body, err := json.Marshal(response)

	if err != nil {
		http.Error(writer, "stats error", http.StatusInternalServerError)

		return
	}

	writer.Header().Set("Content-Type", "application/json")
	writer.WriteHeader(http.StatusOK)

	_, _ = writer.Write(body)
}

// Close gracefully shuts the stats server down. Safe to call on a nil receiver, so
// callers need not branch on whether the server was started.
func (server *Server) Close() {
	if server == nil {
		return
	}

	ctx, cancel := context.WithTimeout(context.Background(), shutdownTimeout)
	defer cancel()

	_ = server.httpServer.Shutdown(ctx)
}
