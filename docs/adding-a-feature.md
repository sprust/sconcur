English | [Русский](adding-a-feature.ru.md)

# How to add a new top-level feature

A top-level feature is a new domain with its own `Method` (like `Sleeper`). The
reference to copy is `Sleeper`: PHP in `src/Features/Sleeper/`, Rust in
`ext/src/features/sleeper/`. Below is the walkthrough in two variants — without
streaming (a single result) and with streaming (multiple batches).

> Building a long-lived network server (like `HttpServer`)? That is a special kind
> of streaming feature with its own listener and serving loop — see
> [How to add a new server](adding-a-server.md).

## Two mandatory requirements

Every handler inside the extension must satisfy both; violating them leaks resources and
breaks `WaitGroup` behaviour.

1. **Context cancellation.** The task context `task.GetContext()` is cancelled when
   a flow stops (`WaitGroup::stop()`, an early `break`, `WaitGroup` destruction,
   `destroy`). Do the work on that context; for long operations listen on
   `ctx.Done()` via `select`, otherwise the task cannot be stopped. For streaming,
   release the resource on a **fresh** context (`context.Background()` + timeout):
   by the time cleanup runs, the task context is already cancelled.

2. **Passing the execution deadline.** The payload pushed from PHP must carry the
   execution deadline, and the extension must bound the operation with it — a task
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
`SConcur\Features\MethodEnum` and Rust `ext/src/types/method.rs` (`Method`).

A payload is the exchange contract, laid out mirror-wise on both sides: PHP
`src/Features/<Feature>/Payloads/` (one class per payload), Rust
`ext/src/features/<feature>/payloads.rs` (all types in one file, in a module named
after the PHP domain). Each PHP `*Payload` has a Rust struct with the same name;
the struct fields are the keys returned by `getData()`, and each `serde(rename)`
equals that short key — the struct decodes precisely by them. Cross-references are
mandatory in both directions.

```rust
/// The payload of a sleep command.
/// PHP: SConcur\Features\Sleeper\Payloads\SleeperPayload.
#[derive(Deserialize)]
pub struct SleeperPayload {
    #[serde(rename = "us")]
    pub microseconds: i64,
}
```

Multi-command features (the reference is `Mongodb`) use a two-level payload: a
shared envelope with a command field and `dt` (the serialized body) — one `Payload`
type in Rust, built by `Base\BaseMongodbPayload` on PHP — plus one struct per
command for the contents of `dt`. There the PHP `*PayloadParameters` classes are a
PHP-only convenience for assembling `dt` and are not carried over: their fields are
expanded directly into the corresponding Rust struct. If a command's `dt` is an
arbitrary user document (insert, count, runCommand, …) or empty (drop, list…), it
has no struct — `dt` is read as an untyped value in the handler, and that case is
marked with a comment, so every PHP `*Payload` corresponds to either a struct or an
explicit note.

A feature with many commands whose parameters are flat maps of short keys can skip the
class-per-command entirely: `Amqp` has one `AmqpPayload(AmqpCommandEnum $command, array
$data)`, the callers write the keys where the values are, and each enum case names the
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
    * Rust: payloads::FooPayload (ext/src/features/foo/payloads.rs).
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

Rust:

1. `ext/src/types/method.rs` — the same constant: a `Foo` case whose wire value is
   `"foo"`.

2. The feature module `ext/src/features/foo/mod.rs` implementing `Feature`
   (`handle(&self, task: Task) -> BoxFuture`): decode `message.payload`, do the
   work under `task.context()`, and answer with a result.

   ```rust
   impl Feature for FooFeature {
       fn handle(&self, task: Task) -> BoxFuture {
           Box::pin(async move {
               let start_time = Instant::now();
               let message = task.message();

               let payload: payloads::FooPayload = match rmp_serde::from_slice(&message.payload) {
                   Ok(payload) => payload,
                   Err(error) => {
                       task.add_result(Result::error(
                           message,
                           ERR_FACTORY.by_err("parse error", error),
                       )).await;

                       return;
                   }
               };

               // The flow's token cancels this on stop; the timeout bounds it.
               tokio::select! {
                   _ = task.context().cancelled() => {
                       task.add_result(Result::error(
                           message,
                           ERR_FACTORY.by_text("closed by task stop"),
                       )).await;
                   }
                   outcome = do_foo(&payload) => match outcome {
                       Ok(body) => {
                           task.add_result(Result::success(
                               message,
                               body,
                               calc_execution_ms(start_time),
                           )).await;
                       }
                       Err(error) => {
                           task.add_result(Result::error(
                               message,
                               ERR_FACTORY.by_err("foo error", error),
                           )).await;
                       }
                   },
               }
           })
       }
   }
   ```

   As with `Sleeper`, the feature is a singleton behind `OnceLock` + `get()`.

3. Registration in `ext/src/features/mod.rs` — an arm in `detect_message_handler`:

   ```rust
   Method::Foo => Ok(foo::get()),
   ```

## Variant B. With streaming (in batches)

Streaming returns the result in parts: the extension holds state, PHP pulls the
next batches.
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

Rust: the constant as in A, plus a state module in the feature (`rows_state.rs`
in `sql`, `message_state.rs` in `wsserver`; mongodb keeps them under `states.rs`)
implementing `StateContract` (`next()`, `close()`, both async):

```rust
pub struct FooState {
    // A mutex, because close() may arrive from cancellation while next() is
    // still using the resource.
    resource: tokio::sync::Mutex<Option<Resource>>,
    message: Arc<Message>,
    start_time: Instant,
}

impl StateContract for FooState {
    fn next(&self) -> StateFuture<'_> {
        Box::pin(async move {
            let mut resource = self.resource.lock().await;

            // Open the resource on the first call, then read one batch.

            // More data → a batch that says so:
            Result::success_with_next(&self.message, body, calc_execution_ms(self.start_time))
            // The last batch → without it, and the registry closes the state.
        })
    }

    fn close(&self) -> StateCloseFuture<'_> {
        Box::pin(async move {
            // Release the resource. This is awaited, so an abandoned MongoDB
            // cursor reaches the server before PHP looks at the cursor count.
        })
    }
}
```

The feature's `handle` builds the state and starts it through the registry;
`states::get().start()` hooks `close()` to the flow ending and returns the first
batch:

```rust
let state = Arc::new(FooState::new(task.message_arc() /*, parameters */));

match states::get().start(task.context().clone(), &message.task_key, state).await {
    Ok(result) => task.add_result(result).await,
    Err(error) => {
        task.add_result(Result::error(message, ERR_FACTORY.by_text(&error))).await;
    }
}
```

An unfinished stream (an early `break` on PHP) is closed automatically: PHP releases
the flow, its cancellation token fires, and the state registry hook calls
`close()` — which is why `close()` must not depend on the flow still being alive.

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
  `BaseTestCase`, and cover the extension's own logic with unit tests (`make ext-test`).

## Checklist

PHP:

- [ ] `MethodEnum` — a new value.
- [ ] Payload class (`PayloadInterface`) in `src/Features/<Feature>/Payloads/`;
      parameter assembly happens inside it; the payload carries the execution
      deadline; docblock with the cross-reference `Rust: payloads::<Type>`.
- [ ] Public API (for streaming — returns `IteratorResult`).
- [ ] A test from `BaseAsyncTestCase` plus edge tests from `BaseTestCase`.

Rust:

- [ ] The same constant in `ext/src/types/method.rs`.
- [ ] Payload structs in `payloads.rs`, mirroring the PHP `*Payload` 1:1 (names,
      `serde(rename)` keys) plus the cross-reference `// PHP: …`.
- [ ] A feature module with `handle`: the task's cancellation token honoured by
      every call; the work bounded by the passed timeout; for streaming — a
      `StateContract` state whose `close()` is awaited.
- [ ] Registration in `ext/src/features/mod.rs`.
- [ ] (opt.) unit tests in the extension.

Verification:
`make ext-build && make ext-test && make php-stan && make cs-fixer-check && make test`.
