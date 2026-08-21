package amqp_feature

import (
	"context"
	"sconcur/internal/features/amqp/payloads"
	"testing"
	"time"
)

// newTestConnections builds an isolated registry (not the process singleton) so a test
// starts no sweeper goroutine and does not disturb its neighbours.
func newTestConnections() *connections {
	return &connections{
		pool:    make(map[connectionKey]*pooledConnection),
		handles: make(map[string]*connectionHandle),
	}
}

func newTestChannels() *channels {
	return &channels{
		entries: make(map[string]*channelEntry),
	}
}

func TestConnectionKeyTellsCredentialsApart(t *testing.T) {
	base := payloads.ConnectParams{
		Host:     "broker",
		Port:     5672,
		Vhost:    "/",
		Login:    "user",
		Password: "secret",
	}

	same := connectionKeyFromParams(base)

	if connectionKeyFromParams(base) != same {
		t.Fatal("the same credentials must produce the same key")
	}

	otherVhost := base
	otherVhost.Vhost = "/other"

	if connectionKeyFromParams(otherVhost) == same {
		t.Fatal("a different vhost must produce a different key")
	}

	otherTuning := base
	otherTuning.HeartbeatSeconds = 30

	if connectionKeyFromParams(otherTuning) == same {
		t.Fatal("different tuning must produce a different key")
	}
}

func TestIdleConnectionsAreSweptOnlyWhenNothingHoldsThem(t *testing.T) {
	registry := newTestConnections()

	key := connectionKeyFromParams(payloads.ConnectParams{Host: "broker", Port: 5672})

	held := &pooledConnection{
		key:        key,
		inUse:      1,
		lastUsedAt: time.Now().Add(-2 * connectionIdleTTL),
	}

	registry.pool[key] = held

	if expired := registry.collectExpired(time.Now()); len(expired) != 0 {
		t.Fatalf("a held connection must survive the sweep, got %d expired", len(expired))
	}

	held.inUse = 0

	expired := registry.collectExpired(time.Now())

	if len(expired) != 1 || expired[0] != held {
		t.Fatalf("an idle unheld connection must be swept, got %d expired", len(expired))
	}

	if len(registry.pool) != 0 {
		t.Fatal("a swept connection must be removed from the pool")
	}
}

func TestAFreshlyUsedConnectionIsNotSwept(t *testing.T) {
	registry := newTestConnections()

	key := connectionKeyFromParams(payloads.ConnectParams{Host: "broker", Port: 5672})

	registry.pool[key] = &pooledConnection{
		key:        key,
		inUse:      0,
		lastUsedAt: time.Now(),
	}

	if expired := registry.collectExpired(time.Now()); len(expired) != 0 {
		t.Fatalf("a connection used just now must survive the sweep, got %d expired", len(expired))
	}
}

func TestIdleChannelsAreSweptOnlyWithoutConsumers(t *testing.T) {
	registry := newTestChannels()

	idle := &channelEntry{
		id:         "amqp:ch:1",
		consumers:  make(map[string]context.CancelFunc),
		lastUsedAt: time.Now().Add(-2 * channelIdleTTL),
	}

	consuming := &channelEntry{
		id: "amqp:ch:2",
		consumers: map[string]context.CancelFunc{
			"ctag-1": func() {},
		},
		lastUsedAt: time.Now().Add(-2 * channelIdleTTL),
	}

	registry.entries[idle.id] = idle
	registry.entries[consuming.id] = consuming

	expired := registry.collectExpired(time.Now())

	if len(expired) != 1 || expired[0] != idle {
		t.Fatalf("only the channel with no consumers must be swept, got %d expired", len(expired))
	}

	if _, exists := registry.entries[consuming.id]; !exists {
		t.Fatal("a channel with a consumer must stay in the registry")
	}
}

func TestFindingAChannelKeepsItFromTheSweeper(t *testing.T) {
	registry := newTestChannels()

	entry := &channelEntry{
		id:         "amqp:ch:1",
		consumers:  make(map[string]context.CancelFunc),
		lastUsedAt: time.Now().Add(-2 * channelIdleTTL),
	}

	registry.entries[entry.id] = entry

	if _, err := registry.find(entry.id); err != nil {
		t.Fatalf("find error: %v", err)
	}

	if expired := registry.collectExpired(time.Now()); len(expired) != 0 {
		t.Fatalf("a channel a command just used must survive the sweep, got %d expired", len(expired))
	}

	if _, err := registry.find("amqp:ch:missing"); err == nil {
		t.Fatal("an unknown channel id must be reported")
	}
}
