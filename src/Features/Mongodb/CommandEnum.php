<?php

namespace SConcur\Features\Mongodb;

enum CommandEnum: int
{
    case InsertOne              = 1;
    case BulkWrite              = 2;
    case Aggregate              = 3;
    case InsertMany             = 4;
    case CountDocuments         = 5;
    case UpdateOne              = 6;
    case FindOne                = 7;
    case CreateIndex            = 8;
    case DeleteOne              = 9;
    case DeleteMany             = 10;
    case UpdateMany             = 11;
    case Drop                   = 12;
    case DropIndex              = 13;
    case Find                   = 14;
    case Distinct               = 15;
    case FindOneAndUpdate       = 16;
    case FindOneAndDelete       = 17;
    case FindOneAndReplace      = 18;
    case ReplaceOne             = 19;
    case EstimatedDocumentCount = 20;
    case CreateIndexes          = 21;
    case ListIndexes            = 22;
    case ListCollections        = 23;
    case ListDatabases          = 24;
    case RenameCollection       = 25;
    case RunCommand             = 26;
}
