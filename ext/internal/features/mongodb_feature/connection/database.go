package connection

import "go.mongodb.org/mongo-driver/mongo"

type Database struct {
	client   *Client
	database *mongo.Database
}

func NewDatabase(client *Client, database *mongo.Database) *Database {
	return &Database{
		client:   client,
		database: database,
	}
}

func (d *Database) Collection(name string) *Collection {
	return NewCollection(
		d,
		d.database.Collection(name),
	)
}
