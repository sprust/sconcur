package connection

import (
	"go.mongodb.org/mongo-driver/mongo"
)

type Client struct {
	mClient *mongo.Client
}

func NewClient(mClient *mongo.Client) *Client {
	return &Client{mClient: mClient}
}

func (c *Client) Database(name string) *Database {
	return NewDatabase(
		c,
		c.mClient.Database(name),
	)
}
