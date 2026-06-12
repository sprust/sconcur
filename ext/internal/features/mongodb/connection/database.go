package connection

import (
	"context"
	"sconcur/internal/dto"
	"sconcur/internal/features/mongodb/objects"
	"sconcur/internal/features/mongodb/serializer"

	"go.mongodb.org/mongo-driver/bson"
	"go.mongodb.org/mongo-driver/mongo"
)

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

func (d *Database) ListCollections(
	ctx context.Context,
	message *dto.Message,
	_ *objects.Payload,
) *dto.Result {
	return documentResult(message, "listCollections", func() (interface{}, error) {
		names, err := d.mDatabase.ListCollectionNames(ctx, bson.D{})

		if err != nil {
			return nil, err
		}

		return bson.D{{Key: "names", Value: names}}, nil
	})
}

func (d *Database) ListDatabases(
	ctx context.Context,
	message *dto.Message,
	_ *objects.Payload,
) *dto.Result {
	return documentResult(message, "listDatabases", func() (interface{}, error) {
		names, err := d.client.mClient.ListDatabaseNames(ctx, bson.D{})

		if err != nil {
			return nil, err
		}

		return bson.D{{Key: "names", Value: names}}, nil
	})
}

func (d *Database) RunCommand(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	command, err := serializer.UnmarshalDocument(payload.Data)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse command", err),
		)
	}

	return documentResult(message, "runCommand", func() (interface{}, error) {
		return d.mDatabase.RunCommand(ctx, command).Raw()
	})
}

func (d *Database) RenameCollection(
	ctx context.Context,
	message *dto.Message,
	payload *objects.Payload,
) *dto.Result {
	var params objects.RenameCollectionParams

	err := objects.UnmarshalParams(payload.Data, &params)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("parse renameCollection params", err),
		)
	}

	dbName := d.mDatabase.Name()
	source := dbName + "." + payload.Collection
	target := dbName + "." + params.Target

	cmd := bson.D{
		{Key: "renameCollection", Value: source},
		{Key: "to", Value: target},
		{Key: "dropTarget", Value: params.DropTarget},
	}

	return stringResult(message, "renameCollection", func() (string, error) {
		return "", d.client.mClient.Database("admin").RunCommand(ctx, cmd).Err()
	})
}
