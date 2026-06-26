package ws

import "github.com/coder/websocket"

// The one-byte prefix the inbound payload carries to PHP so the Connection can recover
// the WebSocket message type (text/binary) via lastMessageWasBinary().
const (
	MessageTypeText   byte = 0
	MessageTypeBinary byte = 1
)

// InboundMessage is one WebSocket data message read from a connection: its type
// (text/binary) and payload bytes.
type InboundMessage struct {
	Binary bool
	Data   []byte
}

// EncodeInbound prefixes one type byte (0 text, 1 binary) to the payload so PHP can
// recover the message type via Connection::lastMessageWasBinary().
func EncodeInbound(message InboundMessage) string {
	prefix := MessageTypeText

	if message.Binary {
		prefix = MessageTypeBinary
	}

	return string(append([]byte{prefix}, message.Data...))
}

// MessageTypeFromCode maps a PHP message-type code (1 binary, anything else text) to a
// WebSocket message type.
func MessageTypeFromCode(code int) websocket.MessageType {
	if byte(code) == MessageTypeBinary {
		return websocket.MessageBinary
	}

	return websocket.MessageText
}
