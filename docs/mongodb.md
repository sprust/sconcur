English | [Русский](mongodb.ru.md)

# MongoDB

Asynchronous MongoDB on top of the official Go driver. Each operation goes into the
Go extension and runs in a goroutine while the coroutine is suspended; inside a
`WaitGroup` operations run in parallel and the total time is bounded by the slowest
one. Outside a `WaitGroup` the same API works synchronously.

Documents are exchanged with the Go side as raw BSON and decoded natively by
`ext-mongodb` — the same code the official driver uses. Values therefore arrive as
native `MongoDB\BSON\*` types (`ObjectId`, `UTCDateTime`, `Decimal128`, …), and
documents and arrays as plain PHP arrays.

> `ext-mongodb` is required — for BSON encoding/decoding on the PHP side only; the
> networking is done by Go.

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

## Connection

`Client` → `Database` → `Collection`:

```php
$client     = new Client(uri: 'mongodb://user:pass@localhost:27017');
$database   = $client->selectDatabase('app');
$collection = $database->selectCollection('users');
```

| `Client` parameter | Default | Purpose |
|---|---|---|
| `uri` | — | MongoDB connection string |
| `timeoutMs` | 30000 | operation deadline; Go applies it as the driver's CSOT (`SetTimeout`), exceeding it gives an error like `mongodb: … deadline exceeded` |
| `serverSelectionTimeoutMs` | 10000 | how long to wait for an available server (`SetServerSelectionTimeout`), so an unreachable MongoDB fails fast instead of hanging on the driver default of 30 s |

Clients are reused on the Go side by the key
`uri + timeoutMs + serverSelectionTimeoutMs`, so creating a `Client` per request is
cheap.

## Documents and BSON types

A document is a PHP array; nested documents and arrays are arrays too. Scalar BSON
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

```php
// insert
$collection->insertOne(['name' => 'Ann']);                        // InsertOneResult
$collection->insertMany([['name' => 'Ann'], ['name' => 'Bob']]);  // InsertManyResult

// read
$collection->findOne(
    filter: ['name' => 'Ann'],
    projection: ['name' => 1, '_id' => 0],   // opt.
    hint: ['name' => 1],                     // opt.
    collation: ['locale' => 'en'],           // opt.
);

$collection->find(                            // cursor (Iterator), in batches
    filter: ['age' => ['$gt' => 18]],
    projection: null,
    sort: ['age' => 1],
    limit: 0,
    skip: 0,
    batchSize: 50,
    hint: null,
    collation: null,
);

$collection->aggregate(                       // cursor (Iterator)
    pipeline: [
        ['$match' => ['age' => ['$gt' => 18]]],
        ['$group' => ['_id' => '$city', 'count' => ['$sum' => 1]]],
    ],
    batchSize: 50,
);

$collection->distinct('city', filter: ['age' => ['$gt' => 18]]);  // array of values

// count
$collection->countDocuments(['age' => ['$gt' => 18]]);  // int (exact)
$collection->estimatedDocumentCount();                  // int (from metadata, fast)

// modify
$collection->updateOne(
    filter: ['name' => 'Ann'],
    update: ['$set' => ['age' => 31]],
    upsert: false,
    arrayFilters: null,
    hint: null,
    collation: null,
);                                                       // UpdateResult

$collection->updateMany(filter: ['active' => true], update: ['$inc' => ['score' => 1]]);
$collection->replaceOne(filter: ['name' => 'Ann'], replacement: ['name' => 'Ann', 'age' => 31], upsert: false);
$collection->deleteOne(['name' => 'Ann']);               // DeleteResult
$collection->deleteMany(['active' => false]);

// find-and-modify (returns a document or null)
$collection->findOneAndUpdate(
    filter: ['name' => 'Ann'],
    update: ['$inc' => ['age' => 1]],
    projection: null,
    upsert: false,
    returnDocument: true,   // true — the new version, false — the previous one
    arrayFilters: null,
    hint: null,
    collation: null,
);

$collection->findOneAndReplace(filter: ['name' => 'Ann'], replacement: ['name' => 'Ann', 'age' => 31], returnDocument: true);
$collection->findOneAndDelete(filter: ['name' => 'Ann']);

// indexes
$collection->createIndex(['name' => 1], name: null);     // index name (string)
$collection->createIndexes([
    ['keys' => ['name' => 1]],
    ['keys' => ['city' => 1, 'age' => -1], 'name' => 'city_age'],
]);                                                       // array of names
$collection->listIndexes();                               // array of index documents
$collection->dropIndex(['name' => 1]);                    // by keys or by name string
$collection->makeIndexNameByKeys(['name' => 1]);          // compute the name locally

// whole collection
$collection->drop();
$collection->rename(target: 'users_archive', dropTarget: false);
```

`bulkWrite` takes a list of operations, each a map `['<type>' => [arguments...]]`:

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
(`['upsert' => bool]`); an unknown operation type throws
`InvalidMongodbBulkWriteOperationException`.

`Database` gives `listCollections()` (collection names), `command(['ping' => 1])`
(an arbitrary command → result document) and `selectCollection()`.

## Results

| Method | Result | Fields |
|--------|--------|--------|
| `insertOne` | `InsertOneResult` | `insertedId` (`ObjectId\|string\|int\|float\|null`) |
| `insertMany` | `InsertManyResult` | `insertedIds`, `insertedCount` |
| `updateOne`/`updateMany`/`replaceOne` | `UpdateResult` | `matchedCount`, `modifiedCount`, `upsertedCount`, `upsertedId` |
| `deleteOne`/`deleteMany` | `DeleteResult` | `deletedCount` |
| `bulkWrite` | `BulkWriteResult` | `insertedCount`, `matchedCount`, `modifiedCount`, `deletedCount`, `upsertedCount`, `upsertedIds` |
| `findOne*` | `array\|null` | document |
| `find`/`aggregate` | `Iterator` | cursor over documents |
| `countDocuments`/`estimatedDocumentCount` | `int` | |
| `distinct` | `array` | values |

## Cursors and streaming

`find()` and `aggregate()` return an `Iterator` that lazily pulls the next batches
from Go (by `batchSize`), so a large result set is not buffered whole either in the
extension or in PHP:

```php
foreach ($collection->find(['active' => true], batchSize: 100) as $document) {
    if ($enough) {
        break; // early exit — the cursor is closed correctly
    }
}
```

An early `break`, an exception, or a `WaitGroup` stop closes the cursor on the Go
side (`cursor.Close` → `killCursors`). Each cursor in concurrent flows is
independent.

## Concurrency

Inside a `WaitGroup` operations from different coroutines run in parallel:

```php
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

The gain grows with the price of the operations — the total time approaches the
slowest one rather than their sum. What that means in numbers (and where the native
driver still wins) is in the [benchmarks](benchmarks.md#mongodb).

## Internals

- All operations are run by `go.mongodb.org/mongo-driver/v2` in a goroutine: the
  blocking driver is used as-is, concurrency comes from the runtime.
- Client pool (`ext/internal/features/mongodb/connection`) — a `*mongo.Client` per
  key `uri + timeoutMs + serverSelectionTimeoutMs`, with refcounting and eviction
  of idle clients (TTL 5 minutes, checked once a minute). In-flight operations do
  not disconnect the client.
- Cursors (`states/find_state`, `states/aggregation_state`) — Go holds the cursor
  as state and hands out batches on a `next` request; it is closed on exhaustion,
  early exit, or a flow stop. Closing runs on a fresh context, because the task
  context may already be cancelled by then.

## Limits

- `ext-mongodb` is required (BSON types and encoding/decoding).
- A `find`/`aggregate` cursor should be read to the end or interrupted (`break`) —
  it holds a resource on the server until closed.
- The library's general limits apply — see the [README](../README.md).
