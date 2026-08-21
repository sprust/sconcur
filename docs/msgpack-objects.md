English | [Русский](msgpack-objects.ru.md)

# Objects over MessagePack

PHP and the Go extension exchange every payload and every result as MessagePack.
Scalars, arrays and maps have a MessagePack type of their own and cross unchanged.
A value that has none — an id, a date, a decimal — crosses as a PHP object, in the
encoding `ext-msgpack` already uses for objects.

This document is the reference for that mechanism: the format, the constraints it
puts on both sides, how to add a type and how to test one.

The only feature using it today is MongoDB, whose `SConcur\Bson\*` classes are the
value objects it hands the application (see [mongodb](mongodb.md)), so every
example below is one of those. The mechanism itself is feature-neutral.

## Where the conversion happens

On the PHP side:

| Direction | Entry point |
|---|---|
| PHP → Go, a payload | `Transport\MessagePackTransport::pack` — packs `PayloadInterface::getData()` |
| Go → PHP, a task result | `Transport\MessagePackTransport::unpack` |
| PHP → Go, a MongoDB document | `Features\Mongodb\Serialization\DocumentSerializer::serialize` |
| Go → PHP, a document or a cursor batch | `DocumentSerializer::unserialize` / `::unserializeBatch` |

Each is one call to `msgpack_pack()` or `msgpack_unpack()`. PHP never walks the
decoded structure: `ext-msgpack` builds the objects in C while parsing, and a
second pass over every document in userland would cost more than the decoding
itself.

On the Go side (MongoDB's, as the worked example):

| Direction | Entry point |
|---|---|
| PHP → Go, `dt` is a parameter struct | `payloads.UnmarshalParams` |
| PHP → Go, `dt` is the document itself | `serializer.PayloadDocument` / `PayloadDocuments` |
| Go → PHP, one document | `serializer.MarshalDocument` |
| Go → PHP, a cursor batch | `serializer.MarshalDocumentBatchRaw` |

Each converts once, at the outer edge of the message. Everything inside is BSON
from there on, so the bulkWrite walk, the option parsers and the per-command code
all read it directly.

## The envelope

An object is an ordinary MessagePack map whose first key is `nil` and whose value
is the class name, followed by property/value pairs. Pack one and look at the
bytes:

```shell
docker compose exec php php -d extension=./ext/build/sconcur.so \
  -r 'require "vendor/autoload.php";
      echo bin2hex(msgpack_pack(["id" => new SConcur\Bson\ObjectId("6919e3d1a3673d3f4d9137a3")])), PHP_EOL;'
```

```
81                                    fixmap(1) — the document
  a2 'id'                             field name
  82                                    fixmap(2) — the object starts here
    c0                                    nil key — the marker
    b5 'SConcur\Bson\ObjectId'            the class name
    a3 'oid'                              property name
    b8 '6919e3d1a3673d3f4d9137a3'         property value
```

The `nil` key is unambiguous: a PHP array cannot hold a `null` key — it coerces to
`''` — so no ordinary map can start with one. The C unpacker recognises the shape
while parsing and constructs the object at that point.

Go writes the same shape with `encodeObjectHeader` (`msgpack.go`), so a value
coming back from a Go driver arrives in PHP as an object with nothing for PHP to
do.

## The extension flag

The encoding only exists when `msgpack.php_only` is on. With it off, both
directions degrade quietly — nothing throws:

- packing an object drops the class and the property names and leaves a list of
  the values, so `["id" => new ObjectId("6919…")]` packs as `81 a2 6964 91 b8 …`
  and reads back as `['id' => ['6919…']]`;
- unpacking bytes Go wrote raises
  `Warning: [msgpack] (msgpack_unserialize_map_item) illegal key type` and yields a
  plain array whose `nil` key has coerced to `''`.

The setting is `PHP_INI_ALL`, so SConcur does not merely require it: it forces it
at extension initialisation, in `Extension::checkExtension()`, which runs once when
the singleton is built. A build that refuses the change fails there with
`MsgpackObjectSupportDisabledException` instead of mangling documents later. The
same check verifies that `ext-msgpack` is loaded at all.

Because the forcing happens once at init, code that flips the setting afterwards is
on its own; nothing re-asserts it per operation.

The setting is process-wide, so it also applies to `msgpack_pack()` calls of your
own. If the application packs MessagePack for a consumer in another language, pack
arrays rather than objects — with the flag on, an object goes out in the PHP
envelope, which only PHP reads back.

## The extension version

The envelope is an implementation detail of `ext-msgpack`, not a documented
interchange format. Nothing obliges the extension to keep it across releases, and a
change would not announce itself: documents would keep flowing, just as plain
arrays without their types.

`composer.json` therefore pins the exact version the project is tested against —
`"ext-msgpack": "3.0.1"`, not a range. Treat that pin as part of the mechanism, not
as caution about dependencies in general: raising it means re-running the tests that
hold the format, above all `TestObjectEnvelopeLayout` and
`TestResolvesRepeatedObjectInstances` in `msgpack_test.go`, which assert the byte
layout and the reference numbering against what PHP actually emits. If those pass on
a new version, the pin can move; if they fail, the encoder and decoder need updating
first.

## Repeated instances

`ext-msgpack` does not write the same object twice. The second appearance of one
instance becomes a reference — `{nil: 4, 0: <index>}` — where the index counts every
container written so far: maps, arrays, objects and references alike, numbered from
1. Reusing a single value object across a document is ordinary code, so the Go
decoder keeps the same counter and resolves references against it (`converter` in
`msgpack_values.go`).

```shell
docker compose exec php php -d extension=./ext/build/sconcur.so \
  -r 'require "vendor/autoload.php";
      $objectId = new SConcur\Bson\ObjectId("6919e3d1a3673d3f4d9137a3");
      echo bin2hex(msgpack_pack(["x" => $objectId, "y" => $objectId])), PHP_EOL;'
```

```
82                                    fixmap(2) — the document, container 1
  a1 'x'
  82 c0 b5 'SConcur\Bson\ObjectId' …    the object, container 2
  a1 'y'
  82                                    fixmap(2) — the reference, container 3
    c0 04                                 nil key, marker 4
    00 02                                 key 0 — names container 2
```

Every container counts, including one that sits inside an object's own property —
the scope of a `Javascript`, say. Skipping those would not fail: the reference would
land on a neighbouring object and the document would carry the wrong value silently.
That is why a property is read by `decodeValue`, which walks containers itself,
rather than handed to the MessagePack decoder wholesale;
`TestResolvesReferencesAfterAContainerInsideAProperty` pins it on bytes PHP really
emits.

## What a PHP class must look like

Two constraints come from the wire format rather than from taste:

- properties must be public — MessagePack mangles a protected property's name the
  way `serialize()` does (`"\0*\0oid"`), and the Go side writes plain names. Declare
  the class `readonly` to keep the object immutable anyway. This is the one place the
  project's "properties are `protected`" rule does not apply, and the classes in
  `src/Bson/` carry a comment saying so.
- the constructor is not called when the extension materialises the object while
  decoding, so validation there guards user code only. What arrives from the wire is
  validated on the Go side, by the `property*` helpers described below.

Beyond that: give the class a marker interface (`SConcur\Bson\Type` for the BSON
set) so a document walk can recognise it, and keep the class name short — it travels
on the wire with every value.

## The Go encoder

`encodeBSONValue` in `msgpack.go` writes a `case` per source type: an
`encodeObjectHeader(encoder, class, propertyCount)` followed by the property
name/value pairs, in the order the PHP class declares them. Class names are
constants at the top of the file.

`encodeObjectHeader` writes a map of `propertyCount + 1` pairs whose first pair is
`nil` → the class name. A property count that does not match the pairs actually
written corrupts the stream — the decoder on either side reads the next value as a
key.

## The Go decoder

`converter` in `msgpack_values.go` walks the MessagePack stream and appends BSON as
it goes, without building an intermediate map:

- `appendMapValue` / `appendMapBody` read a map, ask `startsWithNilKey` whether it is
  an envelope, and hand an envelope to `readObject`;
- `readObject` reads the class name and the properties, records the instance under
  its container index, and `readObjectReference` resolves a repeat against that
  index;
- `appendObject` turns the decoded object into a BSON element — one `case` per class
  name, an unknown class is an error;
- the `property*` helpers (`propertyString`, `propertyBytes`, `propertyInt`,
  `propertyRange`, `propertyPairs`) read one property each. They fail on a property
  that is missing, of the wrong type, or too wide for the target field, instead of
  substituting a zero that would reach the database as a real date or subtype.

`decodeValue` is the odd one out: an object's property is read whole rather than
streamed into the document, because the property can only become BSON once the class
is known. It still walks containers itself, for the counter reason above.

## Adding a type

Four places, in this order:

1. The PHP class, in `src/Bson/` for a BSON value or in the feature's own namespace
   otherwise. Public properties, `readonly` class, the marker interface — see
   [What a PHP class must look like](#what-a-php-class-must-look-like). If the class
   mirrors one from another library, mirror it exactly: same constructor, same
   getters, same `__toString()` and `jsonSerialize()`.
2. The Go encoder, `encodeBSONValue` in `msgpack.go`: a `case` for the source type
   writing `encodeObjectHeader(encoder, class, propertyCount)` and the property
   pairs. Add the class-name constant next to the others at the top of the file.
3. The Go decoder, `appendObject` in `msgpack_values.go`: a `case` for the class name
   that reads the properties with the `property*` helpers and appends the value.
4. The tests — the next section.

Then rebuild the extension and run the checks:

```shell
make ext-build
make check
```

## Testing

A type that does not round-trip is caught immediately. The cases that do not fail on
their own — a reordered envelope, a miscounted reference, a class that drifts from the
library it mirrors — are what the rest of the suite is for.

### The round trip, in Go

`TestRoundTripsEveryBSONType` in `msgpack_test.go` is where a new type goes first: it
builds a document holding a value of every type, runs it through
`BSONToMsgpack` → `MsgpackToBSON` and compares the result byte for byte, so a
mismatch in either direction fails there. `TestRoundTripsNestedSpecialValues` does the
same for a value nested in a sub-document and in an array.

The helpers in that file are `roundTrip` (encode, decode, fail on error) and
`buildDocument` (marshal a `bson.D`).

### Pinning the layout on bytes PHP really emits

A round trip only proves Go agrees with itself. What PHP writes is pinned separately,
and the fixtures are taken from PHP rather than written by hand:

```shell
docker compose exec php php -d extension=./ext/build/sconcur.so \
  -r 'require "vendor/autoload.php";
      echo bin2hex(msgpack_pack([<the value under test>])), PHP_EOL;'
```

Paste the hexadecimal into a test and decode it with `MsgpackToBSON`. The existing
ones to copy:

| Test | What it holds |
|---|---|
| `TestObjectEnvelopeLayout` | the envelope's shape: two pairs, `nil` first, then the class — a reordering would degrade into a plain array on the PHP side rather than fail |
| `TestResolvesRepeatedObjectInstances` | the reference form and the container numbering |
| `TestResolvesReferencesAfterAContainerInsideAProperty` | the numbering when a container sits inside a property |
| `TestKeepsValueObjectsInsideAJavascriptScope` | a value object nested in a property stays an object |
| `TestConvertsStdClassToADocument`, `TestConvertsAnEmptyStdClass` | a plain PHP object becomes a sub-document |
| `TestRejectsUnknownObjectClass`, `TestRejectsUnknownObjectReference` | an unknown class or a dangling reference is an error, not a silent skip |

For readability those tests assemble the payload from parts with `fixedString` and
`objectIdEnvelope` instead of one long hexadecimal line.

### The PHP side

| Test | What it holds |
|---|---|
| `tests/feature/Features/Mongodb/Bson/BsonDriverParityTest.php` | every value object against its `MongoDB\BSON\*` counterpart — string form, JSON form, the whole getter set — compared side by side rather than against a literal, so the promise being kept is parity with the library and not with a value written down once |
| `tests/feature/Features/Mongodb/Serialization/MongodbDocumentSerializerTest.php` | a full round trip through a live MongoDB: write the values, read them back, check the types |
| `tests/feature/Connection/MsgpackObjectSupportTest.php` | that init forces `msgpack.php_only`, verified in a child process started with the setting off |

A new type gets a row in the parity provider and a value in the serializer test.

### Cost

`msgpack_bench_test.go` measures the conversion in Go; `make bench-mongodb-serializer`
measures the PHP pack/unpack alone, with no database and no extension. Worth a look
when a type is added to a document that is already large, since the class name travels
with every value.

### Commands

```shell
make ext-build                                    # rebuild ext/build/sconcur.so first
make ext-test                                     # the Go tests
make test c="--filter=BsonDriverParityTest"       # one PHP test
make check                                        # cs-fixer, phpstan, PHP tests, Go tests
```

## Gotchas

| Case | Behaviour |
|---|---|
| An empty document | goes out as an empty MessagePack array, not an empty map: `ext-msgpack` decodes an empty map into a `stdClass` while every other document decodes into an array. PHP has one type for `{}` and `[]` anyway |
| A plain PHP object (`(object) [...]`, `json_decode()` without `associative: true`) | packs as a `stdClass` envelope and converts to a sub-document; it reads back as an array |
| Any other object with no `case` in `appendObject` | is an error — the value has no target type to become |
| An integer | narrows to int32 where it fits, which is why MongoDB's `int64` needs a wrapper class to survive a read followed by a write |
| A binary string | MessagePack `bin` and `str` are different codes; the decoder accepts either for a string property, and a `bin` value with no envelope becomes a generic binary |
| A numeric map key | PHP writes it as an integer; the decoder renders it as its decimal string |
