package stats

import (
	"context"
	"net"
	"syscall"
)

// soReusePort is SO_REUSEPORT on Linux. The stdlib syscall package does not export
// it, and the extension targets Linux only, where the value is stable across the
// supported architectures.
const soReusePort = 0x0F

// listen opens a TCP listener with SO_REUSEPORT set, so every worker of a pool can
// bind the same stats port at once and the kernel routes a stats request to one of
// them (which then answers for the whole pool from the shared snapshot files).
func listen(address string) (net.Listener, error) {
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
