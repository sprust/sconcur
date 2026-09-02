package types

// WsClientCommand selects a sub-operation of the ws-client feature, carried in the
// payload envelope's cm field under the single MethodWsClient — mirrors SocketClient.
type WsClientCommand string

const (
	// WsClientConnect dials the remote URL and opens a streaming connection (the first
	// result is the connection metadata, subsequent results are inbound messages).
	WsClientConnect WsClientCommand = "con"
	// WsClientSend pushes one message (text or binary) to the peer.
	WsClientSend WsClientCommand = "snd"
	// WsClientClose closes the connection.
	WsClientClose WsClientCommand = "cls"
)
