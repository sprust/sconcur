//! Mirrors ext-go-legacy/internal/handler/handler.go: the singleton orchestrator routing
//! messages to flows and holding the one shared results channel the PHP side
//! waits on.

use crossbeam_channel::{Receiver, RecvTimeoutError, TryRecvError};
use std::collections::{HashMap, VecDeque};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};
use tokio_util::sync::CancellationToken;

use crate::dto::{Message, Result};
use crate::features;
use crate::flows::Flows;
use crate::tasks::{ResultSink, Task};
use crate::types::method::Method;

/// Buffers the shared results channel so a finished task publishes its result
/// and exits instead of parking until the PHP side pulls it: an unbuffered
/// hand-off into a blocking foreign-thread receive is a rendezvous costing two
/// futex wake-ups per result, which dominates fan-out coordination.
/// Backpressure still applies past the buffer (tasks::Task::add_result).
const RESULTS_BUFFER_SIZE: usize = 1024;

/// Caps one batch at what the multiframe's uint16 count field can carry.
/// Without the cap a larger max would silently truncate the count: PHP would
/// parse fewer frames than the buffer holds, while the excess results are
/// already delivered on this side — lost with no error anywhere.
const BATCH_MAX_CAP: usize = 65535;

/// How a wait ended. `Timeout` is not a failure: the caller polls again (the
/// serve loop checks for a shutdown signal between waits), and the PHP side
/// maps it to "no result yet".
pub enum WaitError {
    Timeout,
    Failed(String),
}

pub struct Handler {
    ctx: CancellationToken,
    flows: Flows,

    /// The single channel every flow's tasks publish into, so the PHP side can
    /// wait for the first ready result of any flow (wait_any). This is the
    /// foundation for nested coroutines running concurrently with the outer
    /// flow.
    results_tx: ResultSink,
    results_rx: Receiver<Result>,

    /// Results already pulled from the channel (post-processed once via
    /// deliver) but not yet claimed by a per-flow wait. Transitional: it backs
    /// the legacy wait(flowKey) while the PHP side still uses per-flow waiting.
    pending: Mutex<HashMap<String, VecDeque<Result>>>,

    /// Reused across batch waits to avoid a per-wait allocation. Safe because
    /// the single-threaded PHP caller serializes the batch waits and the
    /// previous batch is fully consumed (framed and copied across the boundary)
    /// before the next wait starts.
    batch_buffer: Mutex<Vec<Result>>,
}

/// Normalizes the PHP-supplied batch size into [1, BATCH_MAX_CAP]: the blocking
/// first result always ships (so the floor is 1), and the ceiling protects the
/// uint16 count field.
fn clamp_batch_max(max: i32) -> usize {
    (max.max(1) as usize).min(BATCH_MAX_CAP)
}

/// Reports whether a method may be pushed on the detached (flowless) path. That
/// path runs the handler synchronously on the PHP thread inside the push()
/// call, so a handler that blocks stalls the entire worker — the list is an
/// explicit opt-in, not a documented convention.
///
/// Two methods qualify. The HTTP respond handler is bounded by the
/// connection-side guards, and Amqp carries the teardown commands a PHP
/// destructor pushes — a channel close, a disconnect, a consumer cancel — which
/// hand their work to the runtime rather than doing it here.
fn detachable(method: Method) -> bool {
    matches!(method, Method::HttpRespond | Method::Amqp)
}

impl Handler {
    pub fn new() -> Self {
        let (results_tx, results_rx) = ResultSink::new(RESULTS_BUFFER_SIZE);

        Handler {
            ctx: CancellationToken::new(),
            flows: Flows::new(),
            results_tx,
            results_rx,
            pending: Mutex::new(HashMap::new()),
            batch_buffer: Mutex::new(Vec::new()),
        }
    }

    pub fn push(&self, message: Message) -> std::result::Result<(), String> {
        // Detached fire-and-forget task (empty flow key): the caller awaits no
        // result and the task needs no cancellation scope of its own, so no
        // flow is created and no stopFlow crossing will ever follow.
        //
        // Runs synchronously on the calling (PHP) thread, not on a runtime
        // worker: the write-command hand-over is a rendezvous, so by the time
        // this push returns the connection task has accepted the command and
        // the write is guaranteed to happen — a graceful drain that stops the
        // server flow right after the coroutine ends can no longer outrun the
        // response. Detached handlers must therefore never block.
        if message.flow_key.is_empty() {
            if message.is_next {
                return Err("next requires a flow key".to_string());
            }

            if !detachable(message.method) {
                return Err(format!(
                    "method {} cannot be pushed detached",
                    message.method.as_wire()
                ));
            }

            let feature = features::detect_message_handler(message.method)?;

            let task = Task::new(
                self.ctx.clone(),
                self.results_tx.clone(),
                Arc::new(message),
            );

            // Protected like every flow task: a panic here would unwind
            // straight into the PHP thread's C frame, which is the whole reason
            // flows::run_task_protected exists on the spawned path.
            let guarded = std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
                feature.handle_detached(task.clone());
            }));

            if guarded.is_err() {
                task.add_result_detached(Result::error(
                    task.message(),
                    "panic: detached handler".to_string(),
                ));
            }

            return Ok(());
        }

        let flow = self
            .flows
            .init_flow(&self.ctx, &message.flow_key, &self.results_tx);

        flow.handle_message(Arc::new(message))
    }

    /// Returns the first ready result of any flow. It is the basis of the
    /// PHP-side scheduler: one global wait point that lets every flow progress
    /// concurrently instead of each flow blocking on its own channel.
    ///
    /// Go hand-rolls a spin-before-park here (waitSpinIterations
    /// runtime.Gosched calls) because parking a cgo-locked thread and waking it
    /// across the boundary costs a futex round-trip of ~20-30us. crossbeam's
    /// recv already spins before parking, and this thread is a plain OS thread
    /// with no runtime attached to hand off to — so the spin has no counterpart
    /// to write here.
    pub fn wait_any(&self) -> std::result::Result<Result, String> {
        if let Some(result) = self.pop_any_pending() {
            return Ok(result);
        }

        loop {
            match self.results_rx.recv() {
                Ok(result) => {
                    self.results_tx.release(1);

                    if !self.deliver(&result) {
                        continue;
                    }

                    return Ok(result);
                }
                Err(_) => return Err("results channel closed".to_string()),
            }
        }
    }

    /// wait_any with a deadline, so a blocking PHP caller can wake periodically
    /// (e.g. to notice a shutdown signal on an idle server).
    pub fn wait_any_timeout(&self, ms: i32) -> std::result::Result<Result, WaitError> {
        if let Some(result) = self.pop_any_pending() {
            return Ok(result);
        }

        let deadline = Instant::now() + Duration::from_millis(ms.max(0) as u64);

        loop {
            let remaining = deadline.saturating_duration_since(Instant::now());

            match self.results_rx.recv_timeout(remaining) {
                Ok(result) => {
                    self.results_tx.release(1);

                    if !self.deliver(&result) {
                        continue;
                    }

                    return Ok(result);
                }
                Err(RecvTimeoutError::Timeout) => return Err(WaitError::Timeout),
                Err(RecvTimeoutError::Disconnected) => {
                    return Err(WaitError::Failed("results channel closed".to_string()));
                }
            }
        }
    }

    /// Returns the first ready result of any flow — blocking for it exactly
    /// like wait_any — plus every further result that is already ready, up to
    /// max in total. One crossing then carries the whole batch to PHP, saving a
    /// crossing, a frame copy and a userland call per result after the first.
    pub fn wait_any_batch(&self, max: i32) -> std::result::Result<Vec<Result>, String> {
        let first = self.wait_any()?;

        Ok(self.drain_ready(first, clamp_batch_max(max)))
    }

    pub fn wait_any_timeout_batch(
        &self,
        ms: i32,
        max: i32,
    ) -> std::result::Result<Vec<Result>, WaitError> {
        let first = self.wait_any_timeout(ms)?;

        Ok(self.drain_ready(first, clamp_batch_max(max)))
    }

    /// Collects the already-ready tail of a batch after its blocking first
    /// result: pending leftovers first, then a non-blocking drain of the
    /// channel. Every channel result passes deliver() exactly like on the
    /// single-result path; the batch never waits for more results to appear.
    fn drain_ready(&self, first: Result, max: usize) -> Vec<Result> {
        let mut results = std::mem::take(&mut *self.batch_buffer.lock().unwrap());

        results.clear();
        results.push(first);

        while results.len() < max {
            match self.pop_any_pending() {
                Some(result) => results.push(result),
                None => break,
            }
        }

        while results.len() < max {
            match self.results_rx.try_recv() {
                Ok(result) => {
                    self.results_tx.release(1);

                    if !self.deliver(&result) {
                        continue;
                    }

                    results.push(result);
                }
                Err(TryRecvError::Empty) | Err(TryRecvError::Disconnected) => break,
            }
        }

        results
    }

    /// Hands the batch's backing allocation back for the next wait. Called
    /// after the batch has been framed and copied across the boundary — the
    /// results themselves are dropped here, so no payload stays pinned by the
    /// buffer through a later trickle of small batches (the `clear` Go needs on
    /// its reused slice).
    pub fn recycle_batch(&self, mut results: Vec<Result>) {
        results.clear();

        *self.batch_buffer.lock().unwrap() = results;
    }

    /// Returns the next result of a specific flow, buffering any other flow's
    /// results into pending. Transitional compatibility for the per-flow
    /// PHP/sync path.
    pub fn wait(&self, flow_key: &str) -> std::result::Result<Result, String> {
        if let Some(result) = self.pop_pending(flow_key) {
            return Ok(result);
        }

        loop {
            match self.results_rx.recv() {
                Ok(result) => {
                    self.results_tx.release(1);

                    if !self.deliver(&result) {
                        continue;
                    }

                    if result.flow_key == flow_key {
                        return Ok(result);
                    }

                    self.push_pending(result);
                }
                Err(_) => return Err("results channel closed".to_string()),
            }
        }
    }

    /// Applies the post-delivery bookkeeping exactly once, when a result is
    /// first pulled from the shared channel, and reports whether the result's
    /// flow is still known. A false return marks a stale result: with a
    /// buffered channel a task may publish its result and the flow be stopped
    /// before the PHP side pulls it — nobody waits for such a result any more,
    /// so callers drop it and keep waiting.
    fn deliver(&self, result: &Result) -> bool {
        let Some(flow) = self.flows.get_flow(&result.flow_key) else {
            return false;
        };

        flow.on_delivered(result);

        true
    }

    fn pop_any_pending(&self) -> Option<Result> {
        let mut pending = self.pending.lock().unwrap();

        let key = pending
            .iter()
            .find(|(_, results)| !results.is_empty())
            .map(|(key, _)| key.clone())?;

        let results = pending.get_mut(&key)?;
        let result = results.pop_front();

        if results.is_empty() {
            pending.remove(&key);
        }

        result
    }

    fn pop_pending(&self, flow_key: &str) -> Option<Result> {
        let mut pending = self.pending.lock().unwrap();

        let results = pending.get_mut(flow_key)?;
        let result = results.pop_front();

        if results.is_empty() {
            pending.remove(flow_key);
        }

        result
    }

    fn push_pending(&self, result: Result) {
        self.pending
            .lock()
            .unwrap()
            .entry(result.flow_key.clone())
            .or_default()
            .push_back(result);
    }

    pub fn stop_flow(&self, flow_key: &str) {
        self.flows.delete_flow(flow_key);

        // The stopped flow's results may still sit in pending (buffered there
        // by a per-flow wait); nobody will ever claim them, so drop them with
        // the flow.
        self.pending.lock().unwrap().remove(flow_key);
    }

    pub fn tasks_count(&self) -> i32 {
        self.flows.tasks_count()
    }

    pub fn destroy(&self) {
        self.ctx.cancel();
        self.flows.cancel();
        features::shutdown();
    }
}
