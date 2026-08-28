package payloads

import (
	"testing"

	"github.com/vmihailenco/msgpack/v5"
)

// The handle and the deadline are declared once, on ChannelCommand, and embedded into every
// command that runs on an open channel. msgpack flattens an embedded struct, so PHP goes on
// writing `chid` and `to` at the top level of the `p` map exactly as it did when each
// command declared the two fields itself.
//
// Tested because the flattening is a library default, not something the tags spell out: an
// embedded field that stopped being inlined would look like a nested map to PHP, and every
// channel command would decode with an empty handle.
func TestAChannelCommandIsFlatOnTheWire(t *testing.T) {
	encoded, err := msgpack.Marshal(map[string]any{
		"chid": "amqp:ch:7",
		"to":   250,
		"na":   "orders",
		"iu":   true,
		"nw":   false,
	})

	if err != nil {
		t.Fatalf("marshal error: %v", err)
	}

	var params ExchangeDeleteParams

	if err := msgpack.Unmarshal(encoded, &params); err != nil {
		t.Fatalf("unmarshal error: %v", err)
	}

	if params.ChannelId != "amqp:ch:7" {
		t.Fatalf("ChannelId = %q, want %q", params.ChannelId, "amqp:ch:7")
	}

	if params.TimeoutMs != 250 {
		t.Fatalf("TimeoutMs = %d, want 250", params.TimeoutMs)
	}

	if params.Name != "orders" || !params.IfUnused || params.NoWait {
		t.Fatalf("the command's own fields decoded wrong: %#v", params)
	}
}

// What the shared runner reads a command by. The accessors come from the embedded struct,
// so every channel command satisfies the interface without writing them itself — which is
// the whole reason the two fields moved there.
func TestEveryChannelCommandAnswersItsHandleAndDeadline(t *testing.T) {
	command := ChannelCommand{
		ChannelId: "amqp:ch:3",
		TimeoutMs: 100,
	}

	commands := []interface {
		Channel() string
		Timeout() int
	}{
		ChannelParams{ChannelCommand: command},
		QosParams{ChannelCommand: command},
		ExchangeDeclareParams{ChannelCommand: command},
		ExchangeDeleteParams{ChannelCommand: command},
		ExchangeBindParams{ChannelCommand: command},
		QueueDeclareParams{ChannelCommand: command},
		QueueDeleteParams{ChannelCommand: command},
		QueueBindParams{ChannelCommand: command},
		QueuePurgeParams{ChannelCommand: command},
		PublishParams{ChannelCommand: command},
		GetParams{ChannelCommand: command},
		ConsumeParams{ChannelCommand: command},
		CancelParams{ChannelCommand: command},
		AckParams{ChannelCommand: command},
		NackParams{ChannelCommand: command},
		RejectParams{ChannelCommand: command},
		ConfirmSelectParams{ChannelCommand: command},
	}

	for _, params := range commands {
		if params.Channel() != "amqp:ch:3" {
			t.Fatalf("%T: Channel() = %q, want %q", params, params.Channel(), "amqp:ch:3")
		}

		if params.Timeout() != 100 {
			t.Fatalf("%T: Timeout() = %d, want 100", params, params.Timeout())
		}
	}
}
