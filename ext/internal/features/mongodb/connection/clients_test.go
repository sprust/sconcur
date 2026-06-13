package connection

import (
	"testing"
	"time"
)

func TestCollectExpiredEvictsOnlyIdleUnreferenced(t *testing.T) {
	clients := &Clients{
		clients: make(map[string]*Client),
	}

	now := time.Now()

	// Idle, no owners, past the TTL → evicted.
	clients.clients["idle"] = &Client{inUse: 0, lastUsedAt: now.Add(-2 * clientIdleTTL)}
	// Old but still held by an owner → kept (never disconnect an in-use client).
	clients.clients["busy"] = &Client{inUse: 1, lastUsedAt: now.Add(-2 * clientIdleTTL)}
	// Recently used → kept.
	clients.clients["fresh"] = &Client{inUse: 0, lastUsedAt: now}

	expired := clients.collectExpired(now)

	if len(expired) != 1 {
		t.Fatalf("expected 1 expired client, got %d", len(expired))
	}

	if _, ok := clients.clients["idle"]; ok {
		t.Fatal("idle client must be removed from the map")
	}

	if _, ok := clients.clients["busy"]; !ok {
		t.Fatal("in-use client must be kept")
	}

	if _, ok := clients.clients["fresh"]; !ok {
		t.Fatal("recently used client must be kept")
	}
}
