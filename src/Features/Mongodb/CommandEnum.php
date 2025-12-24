<?php

namespace SConcur\Features\Mongodb;

enum CommandEnum: int
{
    case InsertOne      = 1;
    case BulkWrite      = 2;
    case Aggregate      = 3;
    case InsertMany     = 4;
    case CountDocuments = 5;
    case UpdateOne      = 6;
    case FindOne        = 7;
    case CreateIndexes  = 8;
}
