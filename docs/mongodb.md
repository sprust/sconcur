English | [Русский](mongodb.ru.md)

# MongoDB

Asynchronous MongoDB work on top of the official Go driver. Each operation goes
to the Go extension and runs in a goroutine while the coroutine is suspended.
Inside a `WaitGroup` operations run in parallel, and the total time is bounded by
the slowest one, not by their sum. Outside a `WaitGroup` the same API works
synchronously.

Documents are exchanged with the Go side as raw BSON and decoded natively by the
`ext-mongodb` extension — the same code the official driver uses. Values
therefore arrive as native `MongoDB\BSON\*` types (`ObjectId`, `UTCDateTime`,
`Decimal128`, …), and documents and arrays as plain PHP arrays.

> The `ext-mongodb` extension is required (used only for BSON encoding on the PHP
> side; networking is done by Go).

## Contents

- [Quick start](#quick-start)
- [Connection](#connection)
- [Documents and BSON types](#documents-and-bson-types)
- [Collection operations](#collection-operations)
- [Results](#results)
- [Cursors and streaming](#cursors-and-streaming)
- [Database](#database)
- [Concurrency](#concurrency)
- [Timeouts](#timeouts)
- [Internals](#internals)
- [Limits](#limits)

## Quick start

```php
use SConcur\Features\Mongodb\Connection\Client;

$collection = new Client('mongodb://localhost:27017')
    ->selectDatabase('app')
    ->selectCollection('users');

$result = $collection->insertOne(['name' => 'Ann', 'age' => 30]);
echo $result->insertedId; // MongoDB\BSON\ObjectId

$user = $collection->findOne(['name' => 'Ann']);

foreach ($collection->find(['age' => ['$gt' => 18]]) as $document) {
    echo $document['name'] . PHP_EOL;
}
```

Inside `WaitGroup::add(...)` the same calls run concurrently (see
[Concurrency](#concurrency)).

## Connection

`Client` → `Database` → `Collection`:

```php
$client     = new Client(uri: 'mongodb://user:pass@localhost:27017');
$database   = $client->selectDatabase('app');
$collection = $database->selectCollection('users');
```

`Client` constructor:

| Parameter                  | Default | Purpose |
|----------------------------|---------|---------|
| `uri`                      | —       | MongoDB connection string |
| `timeoutMs`                | 30000   | operation deadline (CSOT) |
| `serverSelectionTimeoutMs` | 10000   | how long to wait for an available server |

Clients are reused on the Go side by the key `uri + timeoutMs + serverSelectionTimeoutMs`
— creating a `Client` per request is cheap (see [Internals](#internals)).

## Documents and BSON types

A document is a PHP array; nested documents/arrays are arrays too. Scalar BSON
values use the official driver's types:

```php
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

$collection->insertOne([
    '_id'       => new ObjectId(),
    'name'      => 'Ann',
    'createdAt' => new UTCDateTime(),       // now
    'tags'      => ['a', 'b'],
]);

$document = $collection->findOne(['name' => 'Ann']);
$document['_id'];       // MongoDB\BSON\ObjectId
$document['createdAt']; // MongoDB\BSON\UTCDateTime
$document['tags'];      // ['a', 'b']
```

## Collection operations

### Insert

```php
$collection->insertOne(['name' => 'Ann']);                 // InsertOneResult
$collection->insertMany([['name' => 'Ann'], ['name' => 'Bob']]); // InsertManyResult
```

### Read

```php
// one document (or null)
$collection->findOne(
    filter: ['name' => 'Ann'],
    projection: ['name' => 1, '_id' => 0],   // opt.
    hint: ['name' => 1],                     // opt.
    collation: ['locale' => 'en'],           // opt.
);

// cursor (Iterator), in batches
$collection->find(
    filter: ['age' => ['$gt' => 18]],
    projection: null,
    sort: ['age' => 1],
    limit: 0,
    skip: 0,
    batchSize: 50,
    hint: null,
    collation: null,
);

// aggregation — cursor (Iterator)
$collection->aggregate(
    pipeline: [
        ['$match' => ['age' => ['$gt' => 18]]],
        ['$group' => ['_id' => '$city', 'count' => ['$sum' => 1]]],
    ],
    batchSize: 50,
);

$collection->distinct('city', filter: ['age' => ['$gt' => 18]]); // array of values
```

### Count

```php
$collection->countDocuments(['age' => ['$gt' => 18]]); // int (exact)
$collection->estimatedDocumentCount();                 // int (from metadata, fast)
```

### Modify

```php
$collection->updateOne(
    filter: ['name' => 'Ann'],
    update: ['$set' => ['age' => 31]],
    upsert: false,
    arrayFilters: null,
    hint: null,
    collation: null,
); // UpdateResult

$collection->updateMany(filter: ['active' => true], update: ['$inc' => ['score' => 1]]);

$collection->replaceOne(filter: ['name' => 'Ann'], replacement: ['name' => 'Ann', 'age' => 31], upsert: false);

$collection->deleteOne(['name' => 'Ann']);  // DeleteResult
$collection->deleteMany(['active' => false]);
```

### Find-and-modify (return a document or `null`)

```php
$collection->findOneAndUpdate(
    filter: ['name' => 'Ann'],
    update: ['$inc' => ['age' => 1]],
    projection: null,
    upsert: false,
    returnDocument: true,   // true — return the new version, false — the previous one
    arrayFilters: null,
    hint: null,
    collation: null,
);

$collection->findOneAndReplace(
    filter: ['name' => 'Ann'],
    replacement: ['name' => 'Ann', 'age' => 31],
    returnDocument: true,
);

$collection->findOneAndDelete(filter: ['name' => 'Ann']);
```

### Indexes

```php
$collection->createIndex(['name' => 1], name: null);     // index name (string)
$collection->createIndexes([
    ['keys' => ['name' => 1]],
    ['keys' => ['city' => 1, 'age' => -1], 'name' => 'city_age'],
]);                                                       // array of names
$collection->listIndexes();                              // array of index documents
$collection->dropIndex(['name' => 1]);                   // by keys or by name string
$collection->makeIndexNameByKeys(['name' => 1]);         // compute the index name locally
```

### Whole collection

```php
$collection->drop();
$collection->rename(target: 'users_archive', dropTarget: false);
```

### bulkWrite

A list of operations — each a map `['<type>' => [arguments...]]`:

```php
$collection->bulkWrite([
    ['insertOne'  => [['name' => 'Ann']]],
    ['updateOne'  => [['name' => 'Ann'], ['$set' => ['age' => 31]], ['upsert' => true]]],
    ['updateMany' => [['active' => true], ['$inc' => ['score' => 1]]]],
    ['replaceOne' => [['name' => 'Bob'], ['name' => 'Bob', 'age' => 40], ['upsert' => false]]],
    ['deleteOne'  => [['name' => 'Cleo']]],
    ['deleteMany' => [['active' => false]]],
]); // BulkWriteResult
```

The third element of `updateOne`/`updateMany`/`replaceOne` is options
(`['upsert' => bool]`). An unknown operation type throws
`InvalidMongodbBulkWriteOperationException`.

## Results

| Method | Result | Fields |
|--------|--------|--------|
| `insertOne` | `InsertOneResult` | `insertedId` (`ObjectId\|string\|int\|float\|null`) |
| `insertMany` | `InsertManyResult` | `insertedIds` (array), `insertedCount` (int) |
| `updateOne`/`updateMany`/`replaceOne` | `UpdateResult` | `matchedCount`, `modifiedCount`, `upsertedCount`, `upsertedId` |
| `deleteOne`/`deleteMany` | `DeleteResult` | `deletedCount` |
| `bulkWrite` | `BulkWriteResult` | `insertedCount`, `matchedCount`, `modifiedCount`, `deletedCount`, `upsertedCount`, `upsertedIds` |
| `findOne*` | `array\|null` | document |
| `find`/`aggregate` | `Iterator` | cursor over documents |
| `countDocuments`/`estimatedDocumentCount` | `int` | |
| `distinct` | `array` | values |

## Cursors and streaming

`find()` and `aggregate()` return an `Iterator` that lazily pulls the next
batches from Go (by `batchSize`) — a large result set is not buffered whole
either in the extension or in PHP:

```php
foreach ($collection->find(['active' => true], batchSize: 100) as $document) {
    // processed in batches of 100
    if ($enough) {
        break; // early exit — the cursor is closed correctly
    }
}
```

An early `break`, an exception, or a `WaitGroup` stop closes the cursor on the Go
side (`cursor.Close` → `killCursors`). Each cursor in concurrent flows is
independent.

## Database

```php
$database = $client->selectDatabase('app');

$database->listCollections();              // array of collection names
$database->command(['ping' => 1]);         // an arbitrary command → result document
$database->selectCollection('users');
```

## Concurrency

Inside a `WaitGroup`, operations from different coroutines run in parallel:

```php
use SConcur\WaitGroup;

$waitGroup = WaitGroup::create();

$waitGroup->add(fn () => $collection->insertOne(['name' => 'Ann']));
$waitGroup->add(fn () => $collection->countDocuments(['active' => true]));
$waitGroup->add(function () use ($collection) {
    $items = [];

    foreach ($collection->aggregate([['$match' => ['active' => true]]]) as $document) {
        $items[] = $document;
    }

    return $items;
});

$waitGroup->waitAll();
```

The gain grows the more expensive and latent the operations are (heavy
aggregations, a remote cluster): the total time approaches the slowest operation
rather than their sum.

## Timeouts

- `timeoutMs` — the operation deadline; Go applies it as the driver's CSOT
  (`SetTimeout`). Exceeding it → an error like `mongodb: … deadline exceeded`.
- `serverSelectionTimeoutMs` — how long to wait for an available server
  (`SetServerSelectionTimeout`), so that an unreachable MongoDB does not hang the
  task on the driver default (30s). An unreachable server fails fast.

Both parameters are set on the `Client` and are part of the pool key.

## Internals

- Official Go driver. All operations are run by `go.mongodb.org/mongo-driver/v2`
  in a goroutine; the blocking driver is used as-is, concurrency comes from the
  runtime.
- Client pool (`ext/internal/features/mongodb/connection`) — a `*mongo.Client`
  per key `uri + timeoutMs + serverSelectionTimeoutMs`, with refcounting and
  eviction of idle clients (TTL 5 minutes, checked once a minute). In-flight
  operations do not disconnect the client: the owner keeps it alive while an
  operation or a cursor is active.
- Cursors (`states/find_state`, `states/aggregation_state`) — Go holds the cursor
  as state and hands out batches on a `next` request; it is closed on exhaustion,
  early exit, or a flow stop. Closing runs on a fresh context, because the task
  context may already be cancelled by then.
- Document exchange is raw BSON, decoded natively by `ext-mongodb`
  (`MongoDB\BSON\Document`).

## Limits

- The `ext-mongodb` extension is required (for BSON types and encoding).
- A `find`/`aggregate` cursor should be either read to the end or interrupted
  (`break`) — it holds a resource on the server until closed.
- The library's general limits apply: CLI/NTS only, no `pcntl_fork` after the
  extension is loaded, do not terminate the process while tasks/cursors are
  active (see [README](../README.md)).
