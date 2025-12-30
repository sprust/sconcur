package connection

import "go.mongodb.org/mongo-driver/mongo"

type Database struct {
	client    *Client
	mDatabase *mongo.Database
}

func NewDatabase(client *Client, mDatabase *mongo.Database) *Database {
	return &Database{
		client:    client,
		mDatabase: mDatabase,
	}
}

func (d *Database) Collection(name string) *Collection {
	return NewCollection(
		d,
		d.mDatabase.Collection(name),
	)
}
