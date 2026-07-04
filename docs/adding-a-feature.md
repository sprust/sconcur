English | [Русский](adding-a-feature.ru.md)

# How to add a new top-level feature

A top-level feature is a new domain with its own `Method` (like `Sleeper`). The reference
to copy from is `Sleeper`: PHP in `src/Features/Sleeper/` (payloads in
`src/Features/Sleeper/Payloads/`), Go in `ext/internal/features/sleeper/` (payloads in
`ext/internal/features/sleeper/payloads/`).

Below is a step-by-step walkthrough in two variants: without streaming (a single result) and
with streaming (multiple batches). The feature's concrete work is hidden behind "your
operation". For the overall architecture see the [README](../README.md).

> Building a long-lived network server (like `HttpServer`)? That is a special kind of
> streaming feature with its own listener and serving loop — see the separate guide
> [How to add a new server](adding-a-server.md).

---

## ⚠️ Two mandatory requirements

Every handler on the Go side must satisfy both rules. Violating them leads to resource
leaks and to incorrect `WaitGroup` behaviour.

1. **Context cancellation.** The task context `task.GetContext()` is cancelled when a
   thread stops (`WaitGroup::stop()`, an early `break`, `WaitGroup` destruction, `destroy`).
   Do the work on that context; for long operations listen on `ctx.Done()` via `select` —
   otherwise the task cannot be stopped. For streaming, release the resource on a **fresh**
   context (`context.Background()` + timeout): by the time cleanup runs, the task context is
   already cancelled.

2. **Passing the execution deadline.** When pushing a task from PHP you must pass the
   execution deadline, and the Go side must bound the operation with it — a task must not run
   indefinitely. Build this parameter into the feature's payload. How it is applied:
   - sometimes the time is the essence of the operation itself — `Sleeper` (sleep duration);
   - sometimes the timeout is applied natively — MongoDB passes
     `SConcur\Features\Mongodb\Connection\Client::$timeoutMs` (the operation deadline,
     CSOT) and `::$serverSelectionTimeoutMs` (how long to wait for an available server, so
     that an unavailable MongoDB does not hang the task), and Go applies them as
     `options.Client().ApplyURI(url).SetTimeout(...).SetServerSelectionTimeout(...)`;
   - the generic way — bound the task context:
     `ctx, cancel := context.WithTimeout(task.GetContext(), timeout)`.

   (`ExecutionMs` in the result is already the actual work time, set by
   `dto.NewSuccessResult`; do not confuse it with the timeout point.)

---

## `Method` PHP ↔ Go correspondence

The domain is a number duplicated in two places; both must match:

- PHP: `SConcur\Features\MethodEnum`
- Go: `ext/internal/types/method.go` (`Method`)

---

## Laying out payloads (PHP ↔ Go)

A payload is the exchange contract between PHP and Go. It is laid out mirror-wise on both
sides, so that the "PHP → Go" conversion reads clearly.

Location:
- PHP: `src/Features/<Feature>/Payloads/` — one class per payload.
- Go: `ext/internal/features/<feature>/payloads/payloads.go` — all types in a single file
  of the `payloads` package.

The feature's Go directory is named the same as the PHP domain (`Sleeper` → `sleeper`,
`Mongodb` → `mongodb`).

1:1 correspondence: each PHP `*Payload` has a Go struct with the same name.
The Go struct fields are the keys returned by `getData()`; the `msgpack` (and `json`) tags
equal these short keys. Go decodes the payload precisely by the `msgpack` tags.

```go
// SleeperPayload is the payload of a sleep command.
// PHP: SConcur\Features\Sleeper\Payloads\SleeperPayload.
type SleeperPayload struct {
    Microseconds int64 `json:"us" msgpack:"us"`
}
```

Cross-references are mandatory in both directions (as comments):
- on the Go struct: `// PHP: SConcur\Features\<Feature>\Payloads\<Class>`;
- on the PHP class (docblock): `Go: payloads.<Type> (ext/internal/features/<feature>/payloads/payloads.go)`.

Multi-command features (the reference is `Mongodb`). When a single `Method` serves many
commands, the payload is two-level:
- a shared envelope with a command field and `dt` (the serialized body) —
  on Go this is one `Payload` type, on PHP it is built by `Base\BaseMongodbPayload`;
- the contents of `dt` — one struct per command, the names mirror the PHP `*Payload`.

Rules for such features:
- The PHP `*PayloadParameters` classes are a PHP-only convenience for assembling `dt`; they
  are not carried over to Go. Their fields are expanded directly into the corresponding
  `*Payload` struct on Go (option fields inlined).
- If a command's `dt` is an arbitrary user document/array (insert, count,
  runCommand, …) or empty (drop, list…), it has no Go struct: `dt` is read as
  raw BSON in the handler. Such a case is marked with a comment in `payloads.go`, so that
  every PHP `*Payload` corresponds to either a Go struct or an explicit note.

Other: the payload carries the execution deadline (see requirement 2). The PHP payload is
`readonly`, its fields are typed, and the names are not abbreviated.

References: `Sleeper` (a single command) and `Mongodb` (envelope + commands).

---

## Variant A. Without streaming (a single result)

### PHP

1. `MethodEnum` — a new case (the number must be free; at the time of writing the
   first free one is `14`):
   ```php
   case Foo = 14;
   ```

2. The payload class `src/Features/Foo/Payloads/FooPayload.php`, implementing
   `PayloadInterface` (for the layout, see "Laying out payloads" above). `getMethod()`
   returns the new `Method`, `getData()` — the parameters as an array (serialized to
   MessagePack):
   ```php
   /**
    * Go: payloads.FooPayload (ext/internal/features/foo/payloads/payloads.go).
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
   readonly class Foo
   {
       public function doFoo(int $someParam, int $timeoutMs): void
       {
           $taskResult = FeatureExecutor::exec(
               payload: new FooPayload(someParam: $someParam, timeoutMs: $timeoutMs),
           );

           // if needed — parse $taskResult->payload
       }
   }
   ```

### Go

1. `types/method.go` — the same constant:
   ```go
   MethodFoo Method = 14
   ```

2. The feature package `ext/internal/features/foo/feature.go`, implementing
   `contracts.FeatureContract` (`Handle(task *tasks.Task)`). Inside: parse
   `message.Payload`, do the work on `task.GetContext()`, return the result with
   `ExecutionMs`:
   ```go
   var errFactory = errs.NewErrorsFactory("foo")

   type FooFeature struct{}

   func (f *FooFeature) Handle(task *tasks.Task) {
       start := time.Now()
       message := task.GetMessage()

       var payload payloads.FooPayload // payloads.FooPayload mirrors PHP FooPayload; TimeoutMs has the msgpack:"to" tag

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
   (as with `Sleeper`, the feature is usually made a singleton via `sync.Once` + `Get()`.)

3. Registration in `ext/internal/features/factory.go` — a case in `DetectMessageHandler`:
   ```go
   case types.MethodFoo:
       return foo_feature.Get(), nil
   ```

---

## Variant B. With streaming (in batches)

Streaming returns the result in parts: Go holds "state", PHP pulls the next batches.
Routing `next` to the state is shared across all features, no separate setup is needed.

### PHP

1. `MethodEnum` + Payload — as in variant A.

2. The public API returns an `IteratorResult` wrapped around the payload — it will itself
   request the first and subsequent batches:
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

### Go

1. `types/method.go` — the constant (as in A).

2. The state `ext/internal/features/foo/state/foo.go`, implementing
   `contracts.StateContract` (`Next() *dto.Result`, `Close()`):
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
       // the last batch → without the flag (the state is removed, Close() is called):
       // return dto.NewSuccessResult(s.message, response, helpers.CalcExecutionMs(s.startTime))
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

3. The feature's `Handle` creates the state and starts it through the state registry;
   `states.Get().Start` will itself register `Close()` on context cancellation and return
   the first batch:
   ```go
   func (f *FooFeature) Handle(task *tasks.Task) {
       message := task.GetMessage()
       // ... parse message.Payload ...

       state := state.New(task.GetContext(), message /*, parameters */)

       result, err := states.Get().Start(task.GetContext(), message.TaskKey, state)
       if err != nil {
           task.AddResult(dto.NewErrorResult(message, errFactory.ByErr("foo", err)))
           return
       }

       task.AddResult(result)
   }
   ```

4. Registration in `factory.go` — as in A.

> An unfinished stream (an early `break` on PHP) is closed automatically: PHP releases the
> thread, the task context is cancelled, and the state registry hook calls `Close()`. That is
> why `Close()` must work on a fresh context.

---

## Tests (mandatory)

- One test per feature. If the feature has sub-operations — a test for each.
- All tests inherit from `BaseTestCase` (directly or via `BaseAsyncTestCase`).
  `BaseTestCase` manages the extension's lifecycle and in `tearDown` checks that there are
  no "dangling" tasks — this catches leaks and forgotten context cancellation.
- A feature test is written with the parent `BaseAsyncTestCase` — it defines the async
  pattern: two concurrent tasks via `WaitGroup`, checking event ordering,
  concurrency and the exception path (synchronous and asynchronous). Implement the hooks:
  - `on_1_start` / `on_1_middle`, `on_2_start` / `on_2_middle` — the steps of the two tasks
    (call your operation inside them);
  - `on_iterate` — an action on each iteration of the result;
  - `on_exception` — a call that must throw an exception;
  - `assertException(Throwable)` — the exception check;
  - `assertResult(array $results)` — the results check; here you also verify
    concurrency (the total time ≈ the slowest operation, not their sum).

  The reference is `tests/feature/Features/Sleeper/SleeperTest.php`.
- Add edge/synchronous checks as separate tests inheriting from `BaseTestCase`.
- Cover the Go logic with Go tests (`make ext-test`).

---

## Checklist

PHP:
- [ ] `MethodEnum` — a new value.
- [ ] Payload class (`PayloadInterface`) in `src/Features/<Feature>/Payloads/`; parameter
      assembly happens inside it; the payload carries the execution deadline (timeout);
      docblock with the cross-reference `Go: payloads.<Type>`.
- [ ] Public API (for streaming — returns `IteratorResult`).
- [ ] A test from `BaseAsyncTestCase` + edge tests from `BaseTestCase`.

Go:
- [ ] The same constant in `types/method.go`.
- [ ] Payload structs in `ext/internal/features/<feature>/payloads/payloads.go`,
      mirroring the PHP `*Payload` 1:1 (names, `msgpack` tags) + the cross-reference `// PHP: …`.
- [ ] A feature package with `Handle`: the task context into every call; the work bounded by the
      passed timeout; for streaming — a `StateContract` state + `Close()` on a fresh context.
- [ ] Registration in `features/factory.go`.
- [ ] (opt.) Go tests.

Verification: `make ext-build && make ext-test && make php-stan && make cs-fixer-check && make test`.
