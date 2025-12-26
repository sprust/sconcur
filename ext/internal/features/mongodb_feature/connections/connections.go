package connections

import (
	"context"
	"fmt"
	"sync"
	"time"

	"go.mongodb.org/mongo-driver/mongo"
	"go.mongodb.org/mongo-driver/mongo/options"
)

var once sync.Once
var instance Connections

// TODO: graceful shutdown

type Connections struct {
	mutex   sync.Mutex
	clients map[string]*mongo.Client
}

func GetConnections() *Connections {
	once.Do(func() {
		instance = Connections{
			clients: make(map[string]*mongo.Client),
		}
	})

	return &instance
}

func (c *Connections) Get(
	ctx context.Context,
	url string,
	database string,
	collection string,
	socketTimeoutMs int,
) (*mongo.Collection, error) {
	c.mutex.Lock()
	defer c.mutex.Unlock()

	key := fmt.Sprintf("%s[sto:%d]", url, socketTimeoutMs)

	client, exists := c.clients[key]

	if !exists {
		clientOptions := options.Client().ApplyURI(url).
			SetSocketTimeout(time.Duration(socketTimeoutMs) * time.Millisecond)

		var err error

		client, err = mongo.Connect(ctx, clientOptions)

		if err != nil {
			return nil, err
		}

		c.clients[key] = client
	}

	return client.Database(database).Collection(collection), nil
}
