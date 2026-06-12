package connection

import (
	"context"
	"sconcur/internal/dto"
	"sconcur/internal/features/mongodb/objects"
	"sconcur/internal/features/mongodb/serializer"
	"sconcur/internal/helpers"
	"time"

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
	start := time.Now()
	names, err := d.mDatabase.ListCollectionNames(ctx, bson.D{})
	executionMs := helpers.CalcExecutionMs(start)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("listCollections error", err),
		)
	}

	serializedResult, err := serializer.MarshalDocument(bson.D{
		{Key: "names", Value: names},
	})

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal listCollections result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult, executionMs)
}

func (d *Database) ListDatabases(
	ctx context.Context,
	message *dto.Message,
	_ *objects.Payload,
) *dto.Result {
	start := time.Now()
	names, err := d.client.mClient.ListDatabaseNames(ctx, bson.D{})
	executionMs := helpers.CalcExecutionMs(start)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("listDatabases error", err),
		)
	}

	serializedResult, err := serializer.MarshalDocument(bson.D{
		{Key: "names", Value: names},
	})

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal listDatabases result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult, executionMs)
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

	start := time.Now()
	raw, err := d.mDatabase.RunCommand(ctx, command).Raw()
	executionMs := helpers.CalcExecutionMs(start)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("runCommand error", err),
		)
	}

	serializedResult, err := serializer.MarshalDocument(raw)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("marshal runCommand result error", err),
		)
	}

	return dto.NewSuccessResult(message, serializedResult, executionMs)
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

	start := time.Now()
	err = d.client.mClient.Database("admin").RunCommand(ctx, cmd).Err()
	executionMs := helpers.CalcExecutionMs(start)

	if err != nil {
		return dto.NewErrorResult(
			message,
			errFactory.ByErr("renameCollection error", err),
		)
	}

	return dto.NewSuccessResult(message, "", executionMs)
}
