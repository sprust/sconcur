package connection

import (
	"time"

	"go.mongodb.org/mongo-driver/v2/mongo"
)

type Client struct {
	mClient *mongo.Client

	// Reference bookkeeping, guarded by the owning Clients.mutex. inUse counts active
	// owners (a running handler, a live cursor); a client with no owners and idle longer
	// than the TTL is evicted. lastUsedAt is refreshed on every acquire/retain/release.
	clients    *Clients
	inUse      int
	lastUsedAt time.Time
}

func NewClient(clients *Clients, mClient *mongo.Client) *Client {
	return &Client{
		clients: clients,
		mClient: mClient,
	}
}

func (c *Client) Database(name string) *Database {
	return NewDatabase(
		c,
		c.mClient.Database(name),
	)
}

// Retain marks the client as held by an extra owner that outlives the current handler
// (e.g. a streaming cursor). Must be paired with Release.
func (c *Client) Retain() {
	c.clients.retain(c)
}

// Release drops one owner. With no owners left the client becomes eligible for idle
// eviction. Safe to pass as a callback (e.g. a state's Close).
func (c *Client) Release() {
	c.clients.release(c)
}
