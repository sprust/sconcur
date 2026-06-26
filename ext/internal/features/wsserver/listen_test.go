package wsserver_feature

import (
	"testing"
)

// TestListenReusePortAllowsSharedBinding verifies SO_REUSEPORT lets two listeners
// bind the very same address at once — the basis for running one process per core.
func TestListenReusePortAllowsSharedBinding(t *testing.T) {
	first, err := listen("127.0.0.1:0", true)

	if err != nil {
		t.Fatalf("first reuseport listen: %v", err)
	}

	defer func() { _ = first.Close() }()

	address := first.Addr().String()

	second, err := listen(address, true)

	if err != nil {
		t.Fatalf("second reuseport listen on %s should succeed, got: %v", address, err)
	}

	_ = second.Close()
}

// TestListenWithoutReusePortRejectsSharedBinding is the contrast: without the option,
// a second binder on the same port fails as usual.
func TestListenWithoutReusePortRejectsSharedBinding(t *testing.T) {
	first, err := listen("127.0.0.1:0", false)

	if err != nil {
		t.Fatalf("first listen: %v", err)
	}

	defer func() { _ = first.Close() }()

	second, err := listen(first.Addr().String(), false)

	if err == nil {
		_ = second.Close()

		t.Fatal("expected an error binding the same port without SO_REUSEPORT")
	}
}
