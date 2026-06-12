package connection

import (
	"context"
	"fmt"
	"sync"
	"time"

	"go.mongodb.org/mongo-driver/v2/mongo"
	"go.mongodb.org/mongo-driver/v2/mongo/options"
)

var once sync.Once
var instance Clients

const (
	disconnectTimeout = 5 * time.Second

	// clientIdleTTL: a client with no active owners (no running handler, no live cursor)
	// is disconnected after staying idle this long. Owners keep it alive regardless, so
	// in-flight operations are never disconnected — this only bounds growth from
	// short-lived/dynamic URIs (multitenancy).
	clientIdleTTL       = 5 * time.Minute
	clientSweepInterval = time.Minute
)

type Clients struct {
	mutex   sync.Mutex
	clients map[string]*Client
}

func GetClients() *Clients {
	once.Do(func() {
		instance = Clients{
			clients: make(map[string]*Client),
		}

		instance.startSweeper()
	})

	return &instance
}

// Acquire returns the client for url+socketTimeout, creating it on first use, and marks it
// as held (inUse). The caller must Release it when done.
func (c *Clients) Acquire(
	url string,
	socketTimeoutMs int,
) (*Client, error) {
	c.mutex.Lock()
	defer c.mutex.Unlock()

	key := fmt.Sprintf("%s[sto:%d]", url, socketTimeoutMs)

	client, exists := c.clients[key]

	if !exists {
		clientOptions := options.Client().ApplyURI(url).
			SetTimeout(time.Duration(socketTimeoutMs) * time.Millisecond)

		// The client outlives any task, so it must not depend on a task context.
		mClient, err := mongo.Connect(clientOptions)

		if err != nil {
			return nil, err
		}

		client = NewClient(c, mClient)

		c.clients[key] = client
	}

	client.inUse++
	client.lastUsedAt = time.Now()

	return client, nil
}

func (c *Clients) retain(client *Client) {
	c.mutex.Lock()
	defer c.mutex.Unlock()

	client.inUse++
	client.lastUsedAt = time.Now()
}

func (c *Clients) release(client *Client) {
	c.mutex.Lock()
	defer c.mutex.Unlock()

	if client.inUse > 0 {
		client.inUse--
	}

	client.lastUsedAt = time.Now()
}

func (c *Clients) startSweeper() {
	go func() {
		ticker := time.NewTicker(clientSweepInterval)
		defer ticker.Stop()

		for range ticker.C {
			c.sweep()
		}
	}()
}

func (c *Clients) sweep() {
	expired := c.collectExpired(time.Now())

	for _, client := range expired {
		ctx, ctxCancel := context.WithTimeout(context.Background(), disconnectTimeout)

		_ = client.mClient.Disconnect(ctx)

		ctxCancel()
	}
}

// collectExpired removes and returns idle, unreferenced clients (inUse == 0 and untouched
// for longer than the TTL). Disconnecting is left to the caller, outside the lock.
func (c *Clients) collectExpired(now time.Time) []*Client {
	c.mutex.Lock()
	defer c.mutex.Unlock()

	var expired []*Client

	for key, client := range c.clients {
		if client.inUse == 0 && now.Sub(client.lastUsedAt) > clientIdleTTL {
			expired = append(expired, client)

			delete(c.clients, key)
		}
	}

	return expired
}

func (c *Clients) DisconnectAll() {
	c.mutex.Lock()

	clients := c.clients
	c.clients = make(map[string]*Client)

	c.mutex.Unlock()

	for _, client := range clients {
		ctx, ctxCancel := context.WithTimeout(context.Background(), disconnectTimeout)

		_ = client.mClient.Disconnect(ctx)

		ctxCancel()
	}
}
