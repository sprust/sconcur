English | [Русский](adding-a-feature.ru.md)

# How to add a new top-level feature

A top-level feature is a new domain with its own `Method` (like `Sleeper`). The
reference to copy is `Sleeper`: PHP in `src/Features/Sleeper/`, Go in
`ext-go-legacy/internal/features/sleeper/`. Below is the walkthrough in two variants — without
streaming (a single result) and with streaming (multiple batches).

> Building a long-lived network server (like `HttpServer`)? That is a special kind
> of streaming feature with its own listener and serving loop — see
> [How to add a new server](adding-a-server.md).

## Two mandatory requirements

Every handler on the Go side must satisfy both; violating them leaks resources and
breaks `WaitGroup` behaviour.

1. **Context cancellation.** The task context `task.GetContext()` is cancelled when
   a flow stops (`WaitGroup::stop()`, an early `break`, `WaitGroup` destruction,
   `destroy`). Do the work on that context; for long operations listen on
   `ctx.Done()` via `select`, otherwise the task cannot be stopped. For streaming,
   release the resource on a **fresh** context (`context.Background()` + timeout):
   by the time cleanup runs, the task context is already cancelled.

2. **Passing the execution deadline.** The payload pushed from PHP must carry the
   execution deadline, and the Go side must bound the operation with it — a task
   must not run indefinitely. How it is applied varies: sometimes the time is the
   essence of the operation (`Sleeper`); sometimes the timeout is applied natively
   (MongoDB passes `Client::$timeoutMs` and `::$serverSelectionTimeoutMs` into
   `options.Client().SetTimeout(...).SetServerSelectionTimeout(...)`); the generic
   way is to bound the task context:
   `ctx, cancel := context.WithTimeout(task.GetContext(), timeout)`.

   (`ExecutionMs` in the result is the actual work time set by
   `dto.NewSuccessResult` — not the timeout.)

## Method and payloads

The domain is a value duplicated in two places, and both must match: PHP
`SConcur\Features\MethodEnum` and Go `ext-go-legacy/internal/types/method.go` (`Method`).

A payload is the exchange contract, laid out mirror-wise on both sides: PHP
`src/Features/<Feature>/Payloads/` (one class per payload), Go
`ext-go-legacy/internal/features/<feature>/payloads/payloads.go` (all types in one file, in a
directory named after the PHP domain). Each PHP `*Payload` has a Go struct with the
same name; the struct fields are the keys returned by `getData()`, and the
`msgpack` (and `json`) tags equal those short keys — Go decodes precisely by the
tags. Cross-references are mandatory in both directions.

```go
// SleeperPayload is the payload of a sleep command.
// PHP: SConcur\Features\Sleeper\Payloads\SleeperPayload.
type SleeperPayload struct {
    Microseconds int64 `json:"us" msgpack:"us"`
}
```

Multi-command features (the reference is `Mongodb`) use a two-level payload: a
shared envelope with a command field and `dt` (the serialized body) — one `Payload`
type on Go, built by `Base\BaseMongodbPayload` on PHP — plus one struct per command
for the contents of `dt`. There the PHP `*PayloadParameters` classes are a PHP-only
convenience for assembling `dt` and are not carried over: their fields are expanded
directly into the corresponding Go struct. If a command's `dt` is an arbitrary user
document (insert, count, runCommand, …) or empty (drop, list…), it has no Go struct
— `dt` is read as raw BSON in the handler, and that case is marked with a comment,
so every PHP `*Payload` corresponds to either a Go struct or an explicit note.

A feature with many commands whose parameters are flat maps of short keys can skip the
class-per-command entirely: `Amqp` has one `AmqpPayload(AmqpCommandEnum $command, array
$data)`, the callers write the keys where the values are, and each enum case names the Go
struct its `dt` is decoded into. Two dozen near-identical classes buy nothing a named
argument at the call site does not already give. Prefer that shape when the parameters
carry no logic, and the Mongodb one when they do.

The PHP payload is `readonly`, its fields are typed, and the names are not
abbreviated.

## Variant A. Without streaming (a single result)

PHP:

1. `MethodEnum` — a new case (the 2-3 letter string value must be free and
   recognizable): `case Foo = 'foo';`

2. The payload class implementing `PayloadInterface`. `getMethod()` returns the new
   `Method`, `getData()` — the parameters serialized to MessagePack:

   ```php
   /**
    * Go: payloads.FooPayload (ext-go-legacy/internal/features/foo/payloads/payloads.go).
    */
   readonly class FooPayload implements PayloadInterface
   {
       public function __construct(
           protected int $someParam,
           protected int $timeoutMs, // the mandatory execution deadline
       ) {
       }

       public function getMethod(): MethodEnum
       {
           return MethodEnum::Foo;
       }

       /**
        * @return array<string, int>
        */
       public function getData(): array
       {
           return [
               'p'  => $this->someParam,
               'to' => $this->timeoutMs,
           ];
       }
   }
   ```

3. The public API `src/Features/Foo/Foo.php` — assemble the payload and execute:

   ```php
   $taskResult = FeatureExecutor::exec(
       payload: new FooPayload(someParam: $someParam, timeoutMs: $timeoutMs),
   );
   ```

Go:

1. `types/method.go` — the same constant: `MethodFoo Method = "foo"`.

2. The feature package `ext-go-legacy/internal/features/foo/feature.go` implementing
   `contracts.FeatureContract` (`Handle(task *tasks.Task)`): parse
   `message.Payload`, do the work on `task.GetContext()`, return the result with
   `ExecutionMs`.

   ```go
   func (f *FooFeature) Handle(task *tasks.Task) {
       start := time.Now()
       message := task.GetMessage()

       var payload payloads.FooPayload

       if err := msgpack.Unmarshal(message.Payload, &payload); err != nil {
           task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("parse error", err)))
           return
       }

       // Bound the work with the passed timeout; this same ctx is cancelled on stop.
       ctx, cancel := context.WithTimeout(
           task.GetContext(),
           time.Duration(payload.TimeoutMs)*time.Millisecond,
       )
       defer cancel()

       result, err := doFoo(ctx) // your operation; must respect ctx

       if err != nil {
           task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("foo error", err)))
           return
       }

       task.AddResult(dto.NewSuccessResult(message, result, helpers.CalcExecutionMs(start)))
   }
   ```

   As with `Sleeper`, the feature is usually a singleton via `sync.Once` + `Get()`.

3. Registration in `ext-go-legacy/internal/features/factory.go` — a case in
   `DetectMessageHandler`:

   ```go
   case types.MethodFoo:
       return foo_feature.Get(), nil
   ```

## Variant B. With streaming (in batches)

Streaming returns the result in parts: Go holds state, PHP pulls the next batches.
Routing `next` to the state is shared across all features, so no separate setup is
needed.

PHP: `MethodEnum` and the payload as in variant A; the public API returns an
iterator result wrapped around the payload, which requests the first and subsequent
batches itself. (`IteratorResult` below is Mongodb's
`Features\Mongodb\Results\IteratorResult`, shown as the pattern — it decodes batches
with the Mongo BSON serializer, so a new feature writes its own equivalent over its
payload format.)

```php
/**
 * @return Iterator<int, mixed>
 */
public function doFoo(int $someParam): Iterator
{
    return new IteratorResult(
        payload: new FooPayload(someParam: $someParam),
    );
}
```

Go: the constant as in A, plus a state file in the feature package (`rows_state.go`
in `sql`, `message_state.go` in `wsserver`; mongodb keeps them under `states/`)
implementing `contracts.StateContract` (`Next() *dto.Result`, `Close()`):

```go
type FooState struct {
    // the mutex serializes Next and Close: Close may arrive from context cancellation
    // while Next is still using the resource.
    mutex     sync.Mutex
    ctx       context.Context
    message   *dto.Message
    startTime time.Time
    // the held resource + parameters
}

func (s *FooState) Next() *dto.Result {
    s.mutex.Lock()
    defer s.mutex.Unlock()

    // lazily initialize the resource on s.ctx on the first call, read a batch

    // more data → a batch with the "more coming" flag:
    return dto.NewSuccessResultWithNext(s.message, response, helpers.CalcExecutionMs(s.startTime))
    // the last batch → without the flag (the state is removed, Close() is called)
}

// Close releases the resource on a FRESH context: the task context is already cancelled.
func (s *FooState) Close() {
    s.mutex.Lock()
    defer s.mutex.Unlock()

    closeCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
    defer cancel()

    // release the held resource on closeCtx
}
```

The feature's `Handle` creates the state and starts it through the registry;
`states.Get().Start` registers `Close()` on context cancellation and returns the
first batch:

```go
state := newFooState(task.GetContext(), message /*, parameters */)

result, err := states.Get().Start(task.GetContext(), message.TaskKey, state)
if err != nil {
    task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("foo", err)))
    return
}

task.AddResult(result)
```

An unfinished stream (an early `break` on PHP) is closed automatically: PHP releases
the flow, the task context is cancelled, and the state registry hook calls
`Close()` — which is why `Close()` must work on a fresh context.

## Tests (mandatory)

- One test per feature; if the feature has sub-operations, a test for each.
- All tests inherit from `BaseTestCase` (directly or via `BaseAsyncTestCase`).
  `BaseTestCase` manages the extension's lifecycle and in `tearDown` checks that
  there are no dangling tasks — this catches leaks and forgotten context
  cancellation.
- A feature test is written with the parent `BaseAsyncTestCase`, which defines the
  async pattern: two concurrent tasks via `WaitGroup`, checking event ordering,
  concurrency and the exception path. Implement its hooks
  (`on_1_start`/`on_1_middle`, `on_2_start`/`on_2_middle`, `on_iterate`,
  `on_exception`, `assertException`, `assertResult`) — in `assertResult` you also
  verify concurrency, i.e. that the total time ≈ the slowest operation rather than
  their sum. The reference is `tests/feature/Features/Sleeper/SleeperTest.php`.
- Add edge and synchronous checks as separate tests inheriting from
  `BaseTestCase`, and cover the Go logic with Go tests (`make ext-test`).

## Checklist

PHP:

- [ ] `MethodEnum` — a new value.
- [ ] Payload class (`PayloadInterface`) in `src/Features/<Feature>/Payloads/`;
      parameter assembly happens inside it; the payload carries the execution
      deadline; docblock with the cross-reference `Go: payloads.<Type>`.
- [ ] Public API (for streaming — returns `IteratorResult`).
- [ ] A test from `BaseAsyncTestCase` plus edge tests from `BaseTestCase`.

Go:

- [ ] The same constant in `types/method.go`.
- [ ] Payload structs in `payloads.go`, mirroring the PHP `*Payload` 1:1 (names,
      `msgpack` tags) plus the cross-reference `// PHP: …`.
- [ ] A feature package with `Handle`: the task context into every call; the work
      bounded by the passed timeout; for streaming — a `StateContract` state plus
      `Close()` on a fresh context.
- [ ] Registration in `features/factory.go`.
- [ ] (opt.) Go tests.

Verification:
`make ext-build && make ext-test && make php-stan && make cs-fixer-check && make test`.
