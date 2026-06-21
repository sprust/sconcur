package types

// SocketClientCommand selects a sub-operation of the socket-client feature, carried
// in the payload envelope (the `cm` field) under MethodSocketClient — mirrors how
// the HTTP client uses HttpClientCommand under MethodHttpClient.
type SocketClientCommand int

const (
	// SocketClientConnect dials the remote address and opens a streaming connection
	// state: the first result is the connection metadata, subsequent results are
	// inbound frames.
	SocketClientConnect SocketClientCommand = 1
	// SocketClientSend pushes one length-prefixed frame to the peer.
	SocketClientSend SocketClientCommand = 2
	// SocketClientClose closes the connection.
	SocketClientClose SocketClientCommand = 3
)
