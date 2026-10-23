English | [Русский](adding-a-feature.ru.md)

# How to add a new top-level feature

A feature is a PHP API in `src/Features/<Feature>/` plus the Rust module in
`ext/src/features/<feature>/` that executes its tasks. A top-level feature is a new
domain with its own `Method` (like `Sleeper`). The reference to copy is `Sleeper`:
PHP in `src/Features/Sleeper/`, Rust in `ext/src/features/sleeper/`. Below is the
walkthrough in two variants — without streaming (a single result) and with
streaming (multiple batches).

> Building a long-lived network server (like `HttpServer`)? That is a special kind
> of streaming feature with its own listener and serving loop — see
> [How to add a new server](adding-a-server.md).

## Two mandatory requirements

Every handler inside the extension must satisfy both; violating them leaks resources and
breaks `WaitGroup` behaviour.

1. Cancellation. The task's cancellation token, `task.context()`, is cancelled
   when its flow (the group of tasks a `WaitGroup` owns in the extension) stops
   (`WaitGroup::stop()`, an early `break`, `WaitGroup` destruction — which waits for
   its running members, since they hold it — `destroy`).
   For a long operation put `_ = task.context().cancelled()`
   into the `select!` beside it, otherwise the task cannot be stopped. Cleanup
   after a stream must not run under that token — it is already cancelled by the
   time the cleanup starts, so give the release its own bound.

2. The execution deadline. The payload pushed from PHP must carry the
   execution deadline, and the extension must bound the operation with it — a task
   must not run indefinitely. How it is applied varies: sometimes the time is the
   essence of the operation (`Sleeper`); sometimes part of it is a driver option
   (MongoDB passes `serverSelectionTimeoutMs` to the driver as its
   server-selection timeout); the generic way, which MongoDB and SQL use for the
   call itself, is `tokio::time::timeout(Duration::from_millis(timeout_ms), work)`.

   (`executionMs` in the result is the actual work time, measured by
   `calc_execution_ms` — not the timeout.)

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

`Mongodb` is the exception to the struct-per-payload rule. Every command is one
envelope (`ul`/`db`/`cl`/`to`/`sst`/`cm`/`dt`) built by `Base\BaseMongodbPayload`
on PHP; Rust reads it by hand with `decode_envelope()` into `Envelope`
(`ext/src/features/mongodb/payloads.rs`), without serde, because `dt` holds nested
values that are converted to BSON. `dt` is either the user's document (insert,
count, runCommand, …), empty (drop, list…), or a map of named parameters assembled
by the PHP `*PayloadParameters` classes; the handler in `commands.rs` reads that map by
short key through `decode_params()` and `Params` (`params.filter("f")`). There is
no Rust struct per command.

A feature with many commands whose parameters are flat maps of short keys can
skip the class-per-command entirely: `Amqp` has one
`AmqpPayload(AmqpCommandEnum $command, array $data)` (Rust `payloads::Envelope`),
the callers write the keys where the values are, and the docblock of each
`AmqpCommandEnum` case names the struct its `p` is decoded into. Prefer that shape
when the parameters carry no logic, and a class per command when they do.

The PHP payload is `readonly`, its fields are typed, and the names are not
abbreviated.

## Variant A. Without streaming (a single result)

PHP:

1. `MethodEnum` — a new case (the 2-3 letter string value must be free and
   recognizable): `case Foo = 'foo';`

2. The payload class implementing `PayloadInterface`. `getMethod()` returns the new
   `Method`, `getData()` — the parameters as the array the core reads:

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

1. `ext/src/types/method.rs` — the same constant: a `Method::Foo` variant, plus an
   arm in each of `from_wire()` (`b"foo"`), `as_wire()` (`"foo"`) and
   `as_php_case()` (`c"Foo"`, the PHP case name the result builder in
   `ext/sconcur.c` fetches the enum case by).

2. `ext/src/features/foo/payloads.rs` — `FooPayload` with `#[serde(rename = "p")]`
   and `#[serde(rename = "to")]` fields and the `/// PHP: …` cross-reference.

3. The feature module `ext/src/features/foo/mod.rs` implementing `Feature`
   (`handle(&self, task: Task) -> BoxFuture`): decode `message.payload`, do the
   work under `task.context()`, and answer with a result.

   ```rust
   impl Feature for FooFeature {
       fn handle(&self, task: Task) -> BoxFuture {
           Box::pin(async move {
               let start_time = Instant::now();
               let message = task.message();

               let payload: payloads::FooPayload = match message.payload.decode() {
                   Ok(payload) => payload,
                   Err(error) => {
                       task.add_result(Result::error(
                           message,
                           ERR_FACTORY.by_err("parse error", error),
                       )).await;

                       return;
                   }
               };

               // The flow's token cancels this on stop; do_foo bounds itself with
               // payload.timeout_ms (requirement 2).
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

   The singleton itself holds nothing. What the feature keeps for the life of the
   process — connections, pools, open handles, a flag that a background task is
   running — goes into the feature's `Registries` struct, a field of `Core` in
   `ext/src/core.rs` — declared in the struct, built in its constructor, reached
   through an accessor (`crate::core::get().sql()`) — and never into a `static`. After
   a `fork` the child rebuilds the core and everything on it; a `static` would hand
   the child the parent's connections, a flag for a task that runs in a runtime the
   child does not have, or a mutex another thread held at the moment of the fork. A
   task started on first use is armed by a flag on the registry for the same reason
   (`sweeping` in `ext/src/features/sql/pools.rs`). Counters that only number ids may
   stay `static`: a child that carries on the parent's numbering breaks nothing.

4. Registration in `ext/src/features/mod.rs`: `pub mod foo;` among the module
   declarations, an arm in `detect_message_handler`, and, if the feature holds
   connections or handles, a call to its own `shutdown()` inside `shutdown()`, which
   `destroy` runs:

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
`Features\Mongodb\Results\IteratorResult`, shown as the pattern — it checks that a
batch is the list the core built, so a new feature writes its own equivalent over
the shape of its batches.)

```php
/**
 * @return Iterator<int, mixed>
 */
public function doFoo(int $someParam, int $timeoutMs): Iterator
{
    return new IteratorResult(
        payload: new FooPayload(someParam: $someParam, timeoutMs: $timeoutMs),
    );
}
```

Rust: the constant as in A, plus a state module in the feature (`rows_state.rs`
in `sql`, `ext/src/ws/message_state.rs` for the WebSocket server; mongodb keeps
them under `states.rs`) implementing `StateContract` (`next()`, `close()`, both
async):

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

The hook ends with the state as well: a stream read to the end, or deleted by the
feature, takes its hook with it. This matters because a flow can live as long as the
process — a coroutine (a PHP fiber the scheduler suspends) that loops forever, such
as a task-pool task, keeps one flow for
every stream it ever opens. A state that cannot be started, because its first batch
is not ready yet (a transaction, a subscription, a streamed upload), is registered
with `states::get().register_with_flow()`, which hooks it the same way. Do not spawn
a task of your own that waits for `task.context().cancelled()` to delete a state:
nothing ends that task before the flow does.

## Tests (mandatory)

- One test per feature; if the feature has sub-operations, a test for each.
- All tests inherit from `BaseTestCase` (`tests/feature/BaseTestCase.php`, directly
  or via `tests/feature/BaseAsyncTestCase.php`).
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
- Add edge-case and synchronous checks as separate tests inheriting from
  `BaseTestCase`, and cover the extension's own logic with unit tests (`make ext-test`).

## Checklist

PHP:

- [ ] `MethodEnum` — a new value.
- [ ] Payload class (`PayloadInterface`) in `src/Features/<Feature>/Payloads/`;
      parameter assembly happens inside it; the payload carries the execution
      deadline; docblock with the cross-reference `Rust: payloads::<Type>`.
- [ ] Public API (for streaming — returns `IteratorResult`).
- [ ] A test from `BaseAsyncTestCase` plus edge-case tests from `BaseTestCase`.

Rust:

- [ ] The same constant in `ext/src/types/method.rs`: the variant and its arms in
      `from_wire()`, `as_wire()`, `as_php_case()`.
- [ ] Payload structs in `payloads.rs`, mirroring the PHP `*Payload` 1:1 (names,
      `serde(rename)` keys) plus the cross-reference `/// PHP: …`.
- [ ] A feature module with `handle`: the task's cancellation token honoured by
      every call; the work bounded by the passed timeout; for streaming — a
      `StateContract` state whose `close()` is awaited.
- [ ] Whatever the feature keeps for the life of the process sits in its
      `Registries` on `Core`, not in a `static`.
- [ ] Registration in `ext/src/features/mod.rs` (`pub mod`, the
      `detect_message_handler` arm, `shutdown()` if it holds resources).
- [ ] (opt.) unit tests in the extension.

Verification: `make check` (runs `cs-fixer-check`, `php-stan`, `ext-build`,
`ext-test`, `ext-check` and `test`).
