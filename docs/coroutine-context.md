English | [Русский](coroutine-context.ru.md)

# Coroutine context

`SConcur\Context\Context` is a key-value store bound to the current coroutine
(its fiber). It is isolated between concurrent coroutines and inherited by
children. It is meant to carry per-request state (the request, the user, the
locale) across `suspend`/`resume` without stuffing it into shared singletons — a
neighbouring coroutine would overwrite them.

The feature is framework-neutral: the store holds arbitrary `mixed` values under
string keys and knows nothing about them. Integration with a specific framework
is built on top of it.

## API

```php
use SConcur\Context\Context;

$context = Context::current();      // current coroutine's context (root outside a fiber)

$context->set('user', $user);       // write locally into the current coroutine
$context->find('user');             // value or null (respecting inheritance)
$context->has('user');              // is the key visible (own or inherited)
$context->forget('user');           // remove only the local key
```

`Context::current()` is the static entry point. It returns a `CoroutineContext`
pinned to the current fiber's id at the moment of the call, so the reference can
be held across this coroutine's own `suspend`/`resume`.

Signature of `set`:

```php
public function set(string $key, mixed $value, bool $replace = false): void;
```

With `replace: false` (the default) an already existing local key is not
overwritten; `replace: true` overwrites it.

## Semantics

Binding to a coroutine goes by `Fiber::getCurrent()` — the same marker by which
`State::getCurrentFlow()` resolves the flow. Every coroutine (a spawned request
handler and each `WaitGroup` coroutine) has its own local map.

Reads are read-through up the parent chain: the own map first, then the parent,
and so on up to the process root. Writes (`set`/`forget`) are always local: a
child may shadow a parent key without touching the parent or sibling coroutines.

The parent is fixed at coroutine creation: it is the coroutine that called
`Scheduler::spawn` or `WaitGroup::add`. A coroutine created outside any fiber
(the request handler in the server loop, bootstrap code) inherits from the root
context — it is shared per process and lives for its whole lifetime.

Outside a fiber `Context::current()` returns the root context rather than
throwing, so initialization code (before the first request) works as usual.

## Inheritance in nested coroutines

When a request handler spawns nested coroutines (for example, parallel database
queries via `WaitGroup`), they see the parent's keys:

```php
Context::current()->set('request', $request);

$group = WaitGroup::create();

$group->add(static function () {
    // inherits 'request' from the spawning coroutine
    $request = Context::current()->find('request');
    // ...
});

$group->waitAll();
```

## Lifecycle

The local map is created lazily (on the first `set`) and freed together with the
coroutine — in the same place where the library drops its per-fiber accounting
(`State::unRegisterFiber`, via `Scheduler::forget`/`detach` and
`State::deleteFlow`). After N coroutines finish, N contexts do not remain in
memory. The root context is not removed.

## Limits

- The environment is cooperative and single-threaded, there are no locks.
- `find`/`has`/`set` are on the hot path: O(1) over the own map plus walking up
  the parent chain (depth is usually 1–2: request → nested coroutine).
- The size of the values is on the caller's side; the library does not interpret
  them but guarantees they are freed when the coroutine finishes.
