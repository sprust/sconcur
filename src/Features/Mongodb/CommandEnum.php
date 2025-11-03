<?php

namespace SConcur\Features\Mongodb;

enum CommandEnum: int
{
    case InsertOne = 1;
    case BulkWrite = 2;
}
