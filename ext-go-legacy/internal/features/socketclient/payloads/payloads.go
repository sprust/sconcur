// Package payloads holds the Go counterparts of the PHP socket-client payload
// objects (SConcur\Features\SocketClient\Payloads\*) and the connection metadata the
// client streams back to PHP. Struct tags are the short keys exchanged via
// MessagePack.
//
// Every message is a command envelope (cm/p) under MethodSocketClient — mirrors the
// HTTP-client feature: cm selects the sub-operation, p carries that command's
// parameters (decoded into ConnectParams / SendParams / CloseParams).
package payloads

import (
	"sconcur/internal/types"

	"github.com/vmihailenco/msgpack/v5"
)

// Envelope is the command envelope decoded from the msgpack message.
// PHP: SConcur\Features\SocketClient\Payloads\Base\BaseSocketClientPayload (cm from
// the command enum, p from the parameters' getData()).
type Envelope struct {
	Command types.SocketClientCommand `json:"cm" msgpack:"cm"`
	Params  msgpack.RawMessage        `json:"p" msgpack:"p"`
}

// ConnectParams is the `p` content of a Connect command — the remote address and the
// per-connection tuning (timeouts in milliseconds, sizes in bytes). Defaults are
// supplied by the PHP side (SocketClientOptions). There is no single "operation
// time": ConnectTimeoutMs bounds the dial, ReadTimeoutMs the idle wait for an
// inbound frame, WriteTimeoutMs one frame write — the mandatory execution bounds for
// a long-lived connection.
// PHP: SConcur\Features\SocketClient\Payloads\ConnectPayloadParameters.
type ConnectParams struct {
	Address string `json:"ad" msgpack:"ad"`
	// ConnectTimeoutMs bounds establishing the TCP connection (net.Dialer). 0 falls
	// back to the default.
	ConnectTimeoutMs int `json:"ct" msgpack:"ct"`
	// ReadTimeoutMs is the idle timeout waiting for the next inbound frame in read().
	// 0 disables it (a connection may stay idle forever).
	ReadTimeoutMs int `json:"rt" msgpack:"rt"`
	// WriteTimeoutMs bounds writing one outbound frame.
	WriteTimeoutMs int `json:"wt" msgpack:"wt"`
	// MaxMessageBytes caps the length of a single inbound frame (guards against a huge
	// length prefix).
	MaxMessageBytes int `json:"mmb" msgpack:"mmb"`
}

// SendParams is the `p` content of a Send command: the connection to write to and
// the frame bytes (binary-safe).
// PHP: SConcur\Features\SocketClient\Payloads\SendPayload.
type SendParams struct {
	ConnectionId string `json:"cid" msgpack:"cid"`
	Data         string `json:"dt" msgpack:"dt"`
}

// CloseParams is the `p` content of a Close command: the connection to close.
// PHP: SConcur\Features\SocketClient\Payloads\ClosePayload.
type CloseParams struct {
	ConnectionId string `json:"cid" msgpack:"cid"`
}

// ConnectionMeta is the first result the client emits for a Connect: the connection
// id (used to route Send/Close) plus the resolved addresses. Subsequent results
// (pulled via next) are raw inbound frames, not this struct.
// PHP: decoded in SConcur\Features\SocketClient\SocketClient::connect.
type ConnectionMeta struct {
	ConnectionId string `json:"cid" msgpack:"cid"`
	RemoteAddr   string `json:"ra" msgpack:"ra"`
	LocalAddr    string `json:"la" msgpack:"la"`
}
