package sql_feature

import (
	"context"
	"database/sql"
	"fmt"
	"sync"
	"time"
)

const (
	poolCloseTimeout  = 5 * time.Second
	poolIdleTTL       = 5 * time.Minute
	poolSweepInterval = time.Minute
)

var poolsOnce sync.Once
var poolsInstance *pools

// pool wraps one *sql.DB (itself a connection pool) with an owner refcount so an
// idle, unreferenced pool can be swept while in-flight work keeps it alive. Mirrors
// the MongoDB connection.Client pooling.
type pool struct {
	db         *sql.DB
	inUse      int
	lastUsedAt time.Time
}

type pools struct {
	mutex sync.Mutex
	pools map[string]*pool
}

func getPools() *pools {
	poolsOnce.Do(func() {
		poolsInstance = &pools{
			pools: make(map[string]*pool),
		}

		poolsInstance.startSweeper()
	})

	return poolsInstance
}

// acquire returns the *sql.DB for driver+dsn+sizing, opening it on first use, and
// marks it held. The caller must release it when done. sql.Open does not connect —
// the first query connects under its own context, so the connect deadline applies.
func (p *pools) acquire(
	driverName string,
	dsn string,
	maxOpenConns int,
	maxIdleConns int,
	connMaxLifetimeMs int,
) (*pool, error) {
	p.mutex.Lock()
	defer p.mutex.Unlock()

	key := fmt.Sprintf(
		"%s|%s|mo:%d,mi:%d,cl:%d",
		driverName,
		dsn,
		maxOpenConns,
		maxIdleConns,
		connMaxLifetimeMs,
	)

	acquired, exists := p.pools[key]

	if !exists {
		db, err := sql.Open(driverName, dsn)

		if err != nil {
			return nil, err
		}

		if maxOpenConns > 0 {
			db.SetMaxOpenConns(maxOpenConns)
		}

		if maxIdleConns > 0 {
			db.SetMaxIdleConns(maxIdleConns)
		}

		if connMaxLifetimeMs > 0 {
			db.SetConnMaxLifetime(time.Duration(connMaxLifetimeMs) * time.Millisecond)
		}

		acquired = &pool{
			db: db,
		}

		p.pools[key] = acquired
	}

	acquired.inUse++
	acquired.lastUsedAt = time.Now()

	return acquired, nil
}

func (p *pools) release(target *pool) {
	p.mutex.Lock()
	defer p.mutex.Unlock()

	if target.inUse > 0 {
		target.inUse--
	}

	target.lastUsedAt = time.Now()
}

func (p *pools) startSweeper() {
	go func() {
		ticker := time.NewTicker(poolSweepInterval)
		defer ticker.Stop()

		for range ticker.C {
			p.sweep()
		}
	}()
}

func (p *pools) sweep() {
	expired := p.collectExpired(time.Now())

	for _, target := range expired {
		closeDb(target.db)
	}
}

// collectExpired removes and returns idle, unreferenced pools (inUse == 0, untouched
// longer than the TTL). Closing is left to the caller, outside the lock.
func (p *pools) collectExpired(now time.Time) []*pool {
	p.mutex.Lock()
	defer p.mutex.Unlock()

	var expired []*pool

	for key, target := range p.pools {
		if target.inUse == 0 && now.Sub(target.lastUsedAt) > poolIdleTTL {
			expired = append(expired, target)

			delete(p.pools, key)
		}
	}

	return expired
}

func (p *pools) closeAll() {
	p.mutex.Lock()

	current := p.pools
	p.pools = make(map[string]*pool)

	p.mutex.Unlock()

	for _, target := range current {
		closeDb(target.db)
	}
}

// closeDb closes a *sql.DB, bounded by a fresh timeout: the task context that
// triggered shutdown may already be cancelled.
func closeDb(db *sql.DB) {
	done := make(chan struct{})

	go func() {
		_ = db.Close()

		close(done)
	}()

	ctx, cancel := context.WithTimeout(context.Background(), poolCloseTimeout)
	defer cancel()

	select {
	case <-done:
	case <-ctx.Done():
	}
}
