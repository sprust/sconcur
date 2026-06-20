// Package payloads holds the Go counterparts of the PHP socket-server payload
// objects (SConcur\Features\SocketServer\Payloads\*) and the connection event the
// server streams back to PHP. Struct tags are the short keys exchanged via
// MessagePack.
package payloads

// ServePayload is the payload of a socketServe command — the listener address and
// the server tuning parameters (all timeouts in milliseconds, sizes in bytes).
// Defaults are supplied by the PHP side.
// PHP: SConcur\Features\SocketServer\Payloads\ServePayload.
type ServePayload struct {
	Address string `json:"ad" msgpack:"ad"`
	// ReadTimeoutMs is the idle timeout between messages (no frame within → close).
	// 0 disables it (a connection may stay idle forever).
	ReadTimeoutMs int `json:"rt" msgpack:"rt"`
	// WriteTimeoutMs bounds writing one response frame.
	WriteTimeoutMs int `json:"wt" msgpack:"wt"`
	// MaxMessageBytes caps the length of a single inbound frame (guards against a
	// huge length prefix).
	MaxMessageBytes int `json:"mmb" msgpack:"mmb"`
	// MaxConcurrency caps the number of connections handled at once (0 = unlimited).
	MaxConcurrency int `json:"mc" msgpack:"mc"`
	// ShutdownTimeoutMs bounds the graceful drain of in-flight connections on Close.
	ShutdownTimeoutMs int `json:"sht" msgpack:"sht"`
	// ReusePort sets SO_REUSEPORT so several processes can bind the same address and
	// the kernel load-balances connections across them (process-per-core).
	ReusePort bool `json:"rp" msgpack:"rp"`
}

// RespondPayload is the payload of a socketRespond command — one action a PHP
// connection handler performs on its connection. Op selects the kind (0 write a
// length-prefixed frame to the client, 1 close the connection).
// PHP: SConcur\Features\SocketServer\Payloads\RespondPayload.
type RespondPayload struct {
	ConnectionId string `json:"cid" msgpack:"cid"`
	Op           int    `json:"op" msgpack:"op"`
	Data         string `json:"dt" msgpack:"dt"`
}

// ConnectionEvent is what the server emits to PHP for each accepted connection (it
// is MessagePack-marshaled into the streaming result's payload). PHP decodes it
// inline in SocketServer::handleConnection.
type ConnectionEvent struct {
	ConnectionId string `json:"cid" msgpack:"cid"`
	RemoteAddr   string `json:"ra" msgpack:"ra"`
	LocalAddr    string `json:"la" msgpack:"la"`
}
