//! Mirrors ext/internal/features/httpserver/requeststats.go: the HTTP server's
//! workload counters — completed requests, their total duration (for the running
//! average), and the in-flight set keyed by request id, with each request's start
//! for the exclusive age buckets.

use std::collections::HashMap;
use std::sync::Mutex;
use std::time::{Duration, Instant};

use crate::stats::{Requests, Workload, WorkloadProvider};

/// The bucket thresholds, exclusive: a request in flight for 7s counts only in
/// the 5-to-15 bucket.
const WARN_AFTER: Duration = Duration::from_secs(1);
const SLOW_AFTER: Duration = Duration::from_secs(5);
const STUCK_AFTER: Duration = Duration::from_secs(15);

/// One lock over both halves, where Go splits them into a mutex for the counters
/// and a `sync.Map` for the in-flight set.
///
/// It can be one lock because it is never taken on a server that is not being
/// watched: the counters exist only when a collector is configured (see
/// `RequestStats::enabled`), so the hot path of an unwatched server pays an
/// `Option` check and nothing else. On a watched one the critical section is a
/// hash insert or removal plus two integer adds, which is what `sync.Map` costs
/// on its own slow path anyway.
#[derive(Default)]
struct Counters {
    completed: i64,
    total_duration_micros: i64,
    in_flight: HashMap<String, Instant>,
}

#[derive(Default)]
pub struct RequestStats {
    counters: Mutex<Counters>,
}

impl RequestStats {
    pub fn new() -> Self {
        RequestStats::default()
    }

    /// Records a request entering handling, keyed by its id for the age buckets.
    pub fn request_began(&self, request_id: &str, start: Instant) {
        self.counters
            .lock()
            .unwrap()
            .in_flight
            .insert(request_id.to_string(), start);
    }

    /// Records a finished request: drops it from the in-flight set and adds its
    /// duration to the completed counters.
    pub fn request_ended(&self, request_id: &str, start: Instant) {
        let mut counters = self.counters.lock().unwrap();

        counters.in_flight.remove(request_id);
        counters.completed += 1;
        counters.total_duration_micros += start.elapsed().as_micros() as i64;
    }
}

impl WorkloadProvider for RequestStats {
    fn workload_snapshot(&self) -> Workload {
        let now = Instant::now();
        let counters = self.counters.lock().unwrap();

        let mut requests = Requests {
            completed: counters.completed,
            avg_ms: if counters.completed > 0 {
                counters.total_duration_micros as f64 / counters.completed as f64 / 1000.0
            } else {
                0.0
            },
            ..Requests::default()
        };

        for start in counters.in_flight.values() {
            let age = now.duration_since(*start);

            requests.in_flight += 1;

            if age >= STUCK_AFTER {
                requests.in_flight_over_15s += 1;
            } else if age >= SLOW_AFTER {
                requests.in_flight_5_to_15s += 1;
            } else if age >= WARN_AFTER {
                requests.in_flight_1_to_5s += 1;
            }
        }

        Workload {
            requests: Some(requests),
            connections: None,
            consumers: None,
        }
    }
}
