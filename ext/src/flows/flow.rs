//! One flow: the tasks it holds, its cancellation token, and the states
//! registered under it.

use std::collections::HashSet;
use std::sync::Arc;
use std::sync::Mutex;
use std::sync::atomic::{AtomicI32, Ordering};
use tokio_util::sync::CancellationToken;

use crate::dto::{Message, Result};
use crate::features;
use crate::states;
use crate::tasks::{ResultSink, Task};

pub struct Flow {
    inner: Mutex<FlowInner>,
    tasks_count: AtomicI32,
}

struct FlowInner {
    ctx: CancellationToken,
    key: String,
    /// A set, not a map to the tasks: membership is the only thing ever asked.
    active_tasks: HashSet<String>,
    results: ResultSink,
}

impl Flow {
    /// Builds a flow that publishes task results into the shared results
    /// channel owned by the handler. All flows write to the same channel so the
    /// PHP side can wait for any flow's result at once (waitAny), which is what
    /// lets nested coroutines run concurrently with the outer flow.
    pub fn new(handler_ctx: &CancellationToken, key: String, results: ResultSink) -> Self {
        Flow {
            inner: Mutex::new(FlowInner {
                ctx: handler_ctx.child_token(),
                key,
                active_tasks: HashSet::new(),
                results,
            }),
            tasks_count: AtomicI32::new(0),
        }
    }

    /// Re-arms a pooled Flow for a new flow key, reusing the struct and its
    /// active-task set (cleared, not reallocated). Flow keys are globally
    /// unique and never reused, so a struct reused under a new key is invisible
    /// to any stale result of the old key: that result routes by its own string
    /// key, which get_flow no longer knows, so it is dropped before ever
    /// reaching this flow. A fresh child token is derived — a cancelled one
    /// cannot be reused.
    pub fn reset(&self, handler_ctx: &CancellationToken, key: String, results: ResultSink) {
        let mut inner = self.inner.lock().unwrap();

        inner.ctx = handler_ctx.child_token();
        inner.key = key;
        inner.results = results;
        inner.active_tasks.clear();

        self.tasks_count.store(0, Ordering::SeqCst);
    }

    pub fn handle_message(&self, message: Arc<Message>) -> std::result::Result<(), String> {
        let mut inner = self.inner.lock().unwrap();

        // Resolve the handler before mutating flow state: a task registered for
        // a message that will never run would corrupt the tasks accounting and
        // leave PHP waiting forever.
        let handler = if message.is_next {
            features::Handler::State
        } else {
            features::Handler::Feature(features::detect_message_handler(message.method)?)
        };

        let task = Task::new(inner.ctx.clone(), inner.results.clone(), message.clone());

        inner.active_tasks.insert(message.task_key.clone());
        self.tasks_count.fetch_add(1, Ordering::SeqCst);

        drop(inner);

        // One spawned task per message, rather than a worker pool: measured
        // against one and kept, because the pool cost more than it saved.
        tokio::spawn(async move {
            run_task_protected(task, handler).await;
        });

        Ok(())
    }

    /// Runs the post-delivery bookkeeping for a result that has just been
    /// pulled from the shared channel by the handler: drop the task from the
    /// active set and decrement the counter.
    pub fn on_delivered(&self, result: &Result) {
        let mut inner = self.inner.lock().unwrap();

        // A self-pumping server stream publishes many results under its single
        // serve task; only the first delivery may release the registration, or
        // the counter would go negative by one per served request.
        if !inner.active_tasks.remove(&result.task_key) {
            return;
        }

        self.tasks_count.fetch_sub(1, Ordering::SeqCst);
    }

    pub fn count(&self) -> i32 {
        self.tasks_count.load(Ordering::SeqCst)
    }

    pub fn cancel(&self) {
        let inner = self.inner.lock().unwrap();

        inner.ctx.cancel();

        self.tasks_count.store(0, Ordering::SeqCst);
    }
}

/// Converts a panic into a task error result: an unwind escaping into the C
/// caller is undefined behaviour and would abort the whole PHP process, which
/// is exactly what this exists to prevent.
///
/// Exported for the handler's detached (flowless) task path.
pub async fn run_task_protected(task: Task, handler: features::Handler) {
    // Both arms are resolved to a boxed future before this function's own state
    // machine is built, because a state machine is as large as its largest arm
    // and tokio copies what it is handed into the spawned task's allocation.
    // The feature arm is a BoxFuture already; the state arm used to be inlined,
    // and that one arm made every spawned task 1312 bytes — paid by every push,
    // feature pushes included, which is all of them on the hot path. Boxing it
    // leaves 280 bytes (with the panic path below) and takes the spawn from
    // 1.835 us to 0.762 — both shapes measured in the same binary, alternating
    // rounds, by ext/src/bench.rs, which keeps a copy of the old one for that.
    // The state arm allocates a Box of its own now, which it can afford: it is
    // the streaming next() path, not the per-request one.
    //
    // Building it is guarded too, and separately: it happens here rather than
    // inside the guarded future, so without this a panic while a feature builds
    // its future would escape into the spawned task and leave PHP waiting for a
    // result that can no longer come. The guard around a synchronous call costs
    // nothing when nothing panics.
    let built = std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| -> features::BoxFuture {
        match handler {
            features::Handler::Feature(feature) => feature.handle(task.clone()),
            features::Handler::State => {
                let task = task.clone();

                Box::pin(async move { states::get().next(&task).await })
            }
        }
    }));

    let inner = match built {
        Ok(inner) => inner,
        Err(panic) => {
            Box::pin(report_panic(task, describe_panic(panic.as_ref()))).await;

            return;
        }
    };

    if let Err(panic) = futures_catch_unwind(std::panic::AssertUnwindSafe(inner)).await {
        // Boxed for the same reason: publishing the error is a whole future of
        // its own, and inlined it would sit in every spawned task's allocation
        // to serve the one task in a million that panics.
        Box::pin(report_panic(task, panic)).await;
    }
}

/// The panic path, kept out of the caller's state machine.
async fn report_panic(task: Task, panic: String) {
    task.add_result(Result::error(task.message(), format!("panic: {panic}"))).await;
}

/// `catch_unwind` for a future: polls it inside the guard so a panic raised on
/// any poll — not only on creation — is caught. std has no async equivalent, so
/// the future is driven through a small wrapper.
async fn futures_catch_unwind<F: Future<Output = ()>>(
    future: std::panic::AssertUnwindSafe<F>,
) -> std::result::Result<(), String> {
    use std::pin::pin;
    use std::task::Poll;

    let mut future = pin!(future.0);

    std::future::poll_fn(move |context| {
        match std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
            future.as_mut().poll(context)
        })) {
            Ok(Poll::Pending) => Poll::Pending,
            Ok(Poll::Ready(())) => Poll::Ready(Ok(())),
            Err(panic) => Poll::Ready(Err(describe_panic(panic.as_ref()))),
        }
    })
    .await
}

fn describe_panic(panic: &(dyn std::any::Any + Send)) -> String {
    if let Some(text) = panic.downcast_ref::<&str>() {
        return (*text).to_string();
    }

    if let Some(text) = panic.downcast_ref::<String>() {
        return text.clone();
    }

    "unknown panic".to_string()
}
