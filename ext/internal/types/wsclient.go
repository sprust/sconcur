package types

// WsClientCommand selects a sub-operation of the ws-client feature, carried in the
// payload envelope's cm field under the single MethodWsClient — mirrors SocketClient.
type WsClientCommand int

const (
	// WsClientConnect dials the remote URL and opens a streaming connection (the first
	// result is the connection metadata, subsequent results are inbound messages).
	WsClientConnect WsClientCommand = 1
	// WsClientSend pushes one message (text or binary) to the peer.
	WsClientSend WsClientCommand = 2
	// WsClientClose closes the connection.
	WsClientClose WsClientCommand = 3
)
