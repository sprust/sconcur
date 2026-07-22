<?php

namespace SConcur\Features\Mongodb;

enum CommandEnum: string
{
    case InsertOne              = 'ino';
    case BulkWrite              = 'bw';
    case Aggregate              = 'agg';
    case InsertMany             = 'inm';
    case CountDocuments         = 'cnt';
    case UpdateOne              = 'upo';
    case FindOne                = 'fno';
    case CreateIndex            = 'cix';
    case DeleteOne              = 'dlo';
    case DeleteMany             = 'dlm';
    case UpdateMany             = 'upm';
    case Drop                   = 'drp';
    case DropIndex              = 'dix';
    case Find                   = 'fnd';
    case Distinct               = 'dst';
    case FindOneAndUpdate       = 'fou';
    case FindOneAndDelete       = 'fod';
    case FindOneAndReplace      = 'for';
    case ReplaceOne             = 'rpo';
    case EstimatedDocumentCount = 'edc';
    case CreateIndexes          = 'cxs';
    case ListIndexes            = 'lix';
    case ListCollections        = 'lcl';
    case ListDatabases          = 'ldb';
    case RenameCollection       = 'rnc';
    case RunCommand             = 'run';
}
