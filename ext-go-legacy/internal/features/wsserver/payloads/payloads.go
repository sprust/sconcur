// Package payloads holds the Go counterparts of the PHP ws-server payload objects
// (SConcur\Features\WsServer\Payloads\*) and the connection event the server streams
// back to PHP. Struct tags are the short keys exchanged via MessagePack.
package payloads

// ServePayload is the payload of a wsServe command — the listener address and the
// server tuning parameters (all timeouts in milliseconds, sizes in bytes). The
// listener is a net/http.Server; each request with a valid WebSocket upgrade becomes
// a streamed connection, every other request is answered 426 Upgrade Required.
// Defaults are supplied by the PHP side.
// PHP: SConcur\Features\WsServer\Payloads\ServePayload.
type ServePayload struct {
	Address string `json:"ad" msgpack:"ad"`
	// HandshakeTimeoutMs bounds reading the upgrade request headers.
	HandshakeTimeoutMs int `json:"hst" msgpack:"hst"`
	// IdleTimeoutMs is the idle timeout between inbound messages (no message within →
	// the connection's input ends). 0 disables it (a connection may stay idle forever,
	// kept alive by the server ping).
	IdleTimeoutMs int `json:"it" msgpack:"it"`
	// WriteTimeoutMs bounds writing one message (and one keepalive ping) to the client.
	WriteTimeoutMs int `json:"wt" msgpack:"wt"`
	// PingIntervalMs is the server keepalive ping cadence (0 = disabled).
	PingIntervalMs int `json:"pi" msgpack:"pi"`
	// MaxMessageBytes caps the size of a single inbound message; an oversize message
	// closes the connection with 1009 (message too big). 0 disables the limit.
	MaxMessageBytes int `json:"mmb" msgpack:"mmb"`
	// MaxConcurrency caps the number of connections handled at once (0 = unlimited).
	MaxConcurrency int `json:"mc" msgpack:"mc"`
	// ShutdownTimeoutMs bounds the graceful drain of in-flight connections on Close.
	ShutdownTimeoutMs int `json:"sht" msgpack:"sht"`
	// ReusePort sets SO_REUSEPORT so several processes can bind the same address and
	// the kernel load-balances connections across them (process-per-core).
	ReusePort bool `json:"rp" msgpack:"rp"`
	// Path restricts the upgrade endpoint to this request path (empty = any path).
	Path string `json:"pt" msgpack:"pt"`
	// AllowedOrigins lists the host patterns accepted by the origin check (empty =
	// allow any origin, the check is skipped).
	AllowedOrigins []string `json:"ao" msgpack:"ao"`
	// Subprotocols lists the WebSocket subprotocols the server negotiates with the
	// client (empty = none).
	Subprotocols []string `json:"sp" msgpack:"sp"`
	// TelemetrySocket is the collector's unix socket the worker pushes snapshots to
	// (empty = push off). Under the master it is injected from runtimeDir/name.
	TelemetrySocket string `json:"ts" msgpack:"ts"`
	// ServerName labels the snapshot (the pool scope the collector aggregates by).
	ServerName string `json:"sn" msgpack:"sn"`
	// TelemetryIntervalMs is the snapshot-sample/push cadence (0 = default).
	TelemetryIntervalMs int `json:"ti" msgpack:"ti"`
}

// RespondPayload is the payload of a wsRespond command — one action a PHP connection
// handler performs on its connection. Op selects the kind (0 write a message to the
// client, 1 close the connection); MessageType selects the WebSocket message type of
// a written message (0 text, 1 binary).
// PHP: SConcur\Features\WsServer\Payloads\RespondPayload.
type RespondPayload struct {
	ConnectionId string `json:"cid" msgpack:"cid"`
	Op           int    `json:"op" msgpack:"op"`
	MessageType  int    `json:"mt" msgpack:"mt"`
	Data         string `json:"dt" msgpack:"dt"`
}

// ConnectionEvent is what the server emits to PHP for each upgraded connection (it is
// MessagePack-marshaled into the streaming result's payload). PHP decodes it inline in
// WsServer::handleConnection.
type ConnectionEvent struct {
	ConnectionId string `json:"cid" msgpack:"cid"`
	RemoteAddr   string `json:"ra" msgpack:"ra"`
	LocalAddr    string `json:"la" msgpack:"la"`
	Path         string `json:"pa" msgpack:"pa"`
	Subprotocol  string `json:"su" msgpack:"su"`
}
