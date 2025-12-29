package connection

import (
	"go.mongodb.org/mongo-driver/mongo"
)

type Client struct {
	client *mongo.Client
}

func NewClient(client *mongo.Client) *Client {
	return &Client{client: client}
}

func (c *Client) Database(name string) *Database {
	return NewDatabase(
		c,
		c.client.Database(name),
	)
}
