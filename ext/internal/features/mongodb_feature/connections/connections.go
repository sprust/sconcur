package connections

import (
	"context"
	"errors"
	"log/slog"
	"sync"
	"time"

	"go.mongodb.org/mongo-driver/mongo"
	"go.mongodb.org/mongo-driver/mongo/options"
)

var once sync.Once
var instance Connections

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
) (*mongo.Collection, error) {
	c.mutex.Lock()
	defer c.mutex.Unlock()

	client, exists := c.clients[url]

	if !exists {
		clientOptions := options.Client().ApplyURI(url)

		var err error

		client, err = mongo.Connect(ctx, clientOptions)

		if err != nil {
			return nil, err
		}

		c.clients[url] = client
	}

	return client.Database(database).Collection(collection), nil
}

func (c *Connections) Close() error {
	c.mutex.Lock()
	defer c.mutex.Unlock()

	slog.Warn("Closing mongodb connections")

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	var errList []error

	for _, client := range c.clients {
		if err := client.Disconnect(ctx); err != nil {
			errList = append(errList, err)
		}
	}

	if len(errList) > 0 {
		return errors.Join(errList...)
	}

	return nil
}
