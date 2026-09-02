package types

// SocketClientCommand selects a sub-operation of the socket-client feature, carried
// in the payload envelope (the `cm` field) under MethodSocketClient — mirrors how
// the HTTP client uses HttpClientCommand under MethodHttpClient.
type SocketClientCommand string

const (
	// SocketClientConnect dials the remote address and opens a streaming connection
	// state: the first result is the connection metadata, subsequent results are
	// inbound frames.
	SocketClientConnect SocketClientCommand = "con"
	// SocketClientSend pushes one length-prefixed frame to the peer.
	SocketClientSend SocketClientCommand = "snd"
	// SocketClientClose closes the connection.
	SocketClientClose SocketClientCommand = "cls"
)
