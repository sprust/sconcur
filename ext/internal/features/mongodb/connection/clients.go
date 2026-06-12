package connection

import (
	"context"
	"fmt"
	"sync"
	"time"

	"go.mongodb.org/mongo-driver/mongo"
	"go.mongodb.org/mongo-driver/mongo/options"
)

var once sync.Once
var instance Clients

const disconnectTimeout = 5 * time.Second

// TODO: ttl

type Clients struct {
	mutex   sync.Mutex
	clients map[string]*Client
}

func GetClients() *Clients {
	once.Do(func() {
		instance = Clients{
			clients: make(map[string]*Client),
		}
	})

	return &instance
}

func (c *Clients) GetClient(
	url string,
	socketTimeoutMs int,
) (*Client, error) {
	c.mutex.Lock()
	defer c.mutex.Unlock()

	key := fmt.Sprintf("%s[sto:%d]", url, socketTimeoutMs)

	client, exists := c.clients[key]

	if !exists {
		clientOptions := options.Client().ApplyURI(url).
			SetSocketTimeout(time.Duration(socketTimeoutMs) * time.Millisecond)

		var err error

		// The client outlives any task, so it must not depend on a task context.
		mClient, err := mongo.Connect(context.Background(), clientOptions)

		if err != nil {
			return nil, err
		}

		client = NewClient(mClient)

		c.clients[key] = client
	}

	return client, nil
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
