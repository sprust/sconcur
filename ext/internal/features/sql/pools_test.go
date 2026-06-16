package sql_feature

import (
	"testing"
	"time"
)

// newTestPools builds an isolated registry (not the process singleton) so tests do
// not start the sweeper goroutine or interfere with each other.
func newTestPools() *pools {
	return &pools{
		pools: make(map[string]*pool),
	}
}

func TestAcquireReusesPoolAndRefcounts(t *testing.T) {
	registry := newTestPools()

	// sql.Open is lazy (no connection), so this is safe without a running MySQL.
	first, err := registry.acquire("mysql", "user:pass@tcp(127.0.0.1:3306)/db", 0, 0, 0)

	if err != nil {
		t.Fatalf("acquire error: %v", err)
	}

	second, err := registry.acquire("mysql", "user:pass@tcp(127.0.0.1:3306)/db", 0, 0, 0)

	if err != nil {
		t.Fatalf("acquire error: %v", err)
	}

	if first != second {
		t.Fatal("same key should return the same pool")
	}

	if first.inUse != 2 {
		t.Fatalf("inUse = %d, want 2", first.inUse)
	}

	registry.release(first)
	registry.release(second)

	if first.inUse != 0 {
		t.Fatalf("inUse after release = %d, want 0", first.inUse)
	}
}

func TestAcquireDistinctKeysForDifferentSizing(t *testing.T) {
	registry := newTestPools()

	dsn := "user:pass@tcp(127.0.0.1:3306)/db"

	a, _ := registry.acquire("mysql", dsn, 5, 0, 0)
	b, _ := registry.acquire("mysql", dsn, 10, 0, 0)

	if a == b {
		t.Fatal("different pool sizing must produce different pools")
	}

	if len(registry.pools) != 2 {
		t.Fatalf("pools count = %d, want 2", len(registry.pools))
	}
}

func TestCollectExpiredEvictsIdleUnreferenced(t *testing.T) {
	registry := newTestPools()

	idle, _ := registry.acquire("mysql", "user:pass@tcp(127.0.0.1:3306)/idle", 0, 0, 0)
	registry.release(idle)

	idle.lastUsedAt = time.Now().Add(-2 * poolIdleTTL)

	held, _ := registry.acquire("mysql", "user:pass@tcp(127.0.0.1:3306)/held", 0, 0, 0)
	held.lastUsedAt = time.Now().Add(-2 * poolIdleTTL)

	expired := registry.collectExpired(time.Now())

	if len(expired) != 1 {
		t.Fatalf("expired count = %d, want 1 (held pool must survive)", len(expired))
	}

	if _, stillThere := registry.pools["mysql|user:pass@tcp(127.0.0.1:3306)/idle|mo:0,mi:0,cl:0"]; stillThere {
		t.Fatal("idle pool should have been removed from the registry")
	}

	if len(registry.pools) != 1 {
		t.Fatalf("remaining pools = %d, want 1", len(registry.pools))
	}

	for _, target := range expired {
		closeDb(target.db)
	}
}
