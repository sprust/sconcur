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
> [Object conversion](#object-conversion) at the end of this document.

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
- Client pool (`ext/internal/features/mongodb/connection`) — a `*mongo.Client` per
  key `uri + timeoutMs + serverSelectionTimeoutMs`, with refcounting and eviction
  of idle clients (TTL 5 minutes, checked once a minute). In-flight operations do
  not disconnect the client.
- Cursors (`states/find_state`, `states/aggregation_state`) — Go holds the cursor
  as state and hands out batches on a `next` request; it is closed on exhaustion,
  early exit, or a flow stop. Closing runs on a fresh context, because the task
  context may already be cancelled by then.
- Documents are converted between BSON and MessagePack exactly once per message,
  at the outer boundary (`payloads.UnmarshalParams` on the way in, the result
  marshalling on the way out). Everything inside — filters, updates, pipeline
  stages, the bulkWrite walk, the option parsers — reads plain BSON, so a new
  command needs no conversion code of its own. See
  [Object conversion](#object-conversion).

## Limits

- A `find`/`aggregate` cursor should be read to the end or interrupted (`break`) —
  it holds a resource on the server until closed.
- Of the BSON types the specification deprecates, `symbol` is read as a string and
  `undefined` as `null`; `dbPointer` is not supported and a document holding one
  fails to decode. They only occur in data written by very old drivers.
- Forcing `msgpack.php_only` is process-wide — see
  [The extension flag](#the-extension-flag).
- The library's general limits apply — see the [README](../README.md).

## Object conversion

This section is the reference for extending the type list. It describes how a BSON
value that MessagePack cannot express becomes a PHP object and back.

### The envelope

MessagePack has no id, date or decimal. Those ride in the encoding `ext-msgpack`
already uses for PHP objects: an ordinary map whose **first key is `nil`** and
whose value is the class name, followed by property/value pairs. The C unpacker
recognises that shape while parsing and constructs the object at that point, so
the PHP side never walks the decoded structure: a second pass over every document
in userland would cost more than the decoding itself.

```
83                                    fixmap(3) — the document
  a3 '_id'                            field name
  82                                    fixmap(2) — the object starts here
    c0                                    nil key — the marker
    b5 'SConcur\Bson\ObjectId'            the class name
    a3 'oid'                              property name
    b8 '65f1c2a3b4d5e6f708192a3b'         property value
  a5 'title'  ...
```

The `nil` key is unambiguous: BSON field names are always strings, and a PHP array
cannot hold a `null` key — it coerces to `''`.

### The extension flag

The encoding only exists when `msgpack.php_only` is on. With it off, packing drops
the class name and unpacking warns about an illegal key type and yields a plain
array — quietly, in the sense that documents keep flowing, just without their
types.

The setting is `PHP_INI_ALL`, so SConcur does not merely require it: it **forces
it at extension initialisation**, in `Extension::checkExtension()`, which runs once
when the singleton is built. A build that refuses the change fails there with
`MsgpackObjectSupportDisabledException` instead of mangling documents later. The
same check verifies that `ext-msgpack` is loaded at all.

Because the forcing happens once at init, code that flips the setting afterwards
is on its own; nothing re-asserts it per operation.

The setting is process-wide, so it also applies to `msgpack_pack()` calls of your
own. If the application packs MessagePack for a consumer in another language, pack
arrays rather than objects — with the flag on, an object goes out in the PHP
envelope, which only PHP reads back.

### The extension version

The envelope is an implementation detail of `ext-msgpack`, not a documented
interchange format. Nothing obliges the extension to keep it across releases, and
a change would not announce itself: documents would keep flowing, just as plain
arrays without their types.

`composer.json` therefore pins the exact version the project is tested against —
`"ext-msgpack": "3.0.1"`, not a range. Treat that pin as part of the feature, not
as caution about dependencies in general: raising it means re-running the tests
that hold the format, above all `TestObjectEnvelopeLayout` and
`TestResolvesRepeatedObjectInstances` in `msgpack_test.go`, which assert the byte
layout and the reference numbering against what PHP actually emits. If those pass
on a new version, the pin can move; if they fail, the encoder and decoder need
updating first.

### Repeated instances

`ext-msgpack` does not write the same object twice. The second appearance of one
instance becomes a reference — `{nil: 4, 0: <index>}` — where the index counts
every container written so far: maps, arrays, objects and references alike,
numbered from 1. Reusing a single `ObjectId` variable across a document is
ordinary code, so the Go decoder keeps the same counter and resolves references
against it (`converter` in `msgpack_values.go`).

Every container counts, including one that sits inside an object's own property —
the scope of a `Javascript`, say. Skipping those would not fail: the reference
would land on a neighbouring object and the document would carry the wrong value
silently. That is why a property is read by `decodeValue`, which walks containers
itself, rather than handed to the MessagePack decoder wholesale;
`TestResolvesReferencesAfterAContainerInsideAProperty` pins it on bytes PHP really
emits.

### Where the conversion lives

| Direction | Entry point |
|---|---|
| PHP → Go, `dt` is a parameter struct | `payloads.UnmarshalParams` |
| PHP → Go, `dt` is the document itself | `serializer.PayloadDocument` / `PayloadDocuments` |
| Go → PHP, one document | `serializer.MarshalDocument` |
| Go → PHP, a cursor batch | `serializer.MarshalDocumentBatchRaw` |

Each converts once, at the outer edge of the message. Everything inside is BSON
from there on, so the bulkWrite walk, the option parsers and the per-command code
all read it directly.

### Adding a BSON type

Four places, in this order:

1. **The PHP class**, in `src/Bson/`. Mirror the `MongoDB\BSON\*` counterpart:
   same constructor, same getters, same `__toString()` and `jsonSerialize()`.
   Implement the `SConcur\Bson\Type` marker. Two constraints come from the wire
   format, not from taste:
   - properties must be **`public`** — MessagePack mangles a protected property's
     name the way `serialize()` does (`"\0*\0oid"`), and the Go side writes plain
     names. Declare the class `readonly` to keep the object immutable anyway.
   - the constructor is **not called** when the extension materialises the object
     while decoding, so validation there guards user code only.
2. **The Go encoder**, `encodeBSONValue` in `msgpack.go`: a `case` for the
   `bson.Type`, writing `encodeObjectHeader(encoder, class, propertyCount)`
   followed by the property name/value pairs. Add the class-name constant next to
   the others at the top of the file.
3. **The Go decoder**, `appendObject` in `msgpack_values.go`: a `case` for the
   class name that reads the properties and appends the BSON element through
   `bsoncore`. Read them with the `property*` helpers — they fail on a property
   that is missing or of the wrong type, and on one too wide for the BSON field,
   instead of substituting a zero that would reach the collection as a real value.
4. **The tests**. `TestRoundTripsEveryBSONType` in `msgpack_test.go` gains the
   value — it compares the document byte for byte after a full round trip, so a
   mismatch in either direction fails there. `BsonDriverParityTest` gains the pair,
   which checks the string form, the JSON form and the whole getter set against
   the driver.

Then rebuild the extension (`make ext-build`) and run `make check`.

A type that does not round-trip is caught immediately; a type whose PHP class
drifts from the driver's is caught by the parity test rather than by an
application discovering it later.
