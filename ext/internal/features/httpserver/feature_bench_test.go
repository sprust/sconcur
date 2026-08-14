package httpserver_feature

import (
	"testing"
)

// The fire-and-forget handover is on the per-request hot path: one per answered
// request. It must not allocate — the done channel it used to create was pure
// waste, since nothing ever reads it. Run with -benchmem: allocs/op is the
// number this benchmark exists for.
func BenchmarkDispatchFireAndForget(b *testing.B) {
	feature := Get()

	pending := &pendingRequest{
		commands:  make(chan writeCommand),
		abandoned: make(chan struct{}),
	}

	// A receiver always parked in the select, like ServeHTTP's consumeCommands
	// waiting for the handler's answer.
	stop := make(chan struct{})
	defer close(stop)

	go func() {
		for {
			select {
			case <-stop:
				return
			case command := <-pending.commands:
				command.report(nil)
			}
		}
	}()

	command := writeCommand{kind: writeFull, status: 200, body: "ok"}

	b.ReportAllocs()
	b.ResetTimer()

	for range b.N {
		feature.dispatchFireAndForget(pending, command)
	}
}

// The awaited handover, for comparison: it must allocate its done channel,
// because the handler coroutine really does wait on the write outcome. Keeps the
// benchmark above honest — a zero here would mean the harness is measuring
// nothing.
func BenchmarkDispatchAwaited(b *testing.B) {
	pending := &pendingRequest{
		commands:  make(chan writeCommand),
		abandoned: make(chan struct{}),
	}

	stop := make(chan struct{})
	defer close(stop)

	go func() {
		for {
			select {
			case <-stop:
				return
			case command := <-pending.commands:
				command.report(nil)
			}
		}
	}()

	b.ReportAllocs()
	b.ResetTimer()

	for range b.N {
		command := writeCommand{kind: writeFull, status: 200, body: "ok", done: make(chan error, 1)}

		pending.commands <- command

		<-command.done
	}
}
