package httpserver_feature

import (
	"context"
	"net"
	"syscall"
)

// soReusePort is SO_REUSEPORT on Linux. The stdlib syscall package does not
// export it, and the extension targets Linux only, where the value is stable
// across the supported architectures (amd64, arm64, 386, arm).
const soReusePort = 0x0F

// listen opens a TCP listener for the address. With reusePort it sets
// SO_REUSEPORT on the socket so several independent processes can bind the very
// same address at once; the kernel then load-balances incoming connections
// across them (process-per-core scaling). Without it, a second binder on the
// same port fails with EADDRINUSE, as usual.
func listen(address string, reusePort bool) (net.Listener, error) {
	if !reusePort {
		return net.Listen("tcp", address)
	}

	config := net.ListenConfig{
		Control: func(_, _ string, connection syscall.RawConn) error {
			var controlErr error

			err := connection.Control(func(fd uintptr) {
				controlErr = syscall.SetsockoptInt(int(fd), syscall.SOL_SOCKET, soReusePort, 1)
			})

			if err != nil {
				return err
			}

			return controlErr
		},
	}

	return config.Listen(context.Background(), "tcp", address)
}
