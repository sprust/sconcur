//! What accepting one task costs the runtime, stage by stage.
//!
//! The PHP-side measurement (`make bench-push`) prices a push at 5.096 us of
//! CPU and attributes 3.68 of them to "the runtime accepting the task" — a
//! single number covering everything past the C boundary. The sampling profile
//! then made that number the largest article on the whole hot path, which is
//! reason enough to know what it consists of before touching any of it.
//!
//! Measured here rather than from PHP because the stages are not reachable from
//! there: the flow registry lookup, the per-flow bookkeeping and the spawn are
//! one crossing seen from the outside.
//!
//! The tasks pushed sleep for seconds, so nothing finishes inside a measured
//! window — the trap 521bb3c fell into, where the runtime executing what the
//! loop submitted lands in the number meant to price submitting it.
//!
//! Ignored by default: it takes seconds and is a measurement, not a check.
//! Release only — the debug build prices a different program.
//!
//!   make ext-bench-push

use std::sync::Arc;
use std::time::{Duration, Instant};

use tokio_util::sync::CancellationToken;

use crate::dto::Message;
use crate::features;
use crate::flows::flow::run_task_protected;
use crate::flows::Flows;
use crate::states;
use crate::tasks::{ResultSink, Task};
use crate::types::method::Method;

/// Enough iterations that a sub-microsecond stage is measured against a clock
/// that ticks in tens of nanoseconds.
const ITERATIONS: u32 = 20_000;

/// Stages are timed round by round and reported by median, because a stage that
/// runs alone owns whatever else the host was doing during its window.
const ROUNDS: usize = 5;

/// Long enough that nothing pushed inside a window can finish inside it.
const SLEEP_MICROSECONDS: i64 = 5_000_000;

fn sleep_payload() -> Vec<u8> {
    // The wire shape of SleeperPayload: a one-entry map, "us" => microseconds.
    let mut payload = Vec::with_capacity(16);

    let _ = rmp::encode::write_map_len(&mut payload, 1);
    let _ = rmp::encode::write_str(&mut payload, "us");
    let _ = rmp::encode::write_sint(&mut payload, SLEEP_MICROSECONDS);

    payload
}

fn message(flow_key: &str, task_key: &str, payload: &[u8]) -> Message {
    Message {
        flow_key: flow_key.to_string(),
        method: Method::Sleep,
        task_key: task_key.to_string(),
        payload: payload.to_vec(),
        is_next: false,
        owner_id: 1,
    }
}

fn median(mut values: Vec<f64>) -> f64 {
    values.sort_by(|a, b| a.partial_cmp(b).unwrap());

    let middle = values.len() / 2;

    if values.len() % 2 == 1 {
        values[middle]
    } else {
        (values[middle - 1] + values[middle]) / 2.0
    }
}

/// Microseconds per iteration.
fn time_per_call(iterations: u32, mut body: impl FnMut(u32)) -> f64 {
    let started = Instant::now();

    for index in 0..iterations {
        body(index);
    }

    started.elapsed().as_secs_f64() * 1_000_000.0 / f64::from(iterations)
}

/// The shape run_task_protected had before the arms were boxed, kept here so the
/// change that boxed them is measured against it in the same binary and the same
/// interleaved rounds rather than against a number from another session.
///
/// The difference is one thing only: the state arm awaited inline, which made
/// this future as large as that arm and every spawned task's allocation with it.
async fn run_task_inlined(task: Task, handler: features::Handler) {
    let guarded = std::panic::AssertUnwindSafe(async {
        match handler {
            features::Handler::Feature(feature) => feature.handle(task.clone()).await,
            features::Handler::State => states::get().next(&task).await,
        }
    });

    if let Err(panic) = futures_catch_unwind_local(guarded).await {
        task.add_result(crate::dto::Result::error(
            task.message(),
            format!("panic: {panic}"),
        ))
        .await;
    }
}

/// The bench's own copy of the guard, so the shape above stands on its own.
async fn futures_catch_unwind_local<F: std::future::Future<Output = ()>>(
    future: std::panic::AssertUnwindSafe<F>,
) -> std::result::Result<(), String> {
    use std::pin::pin;
    use std::task::Poll;

    let mut future = pin!(future.0);

    std::future::poll_fn(move |context| {
        match std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| future.as_mut().poll(context))) {
            Ok(Poll::Ready(())) => Poll::Ready(Ok(())),
            Ok(Poll::Pending) => Poll::Pending,
            Err(_) => Poll::Ready(Err("panic".to_string())),
        }
    })
    .await
}

#[test]
#[ignore = "a measurement, not a check: run it deliberately"]
fn push_cost_stages() {
    let runtime = tokio::runtime::Builder::new_multi_thread()
        .worker_threads(1)
        .enable_all()
        .thread_name("sconcur-bench")
        .build()
        .expect("runtime");

    let _guard = runtime.enter();

    let payload = sleep_payload();
    let ctx = CancellationToken::new();

    let mut rounds: Vec<(&str, Vec<f64>)> = vec![
        ("Message (2 strings + payload copy)", Vec::new()),
        ("init_flow, key already there", Vec::new()),
        ("init_flow + delete_flow, fresh key", Vec::new()),
        ("tokio::spawn of an empty future", Vec::new()),
        ("Task::new (3 clones)", Vec::new()),
        ("spawn of the task future, arms inlined", Vec::new()),
        ("spawn of the task future, arms boxed", Vec::new()),
        ("spawn of the same future, pre-boxed", Vec::new()),
        ("handle_message (bookkeeping + spawn)", Vec::new()),
        ("push whole (init_flow + handle_message)", Vec::new()),
    ];

    for round in 0..ROUNDS {
        // A registry per round, so a round starts from the state the previous
        // one did rather than from its leftovers.
        let (results_tx, _results_rx) = ResultSink::new(1024);
        let flows = Flows::new();

        // The flow every "already there" lookup finds.
        flows.init_flow(&ctx, "warm", &results_tx);

        rounds[0].1.push(time_per_call(ITERATIONS, |index| {
            std::hint::black_box(message("warm", &format!("warm:{index}"), &payload));
        }));

        rounds[1].1.push(time_per_call(ITERATIONS, |_| {
            std::hint::black_box(flows.init_flow(&ctx, "warm", &results_tx));
        }));

        rounds[2].1.push(time_per_call(ITERATIONS, |index| {
            // One flow per request is the server's shape: created on the way in,
            // deleted on the way out, which is what the pool inside Flows is for.
            let key = format!("r{round}:{index}");

            std::hint::black_box(flows.init_flow(&ctx, &key, &results_tx));
            flows.delete_flow(&key);
        }));

        rounds[3].1.push(time_per_call(ITERATIONS, |_| {
            std::hint::black_box(tokio::spawn(async {
                tokio::time::sleep(Duration::from_micros(SLEEP_MICROSECONDS as u64)).await;
            }));
        }));

        let flow = flows.init_flow(&ctx, "warm", &results_tx);
        let handler = features::Handler::Feature(
            features::detect_message_handler(Method::Sleep).expect("sleep handler"),
        );

        rounds[4].1.push(time_per_call(ITERATIONS, |index| {
            let message = Arc::new(message("warm", &format!("tn{round}:{index}"), &payload));

            std::hint::black_box(Task::new(ctx.clone(), results_tx.clone(), message));
        }));

        // The future handle_message spawns, rather than the empty one above: it
        // carries the task and boxes the feature's own future, so its allocation
        // is the one a push actually pays for.
        rounds[5].1.push(time_per_call(ITERATIONS, |index| {
            let message = Arc::new(message("warm", &format!("in{round}:{index}"), &payload));
            let task = Task::new(ctx.clone(), results_tx.clone(), message);

            std::hint::black_box(tokio::spawn(async move {
                run_task_inlined(task, handler).await;
            }));
        }));

        rounds[6].1.push(time_per_call(ITERATIONS, |index| {
            let message = Arc::new(message("warm", &format!("sp{round}:{index}"), &payload));
            let task = Task::new(ctx.clone(), results_tx.clone(), message);

            std::hint::black_box(tokio::spawn(async move {
                run_task_protected(task, handler).await;
            }));
        }));

        // The same future behind a Box: tokio moves what it is handed into the
        // task's allocation, so a large future is copied there in full. If the
        // copy is what costs, handing over a pointer instead shows it here.
        rounds[7].1.push(time_per_call(ITERATIONS, |index| {
            let message = Arc::new(message("warm", &format!("bx{round}:{index}"), &payload));
            let task = Task::new(ctx.clone(), results_tx.clone(), message);

            std::hint::black_box(tokio::spawn(Box::pin(async move {
                run_task_protected(task, handler).await;
            })));
        }));

        rounds[8].1.push(time_per_call(ITERATIONS, |index| {
            let message = Arc::new(message("warm", &format!("hm{round}:{index}"), &payload));

            flow.handle_message(message).expect("handle_message");
        }));

        rounds[9].1.push(time_per_call(ITERATIONS, |index| {
            let key = format!("p{round}:{index}");
            let message = message("warm", &key, &payload);

            let flow = flows.init_flow(&ctx, &message.flow_key, &results_tx);

            flow.handle_message(Arc::new(message)).expect("push");
        }));
    }

    // The size of what tokio is handed: a spawned future is moved into the
    // task's own allocation, so this is the number the spawn stages are about.
    let (sized_tx, _sized_rx) = ResultSink::new(1);
    let sized_task = Task::new(ctx.clone(), sized_tx, Arc::new(message("warm", "size", &payload)));
    let sized_handler = features::Handler::Feature(
        features::detect_message_handler(Method::Sleep).expect("sleep handler"),
    );
    let real_future = async move {
        run_task_protected(sized_task, sized_handler).await;
    };

    let (inlined_tx, _inlined_rx) = ResultSink::new(1);
    let inlined_task = Task::new(ctx.clone(), inlined_tx, Arc::new(message("warm", "size2", &payload)));
    let inlined_future = async move {
        run_task_inlined(inlined_task, sized_handler).await;
    };

    println!();
    println!(
        "  spawned future: {} bytes boxed, {} bytes inlined",
        std::mem::size_of_val(&real_future),
        std::mem::size_of_val(&inlined_future),
    );
    println!();
    println!(
        "{ITERATIONS} iterations per stage, {ROUNDS} interleaved rounds (median), 1 runtime thread"
    );
    println!();
    println!("  {:<42} {:>9} {:>9} {:>9}", "stage", "us/call", "min", "max");
    println!("  {:-<42} {:->9} {:->9} {:->9}", "", "", "", "");

    for (name, values) in rounds {
        let min = values.iter().cloned().fold(f64::INFINITY, f64::min);
        let max = values.iter().cloned().fold(f64::NEG_INFINITY, f64::max);

        println!("  {:<42} {:>9.3} {:>9.3} {:>9.3}", name, median(values), min, max);
    }
}
