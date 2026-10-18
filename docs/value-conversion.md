English | [Русский](value-conversion.ru.md)

# Values across the boundary

How a PHP value reaches the Rust core and how a Rust value reaches PHP. Nothing
is serialized in either direction: the core reads the PHP array a task is given
and builds the PHP values of a result itself, both on the PHP thread. This
document is the reference for that mechanism — the value tree, the mapping of
every PHP type, objects, how a feature uses it, and how to add a value class.
See [architecture](architecture.md) for where the crossing sits in the task
lifecycle.

## The two directions

| Direction | Where | What happens |
|---|---|---|
| PHP → core, a task's parameters | `SConcur\Extension\push()`, `ext/sconcur.c`, `read_value` in `ext/src/lib.rs` | the glue answers what each zval is; the core reads the array into a `dto::Value` tree before the call returns |
| core → PHP, a task's result | the `wait*` exports, `write_value` in `ext/src/lib.rs`, the value builder in `ext/sconcur.c` | the core walks the `Value` tree a feature built and the glue writes the zvals in place |

Both run inside the call PHP made, on the PHP thread: a Zend value may only be
touched there. A feature's task runs on the runtime's threads and never sees a
zval; it sees the tree.

## The value tree

`ext/src/dto/value.rs` holds the one type both directions share:

| Variant | PHP value |
|---|---|
| `Value::Nil` | `null` |
| `Value::Bool` | `bool` |
| `Value::Int` | `int` (64-bit, signed) |
| `Value::Float` | `float` |
| `Value::Str(Vec<u8>)` | `string`, text or bytes alike: PHP has one type for both |
| `Value::List(Vec<Value>)` | an array whose keys are `0..n` in order |
| `Value::Map(Vec<(Key, Value)>)` | any other array, in insertion order; `Key` is `Int` or `Str` |
| `Value::Object { class, properties }` | an instance of a userland class: its fully qualified name and its public properties in declaration order |

A `Map` is built with `Map::new().set("key", value)`; scalars, strings, byte
vectors, `Vec<Value>` and `Option` convert into a `Value` with `.into()`.
`Value::uint` renders an unsigned number as an `Int` when it fits and as a `Float`
past `i64::MAX`, which is what PHP itself does with such a number.

## PHP → core

The glue's value readers (`sconcur_zval_kind`, `sconcur_zval_long`,
`sconcur_zval_string`, `sconcur_array_iterate`, `sconcur_object_iterate`, …) answer
one question each about a zval; the two iterate calls walk a container and call the
core back per entry. The reading rules:

- a reference is read through; an uninitialized typed property is skipped;
- an array is a `List` when PHP says its keys are `0..n`, a `Map` otherwise, and
  **an empty array is an empty `List`**;
- a `stdClass` is read as a `Map` of its properties;
- any other object is read as `Value::Object` with its class name and its public
  properties; a protected or private property is not part of the value;
- a `Closure`, an enum case, a resource: the push fails with an error naming it;
- the tree may nest 256 levels deep; past that the push fails rather than
  overflow a stack;
- a property table built by user code (a lazy object's initializer) that throws
  fails the push; the exception propagates as it would from any call.

A feature declares its parameters as a `#[derive(Deserialize)]` struct and fills it
with `message.payload.decode::<T>()`. The serde side is `ext/src/dto/value_de.rs`,
and it follows PHP rather than a wire format:

| Field type | Accepts |
|---|---|
| `String` | a UTF-8 string; bytes that are not UTF-8 are refused |
| `Vec<u8>` with `#[serde(deserialize_with = "crate::dto::value_de::bytes")]` | any string, in one copy; `nullable_bytes` reads `null` as no bytes |
| `i64`, `u32`, … | an int in the target's range; a float or a string is refused |
| `f64` | a float, or an int |
| `bool` | a bool |
| `Option<T>` | `null` or a `T` |
| `Vec<T>` | a list; **a string never stands in for a list** |
| `HashMap<String, T>`, a struct | a map, or an empty array; an integer key reads as its decimal text |
| `Value` | the subtree as it stands, an object's class included |
| `Borrowed<'a>` with `#[serde(borrow)]` | a reference into the message instead of a copy — for the untyped command body a sub-operation decodes later |

A missing field takes its `#[serde(default)]`; an unknown field is ignored. The
reader side of `Value` (`get`, `as_str`, `as_list`, `as_map`, `entries`,
`Object::property`) is for a feature that walks a value by hand, as the MongoDB
converter does with a document.

## core → PHP

A feature puts a `Value` into `Result::success(message, value, execution_ms)`.
When PHP's wait call takes the result, `write_value` walks the tree and the glue's
value builder (`sconcur_value_*`, `sconcur_array_*`, `sconcur_object_*` in
`ext/sconcur.c`) writes each node:

- a `List` and a `Map` both become a PHP array (`array_init_size`, then one slot
  per entry); a `Map`'s string key that is the decimal form of an integer becomes
  that integer key, as it would in an array literal;
- an `Object` becomes an instance of the named class, created without its
  constructor, each named property written into its declared slot. The class is
  looked up through the autoloader once per request and cached; a class that is
  not loadable, or a property the class does not declare, fails the wait with an
  `Error` naming it;
- a `Str` is one `zend_string`, whatever the bytes.

A container's slots are handed out one at a time: a child is written before the
next sibling's slot is asked for, because adding to a PHP array may move what it
holds.

The result itself is an instance of `SConcur\Dto\TaskResultDto`, built the same
way by the result builder: the property names and order declared in
`src/Dto/TaskResultDto.php` are what the builder writes, so a change to that class
is a change to `ext/sconcur.c` in the same commit.

## Value classes

A PHP class whose instances cross the boundary as `Value::Object` is a value
class. The MongoDB feature's `SConcur\Bson\*` are the ones that exist today
(see [mongodb](mongodb.md#object-conversion)); the mechanism is feature-neutral.
Two constraints come from the mechanism:

- the properties the core reads and writes are public and declared: a protected
  property does not cross on the way in, and an undeclared one fails on the way
  out. Declare the class `readonly` to keep the value object immutable anyway;
- the constructor is not called when the core creates the instance, so validation
  there guards user code only. What arrives from PHP is validated on the core's
  side (the MongoDB converter's `property_*` helpers fail on a missing or
  mistyped property instead of substituting a zero).

Keep the class name short: it travels with every value.

## Adding a value class

1. The PHP class, with public properties, `readonly`, mirroring its counterpart
   exactly if it mirrors one (constructor, getters, `__toString()`,
   `jsonSerialize()`).
2. The way in: an arm in the feature's converter matching `object.class` and
   reading the properties with `Object::property(name)` — for MongoDB,
   `bson_from_object` in `ext/src/features/mongodb/serializer/from_value.rs`.
3. The way out: an arm building `Value::object(class, properties)` with the
   properties in declaration order — for MongoDB, `bson_value` in
   `serializer/to_value.rs`.
4. Tests: the converter's own unit tests on both arms (build the `Value` a PHP
   instance is read as and assert the result; build the result and assert class,
   property names and order), plus the feature's PHP test that writes the value
   and reads it back.

Then rebuild and run the checks:

```shell
make ext-build
make check
```

## Cost

Every crossing converts once at the edge: the read happens inside `push`, the
build inside the wait, and a feature's task works on the tree or on its own
types in between. `tests/benchmarks/runtime/boundary-profile.php` prices taking a
result, `push-profile.php` pushing a task, and `request-profile.php` what the HTTP
server does with a request event; see [benchmarks](benchmarks.md).

## Gotchas

| Case | Behaviour |
|---|---|
| An empty PHP array | arrives as an empty `List`; every reader of a map accepts it, and `as_map` answers an empty map |
| A string where a list is expected | refused, not read as a list of its bytes |
| A plain `Vec<u8>` field without the `bytes` attribute | compiles, but a string is refused for it: annotate the field |
| An integer past `i64` | cannot arrive: PHP ints are 64-bit; on the way out `Value::uint` makes a float of it |
| A `stdClass` | arrives as a `Map`, so a feature never sees an `Object` of that class |
| A value class with a protected property | the property does not cross: make it public |
| Two references to the same instance in one array | read twice; there is no sharing on the other side |
