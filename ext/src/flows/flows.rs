//! The registry of live flows, keyed by the flow key PHP sends.

use std::collections::HashMap;
use std::sync::{Arc, Mutex};
use tokio_util::sync::CancellationToken;

use crate::flows::Flow;
use crate::tasks::ResultSink;

pub struct Flows {
    inner: Mutex<FlowsInner>,
}

struct FlowsInner {
    flows: HashMap<String, Arc<Flow>>,
    /// Recycles Flow structs (and their active-task set) between a delete_flow
    /// and the next init_flow, so the common one-shot flow — the sync path and
    /// every async coroutine's own flow — stops allocating a fresh Flow + set
    /// on each call. Safe because flow keys are never reused (see Flow::reset).
    /// A plain Vec, not a lock-free pool: the registry lock is already held on
    /// both paths, so one would buy nothing here.
    pool: Vec<Arc<Flow>>,
}

impl Flows {
    pub fn new() -> Self {
        Flows {
            inner: Mutex::new(FlowsInner {
                flows: HashMap::new(),
                pool: Vec::new(),
            }),
        }
    }

    pub fn init_flow(
        &self,
        handler_ctx: &CancellationToken,
        flow_key: &str,
        results: &ResultSink,
    ) -> Arc<Flow> {
        let mut inner = self.inner.lock().unwrap();

        if let Some(flow) = inner.flows.get(flow_key) {
            return flow.clone();
        }

        let flow = match inner.pool.pop() {
            Some(pooled) => {
                pooled.reset(handler_ctx, flow_key.to_string(), results.clone());

                pooled
            }
            None => Arc::new(Flow::new(handler_ctx, flow_key.to_string(), results.clone())),
        };

        inner.flows.insert(flow_key.to_string(), flow.clone());

        flow
    }

    pub fn get_flow(&self, flow_key: &str) -> Option<Arc<Flow>> {
        self.inner.lock().unwrap().flows.get(flow_key).cloned()
    }

    pub fn delete_flow(&self, flow_key: &str) {
        let mut inner = self.inner.lock().unwrap();

        let Some(flow) = inner.flows.remove(flow_key) else {
            return;
        };

        flow.cancel();

        // Recycle the detached flow. Its key is retired for good (keys are
        // never reused), so any of its results still sitting in the buffered
        // channel route by a key get_flow no longer knows and are dropped —
        // they can never reach the struct once it is re-armed for a new key.
        // Only the last holder may recycle it: a task still running keeps its
        // own Arc, and re-arming under it would swap the flow beneath a live
        // task.
        if Arc::strong_count(&flow) == 1 {
            inner.pool.push(flow);
        }
    }

    pub fn tasks_count(&self) -> i32 {
        self.inner
            .lock()
            .unwrap()
            .flows
            .values()
            .map(|flow| flow.count())
            .sum()
    }

    pub fn cancel(&self) {
        let mut inner = self.inner.lock().unwrap();

        for flow in inner.flows.values() {
            flow.cancel();
        }

        inner.flows.clear();
        inner.pool.clear();
    }
}
