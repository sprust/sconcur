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

// TODO: graceful shutdown

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
	ctx context.Context,
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

		mdbClient, err := mongo.Connect(ctx, clientOptions)

		if err != nil {
			return nil, err
		}

		client = NewClient(mdbClient)

		c.clients[key] = client
	}

	return client, nil
}
