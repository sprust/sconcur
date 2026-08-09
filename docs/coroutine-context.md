English | [Русский](coroutine-context.ru.md)

# Coroutine context

`SConcur\Context\Context` is a key-value store bound to the current coroutine (its
fiber), isolated between concurrent coroutines and inherited by children. It
carries per-request state (the request, the user, the locale) across
`suspend`/`resume` without shared singletons, which a neighbouring coroutine would
overwrite. The store holds arbitrary `mixed` values under string keys and knows
nothing about them, so it stays framework-neutral.

## API

```php
use SConcur\Context\Context;

$context = Context::current();      // current coroutine's context (root outside a fiber)

$context->set('user', $user);       // write locally into the current coroutine
$context->find('user');             // value or null (respecting inheritance)
$context->has('user');              // is the key visible (own or inherited)
$context->forget('user');           // remove only the local key
```

`Context::current()` returns a `CoroutineContext` pinned to the current fiber's id
at the moment of the call, so the reference survives this coroutine's own
`suspend`/`resume`. In `set(string $key, mixed $value, bool $replace = false)` the
default `replace: false` does not overwrite an existing local key.

## Semantics

Binding goes by `Fiber::getCurrent()` — the same marker by which
`State::getCurrentFlow()` resolves the flow — so every coroutine (a spawned request
handler, each `WaitGroup` coroutine) has its own local map. Reads are read-through
up the parent chain: the own map first, then the parent, up to the process root.
Writes are always local, so a child may shadow a parent key without touching the
parent or its siblings.

The parent is fixed at coroutine creation — whoever called `Scheduler::spawn` or
`WaitGroup::add`. A coroutine created outside any fiber inherits from the root
context, which is shared per process; outside a fiber `Context::current()` returns
that root rather than throwing, so bootstrap code works as usual.

```php
Context::current()->set('request', $request);

$group = WaitGroup::create();

$group->add(static function () {
    // inherits 'request' from the spawning coroutine
    $request = Context::current()->find('request');
});

$group->waitAll();
```

## Lifecycle and limits

The local map is created lazily (on the first `set`) and freed together with the
coroutine, where the library drops its per-fiber accounting
(`State::unRegisterFiber`), so N finished coroutines leave no N contexts behind;
the root context is not removed.

The environment is cooperative and single-threaded, so there are no locks.
`find`/`has`/`set` are O(1) over the own map plus the walk up the parent chain,
whose depth is usually 1–2. The size of the values is the caller's business — the
library does not interpret them, but guarantees they are freed with the coroutine.
