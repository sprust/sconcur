English | [Русский](mongodb.ru.md)

# MongoDB

Asynchronous MongoDB on top of the official Go driver. Each operation goes into the
Go extension and runs in a goroutine while the coroutine is suspended; inside a
`WaitGroup` operations run in parallel and the total time is bounded by the slowest
one. Outside a `WaitGroup` the same API works synchronously.

Documents are exchanged with the Go side as MessagePack, which PHP already speaks
through `ext-msgpack`. BSON values that MessagePack has no equivalent for arrive as
`SConcur\Bson\*` objects (`ObjectId`, `UTCDateTime`, `Decimal128`, …); documents
and arrays arrive as plain PHP arrays.

> `SConcur\Bson\*` reproduces the `MongoDB\BSON\*` API one for one — the same
> constructors, getters, string and JSON forms — so moving an application from the
> native driver is a change of `use` lines. The only PHP extension the feature
> needs is `ext-msgpack`. How that works, and how to add a type, is in
> [Objects over MessagePack](msgpack-objects.md).

## Quick start

```php
use SConcur\Features\Mongodb\Connection\Client;

$collection = new Client('mongodb://localhost:27017')
    ->selectDatabase('app')
    ->selectCollection('users');

$result = $collection->insertOne(['name' => 'Ann', 'age' => 30]);
echo $result->insertedId; // SConcur\Bson\ObjectId

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

A document is a PHP array; nested documents and arrays are arrays too. BSON values
that have no MessagePack equivalent are objects from `SConcur\Bson\`:

```php
use SConcur\Bson\ObjectId;
use SConcur\Bson\UTCDateTime;

$collection->insertOne([
    '_id'       => new ObjectId(),
    'name'      => 'Ann',
    'createdAt' => new UTCDateTime(),       // now
    'tags'      => ['a', 'b'],
]);

$document = $collection->findOne(['name' => 'Ann']);
$document['_id'];       // SConcur\Bson\ObjectId
$document['createdAt']; // SConcur\Bson\UTCDateTime
$document['tags'];      // ['a', 'b']
```

| BSON type | PHP |
|---|---|
| double, string, bool, null, int32 | native scalars |
| document, array | `array` |
| int64 | `SConcur\Bson\Int64` |
| objectId | `SConcur\Bson\ObjectId` |
| date | `SConcur\Bson\UTCDateTime` |
| binary | `SConcur\Bson\Binary` |
| regex | `SConcur\Bson\Regex` |
| timestamp | `SConcur\Bson\Timestamp` |
| decimal128 | `SConcur\Bson\Decimal128` |
| javascript | `SConcur\Bson\Javascript` |
| minKey / maxKey | `SConcur\Bson\MinKey` / `MaxKey` |

An empty document reads back as an empty array — PHP has one type for `{}` and
`[]`, so the two are told apart only by what is inside them. Writing that array
back stores an empty array, exactly as the `ext-mongodb` path did.

A plain object is accepted where a document is expected — `(object) [...]`, or what
`json_decode()` returns without `associative: true` — and stores as a sub-document.
It reads back as an array, like any other document. Any other object in a document
is an error: the value has no BSON type to become.

Each class carries the same methods as its `MongoDB\BSON\*` counterpart —
`getData()`/`getType()` on `Binary`, `getPattern()`/`getFlags()` on `Regex`,
`toDateTime()`/`toDateTimeImmutable()` on `UTCDateTime`, `__toString()` and
`jsonSerialize()` everywhere the driver has them. `int64` is wrapped for the same
reason the driver wraps it: without the wrapper the type would not survive a read
followed by a write, because a value that fits in an int32 would come back as one.

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

$collection->distinct('city', filter: ['age' => ['$gt' => 18]], collation: null);  // array of values

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
$collection->deleteOne(['name' => 'Ann'], hint: null, collation: null);  // DeleteResult
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
$collection->findOneAndDelete(filter: ['name' => 'Ann'], projection: null);

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

`Database` gives `listCollections()` (a list of collection names),
`command(['ping' => 1])` (an arbitrary command → result document) and
`selectCollection()`. `Client` gives `listDatabases()` (a list of database names)
and `selectDatabase()`.

## Results

| Method | Result | Fields |
|--------|--------|--------|
| `insertOne` | `InsertOneResult` | `insertedId` (`ObjectId\|Int64\|string\|int\|float\|null`) |
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
- Client pool (`ext/internal/features/mongodb/connection`) — a `*mongo.Client`
  per key `uri + timeoutMs + serverSelectionTimeoutMs`, with refcounting and
  eviction of idle clients (TTL 5 minutes, checked once a minute). A client with
  operations still running is not disconnected.
- Cursors (`states/find_state`, `states/aggregation_state`) — Go holds the cursor
  as state and hands out batches on a `next` request; it is closed on exhaustion,
  early exit, or a flow stop. Closing runs on a fresh context, because the task
  context may already be cancelled by then.
- Documents are converted between BSON and MessagePack exactly once per message,
  at the outer boundary (`payloads.UnmarshalParams` on the way in, the result
  marshalling on the way out). Everything inside — filters, updates, pipeline
  stages, the bulkWrite walk, the option parsers — reads plain BSON, so a new
  command needs no conversion code of its own. See
  [Objects over MessagePack](msgpack-objects.md).

## Limits

- A `find`/`aggregate` cursor should be read to the end or interrupted (`break`) —
  it holds a resource on the server until closed.
- Of the BSON types the specification deprecates, `symbol` is read as a string and
  `undefined` as `null`; `dbPointer` is not supported and a document holding one
  fails to decode. They only occur in data written by very old drivers.
- Forcing `msgpack.php_only` is process-wide — see
  [Objects over MessagePack](msgpack-objects.md#the-extension-flag).
- The library's general limits apply — see the [README](../README.md).

## Object conversion

BSON values MessagePack cannot express — an id, a date, a decimal — cross the
boundary as PHP objects, in the encoding `ext-msgpack` uses for objects. That
mechanism is not specific to MongoDB and is documented on its own:
[Objects over MessagePack](msgpack-objects.md) covers the format, the
`msgpack.php_only` requirement, the `ext-msgpack` version pin, repeated instances,
and how to add a type and test it.

The BSON side of it is the type table in
[Documents and BSON types](#documents-and-bson-types): adding a type means a class in
`src/Bson/` mirroring its `MongoDB\BSON\*` counterpart, a `case` in the Go encoder
and one in the Go decoder, plus a value in `TestRoundTripsEveryBSONType` and a pair in
`BsonDriverParityTest`.
