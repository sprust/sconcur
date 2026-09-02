// Package payloads holds the Go counterparts of the PHP ws-client payload objects
// (SConcur\Features\WsClient\Payloads\*) and the connection metadata the client streams
// back to PHP. Struct tags are the short keys exchanged via MessagePack.
//
// Every message is a command envelope (cm/p) under MethodWsClient — mirrors SocketClient:
// cm selects the sub-operation, p carries that command's parameters (decoded into
// ConnectParams / SendParams / CloseParams).
package payloads

import (
	"sconcur/internal/types"

	"github.com/vmihailenco/msgpack/v5"
)

// Envelope is the command envelope decoded from the msgpack message.
// PHP: SConcur\Features\WsClient\Payloads\Base\BaseWsClientPayload.
type Envelope struct {
	Command types.WsClientCommand `json:"cm" msgpack:"cm"`
	Params  msgpack.RawMessage    `json:"p" msgpack:"p"`
}

// ConnectParams is the `p` content of a Connect command — the remote ws:// URL and the
// per-connection tuning (timeouts in milliseconds, sizes in bytes). Defaults are
// supplied by the PHP side (WsClientOptions).
// PHP: SConcur\Features\WsClient\Payloads\ConnectPayloadParameters.
type ConnectParams struct {
	Address string `json:"ad" msgpack:"ad"`
	// ConnectTimeoutMs bounds establishing the connection (the dial + handshake). 0
	// falls back to the default.
	ConnectTimeoutMs int `json:"ct" msgpack:"ct"`
	// ReadTimeoutMs is the idle timeout waiting for the next inbound message. 0 disables
	// it (a connection may stay idle forever).
	ReadTimeoutMs int `json:"rt" msgpack:"rt"`
	// WriteTimeoutMs bounds writing one outbound message.
	WriteTimeoutMs int `json:"wt" msgpack:"wt"`
	// MaxMessageBytes caps the size of a single inbound message; an oversize message
	// closes the connection with 1009 (message too big). 0 disables the limit.
	MaxMessageBytes int `json:"mmb" msgpack:"mmb"`
	// Subprotocols lists the WebSocket subprotocols offered in the handshake.
	Subprotocols []string `json:"sp" msgpack:"sp"`
}

// SendParams is the `p` content of a Send command: the connection to write to, the
// WebSocket message type (0 text, 1 binary), and the message bytes (binary-safe).
// PHP: SConcur\Features\WsClient\Payloads\SendPayload.
type SendParams struct {
	ConnectionId string `json:"cid" msgpack:"cid"`
	MessageType  int    `json:"mt" msgpack:"mt"`
	Data         string `json:"dt" msgpack:"dt"`
}

// CloseParams is the `p` content of a Close command: the connection to close.
// PHP: SConcur\Features\WsClient\Payloads\ClosePayload.
type CloseParams struct {
	ConnectionId string `json:"cid" msgpack:"cid"`
}

// ConnectionMeta is the first result the client emits for a Connect: the connection id
// (used to route Send/Close) plus the resolved addresses and the negotiated
// subprotocol. Subsequent results (pulled via next) are inbound messages, not this
// struct.
// PHP: decoded in SConcur\Features\WsClient\WsClient::connect.
type ConnectionMeta struct {
	ConnectionId string `json:"cid" msgpack:"cid"`
	RemoteAddr   string `json:"ra" msgpack:"ra"`
	LocalAddr    string `json:"la" msgpack:"la"`
	Subprotocol  string `json:"su" msgpack:"su"`
}
