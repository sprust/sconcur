//! Mirrors ext/internal/stats: one worker's statistics snapshot, pushed framed
//! over a unix socket to an external collector (the PHP worker master, or any
//! other supervisor speaking the same contract). Aggregation, the live panel and
//! the /api/stats endpoint live in the collector, not here — this module only
//! samples and pushes.
//!
//! Wire contract, and the collector reads it verbatim:
//! a unix SOCK_STREAM connection carrying length-prefixed frames (the
//! `socket::frame` codec — 4-byte big-endian length + body); each body is UTF-8
//! JSON `{"t":"snapshot","s":<Snapshot>}`.
//!
//! Pushing is best-effort and lossy: with the collector absent the frame is
//! dropped and the worker keeps serving traffic unaffected.
//!
//! The process metrics (memory, CPU, uptime) are universal; the workload section
//! is feature-specific and supplied through a `WorkloadProvider` — HTTP fills
//! `requests`, the two connection servers fill `connections`, the queue consumer
//! fills `consumers`.
//!
//! There is exactly one push loop, and one collector connection, per process. The
//! collector reads a connection as a worker — it keys its store by connection and
//! evicts on close — so a worker that both serves and consumes must not dial twice,
//! or it is counted as two workers with two different start times. Every
//! `WorkloadProvider` of the process registers into one registry instead, and the
//! loop merges their sections into the single snapshot the `Snapshot` struct was
//! always shaped to carry.

pub mod metrics;

use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

use serde::Serialize;
use tokio::net::UnixStream;
use tokio_util::sync::CancellationToken;

use crate::socket::frame::write_frame;

/// The sample/push cadence when the PHP side names none.
const DEFAULT_INTERVAL_MS: u64 = 1000;

/// Bounds one connect and one frame write, so a slow or wedged collector never
/// holds the push loop for long; on timeout the frame is dropped.
const PUSH_WRITE_TIMEOUT: Duration = Duration::from_secs(1);

/// The process memory split. `rss_bytes` is the whole resident set;
/// One number, because one is all this can honestly report. A split between the
/// extension's memory and PHP's would need a tracking allocator — this core
/// allocates through the system one, alongside the interpreter, so nothing in
/// the process can say which resident page belongs to which side.
///
/// The JSON name is the wire contract the collector and
/// SConcur\Telemetry\Dto\Memory decode.
#[derive(Serialize)]
pub struct Memory {
    #[serde(rename = "rssBytes")]
    pub rss_bytes: i64,
}

/// The HTTP-server workload section. The in-flight buckets are exclusive: a
/// request in flight for 7s counts only in `in_flight_5_to_15s`.
#[derive(Serialize, Default)]
pub struct Requests {
    pub completed: i64,
    #[serde(rename = "avgMs")]
    pub avg_ms: f64,
    #[serde(rename = "inFlight")]
    pub in_flight: i64,
    #[serde(rename = "inFlight1to5s")]
    pub in_flight_1_to_5s: i64,
    #[serde(rename = "inFlight5to15s")]
    pub in_flight_5_to_15s: i64,
    #[serde(rename = "inFlightOver15s")]
    pub in_flight_over_15s: i64,
}

/// The connection-server workload section, reported by both the socket and the
/// WebSocket server: `active` is the current open count, `total_accepted` the
/// lifetime number.
#[derive(Serialize, Default)]
pub struct Connections {
    pub active: i64,
    #[serde(rename = "totalAccepted")]
    pub total_accepted: i64,
}

/// The queue-consumer workload section. `coroutines` is how many consumers the
/// worker has open — one per coroutine, so the capacity in use; the in-flight
/// buckets are exclusive, as the request ones are.
#[derive(Serialize, Default)]
pub struct Consumers {
    pub coroutines: i64,
    pub delivered: i64,
    pub acked: i64,
    pub refused: i64,
    pub timed: i64,
    #[serde(rename = "avgMs")]
    pub avg_ms: f64,
    #[serde(rename = "inFlight")]
    pub in_flight: i64,
    #[serde(rename = "inFlight1to5s")]
    pub in_flight_1_to_5s: i64,
    #[serde(rename = "inFlight5to15s")]
    pub in_flight_5_to_15s: i64,
    #[serde(rename = "inFlightOver15s")]
    pub in_flight_over_15s: i64,
}

/// The feature-specific part of a snapshot. Exactly one section is set per
/// server kind, and a worker that has done nothing reports none at all — so a
/// snapshot never claims a workload it does not have.
#[derive(Default)]
pub struct Workload {
    pub requests: Option<Requests>,
    pub connections: Option<Connections>,
    pub consumers: Option<Consumers>,
}

impl Workload {
    /// Folds another provider's sections into these. A section nobody reported
    /// stays absent; one reported twice — a process running two servers of the
    /// same kind — is summed, with the averages weighted by the denominator each
    /// side divided by, the same rule the collector's aggregator applies across
    /// workers.
    fn merge(&mut self, other: Workload) {
        merge_requests(&mut self.requests, other.requests);
        merge_connections(&mut self.connections, other.connections);
        merge_consumers(&mut self.consumers, other.consumers);
    }
}

fn merge_requests(into: &mut Option<Requests>, other: Option<Requests>) {
    let Some(other) = other else {
        return;
    };

    let Some(current) = into else {
        *into = Some(other);

        return;
    };

    let completed = current.completed + other.completed;

    current.avg_ms = if completed > 0 {
        (current.avg_ms * current.completed as f64 + other.avg_ms * other.completed as f64)
            / completed as f64
    } else {
        0.0
    };

    current.completed = completed;
    current.in_flight += other.in_flight;
    current.in_flight_1_to_5s += other.in_flight_1_to_5s;
    current.in_flight_5_to_15s += other.in_flight_5_to_15s;
    current.in_flight_over_15s += other.in_flight_over_15s;
}

fn merge_connections(into: &mut Option<Connections>, other: Option<Connections>) {
    let Some(other) = other else {
        return;
    };

    let Some(current) = into else {
        *into = Some(other);

        return;
    };

    current.active += other.active;
    current.total_accepted += other.total_accepted;
}

fn merge_consumers(into: &mut Option<Consumers>, other: Option<Consumers>) {
    let Some(other) = other else {
        return;
    };

    let Some(current) = into else {
        *into = Some(other);

        return;
    };

    let timed = current.timed + other.timed;

    current.avg_ms = if timed > 0 {
        (current.avg_ms * current.timed as f64 + other.avg_ms * other.timed as f64) / timed as f64
    } else {
        0.0
    };

    current.timed = timed;
    current.coroutines += other.coroutines;
    current.delivered += other.delivered;
    current.acked += other.acked;
    current.refused += other.refused;
    current.in_flight += other.in_flight;
    current.in_flight_1_to_5s += other.in_flight_1_to_5s;
    current.in_flight_5_to_15s += other.in_flight_5_to_15s;
    current.in_flight_over_15s += other.in_flight_over_15s;
}

/// Yields the current feature-specific counters at snapshot time.
pub trait WorkloadProvider: Send + Sync {
    fn workload_snapshot(&self) -> Workload;
}

/// One worker's statistics, pushed as the `s` field of a snapshot frame.
///
/// `updated_at_ms` is this worker's own epoch-ms stamp at sample time — purely
/// informational, since the collector ages a snapshot from its own receipt time,
/// which is immune to clock skew between the two. `started_at_ms` is the serve
/// loop's start, which the collector renders as a UTC datetime.
#[derive(Serialize)]
struct Snapshot {
    name: String,
    pid: i64,
    #[serde(rename = "updatedAtMs")]
    updated_at_ms: i64,
    #[serde(rename = "startedAtMs")]
    started_at_ms: i64,
    #[serde(rename = "uptimeSeconds")]
    uptime_seconds: f64,
    memory: Memory,
    #[serde(rename = "cpuPercent")]
    cpu_percent: f64,
    /// The live task count of the async runtime: how much work the worker
    /// currently has in flight below PHP.
    #[serde(rename = "runtimeTasks")]
    runtime_tasks: i64,
    #[serde(skip_serializing_if = "Option::is_none")]
    requests: Option<Requests>,
    #[serde(skip_serializing_if = "Option::is_none")]
    connections: Option<Connections>,
    #[serde(skip_serializing_if = "Option::is_none")]
    consumers: Option<Consumers>,
}

#[derive(Serialize)]
struct SnapshotFrame<'a> {
    t: &'a str,
    s: Snapshot,
}

/// The `WorkloadProvider`s registered in this process, and the one push loop that
/// reads them.
///
/// A process gets a single loop and therefore a single collector connection: the
/// collector counts connections, not pids. The first registration decides where
/// to push, under which name and how often, and fixes the start the snapshots
/// report — every provider here belongs to the same worker, and its telemetry
/// settings come from the same environment the master injected.
struct Registry {
    providers: Vec<(u64, Arc<dyn WorkloadProvider>)>,
    next_id: u64,
    /// Cancels the push loop; `None` while no provider is registered.
    loop_stop: Option<CancellationToken>,
}

static REGISTRY: Mutex<Registry> = Mutex::new(Registry {
    providers: Vec::new(),
    next_id: 0,
    loop_stop: None,
});

/// A registration in the process-wide push loop.
///
/// There is no `stop()`: dropping the pusher unregisters its provider, and the
/// last one out cancels the loop. The pusher is owned by the server's registry
/// entry, so it goes exactly when the server does. An explicit `stop()` would
/// need guarding against a second call, which ownership makes unnecessary here.
pub struct Pusher {
    /// The registration to withdraw on drop; `None` for a pusher that registered
    /// nothing (push is off).
    id: Option<u64>,
}

impl Pusher {
    /// A pusher that pushes nothing: the server has no collector to report to.
    /// It exists so a server state holds a `Pusher` either way and nothing has to
    /// branch on an Option to stop one.
    pub fn disabled() -> Self {
        Pusher { id: None }
    }

    /// Registers one server's or consumer's counters with the process push loop,
    /// starting the loop if this is the first registration. `name` labels the
    /// snapshot (pool scope), `socket_path` is the collector's unix socket,
    /// `interval_ms` the cadence (0 = default), `start_time` the moment this
    /// worker began working.
    ///
    /// A pusher with no socket path registers nothing: push is off, which is the
    /// ordinary case for a server started outside a master.
    pub fn start(
        name: String,
        socket_path: String,
        interval_ms: i64,
        start_time: Instant,
        provider: Arc<dyn WorkloadProvider>,
    ) -> Self {
        if socket_path.is_empty() {
            return Pusher { id: None };
        }

        let mut registry = REGISTRY.lock().unwrap();

        let id = registry.next_id;

        registry.next_id += 1;
        registry.providers.push((id, provider));

        if registry.loop_stop.is_none() {
            let stop = CancellationToken::new();

            registry.loop_stop = Some(stop.clone());

            let interval = Duration::from_millis(if interval_ms > 0 {
                interval_ms as u64
            } else {
                DEFAULT_INTERVAL_MS
            });

            // The wall-clock stamp of the start is derived once, by taking the
            // monotonic start's distance from now: `Instant` cannot be turned into
            // an epoch time, and the loop must report the same value every tick.
            let started_at_ms = epoch_millis() - start_time.elapsed().as_millis() as i64;

            crate::core::get().runtime().spawn(async move {
                run(name, socket_path, interval, start_time, started_at_ms, stop).await;
            });
        }

        Pusher { id: Some(id) }
    }
}

impl Drop for Pusher {
    fn drop(&mut self) {
        let Some(id) = self.id else {
            return;
        };

        let mut registry = REGISTRY.lock().unwrap();

        registry.providers.retain(|(other, _)| *other != id);

        if registry.providers.is_empty() {
            // Nothing left to report: end the loop, which closes the connection —
            // and that is how the collector learns this worker is gone.
            if let Some(stop) = registry.loop_stop.take() {
                stop.cancel();
            }
        }
    }
}

/// The sections of every provider registered right now, merged into one.
///
/// The registry lock is released before any provider is asked: a provider takes a
/// lock of its own, and holding both would order two unrelated locks against each
/// other for no reason.
fn collect_workload() -> Workload {
    let providers: Vec<Arc<dyn WorkloadProvider>> = REGISTRY
        .lock()
        .unwrap()
        .providers
        .iter()
        .map(|(_, provider)| provider.clone())
        .collect();

    let mut merged = Workload::default();

    for provider in providers {
        merged.merge(provider.workload_snapshot());
    }

    merged
}

async fn run(
    name: String,
    socket_path: String,
    interval: Duration,
    start_time: Instant,
    started_at_ms: i64,
    stop: CancellationToken,
) {
    let mut cpu = metrics::CpuSampler::new();
    let mut connection: Option<UnixStream> = None;

    // interval_at, not interval: tokio's first tick fires immediately, while a
    // ticker waits out a period. A snapshot pushed before the server has served
    // anything is not wrong, but the cadence would be off by one for the life of
    // the worker.
    let mut ticker = tokio::time::interval_at(tokio::time::Instant::now() + interval, interval);

    loop {
        tokio::select! {
            _ = stop.cancelled() => return,
            _ = ticker.tick() => {}
        }

        push_once(
            &name,
            &socket_path,
            start_time,
            started_at_ms,
            &mut cpu,
            &mut connection,
        )
        .await;
    }
}

/// Builds the current snapshot and writes one frame, dialing the collector first
/// when not yet connected. Any write failure drops the connection so the next
/// tick redials; the frame itself is lost — best-effort, at most once.
async fn push_once(
    name: &str,
    socket_path: &str,
    start_time: Instant,
    started_at_ms: i64,
    cpu: &mut metrics::CpuSampler,
    connection: &mut Option<UnixStream>,
) {
    if connection.is_none() {
        match tokio::time::timeout(PUSH_WRITE_TIMEOUT, UnixStream::connect(socket_path)).await {
            Ok(Ok(stream)) => *connection = Some(stream),
            // The collector is not there (yet). Nothing is logged: a worker
            // started outside a master would say so every second forever.
            _ => return,
        }
    }

    let workload = collect_workload();
    let now = Instant::now();

    let snapshot = Snapshot {
        name: name.to_string(),
        pid: std::process::id() as i64,
        updated_at_ms: epoch_millis(),
        started_at_ms,
        uptime_seconds: now.duration_since(start_time).as_secs_f64(),
        memory: metrics::read_memory(),
        cpu_percent: cpu.sample(now),
        runtime_tasks: crate::core::get().runtime().metrics().num_alive_tasks() as i64,
        requests: workload.requests,
        connections: workload.connections,
        consumers: workload.consumers,
    };

    let Ok(body) = serde_json::to_vec(&SnapshotFrame {
        t: "snapshot",
        s: snapshot,
    }) else {
        return;
    };

    let stream = connection.as_mut().expect("connected just above");

    let written = tokio::time::timeout(PUSH_WRITE_TIMEOUT, write_frame(stream, &body)).await;

    if !matches!(written, Ok(Ok(()))) {
        *connection = None;
    }
}

fn epoch_millis() -> i64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|since| since.as_millis() as i64)
        .unwrap_or(0)
}

/// The workload provider both connection servers use.
///
/// One type for both servers, rather than a declaration in each of
/// socketserver, once in wsserver — because the two are in different packages
/// and the type is unexported. The counters are identical, and so is the section
/// they fill.
#[derive(Default)]
pub struct ConnectionStats {
    active: std::sync::atomic::AtomicI64,
    total_accepted: std::sync::atomic::AtomicI64,
}

impl ConnectionStats {
    pub fn new() -> Self {
        ConnectionStats::default()
    }

    /// Records a connection that has just been accepted (socket) or upgraded
    /// (WebSocket), and answers a guard that records its close.
    ///
    /// A guard rather than a matching call, because a connection task can be
    /// dropped mid-flight — the client hung up, the flow was cancelled — and a
    /// close that only ran on the normal path would leave the active count
    /// drifting upwards for the life of the worker.
    pub fn opened(self: &Arc<Self>) -> ConnectionGuard {
        self.active
            .fetch_add(1, std::sync::atomic::Ordering::Relaxed);
        self.total_accepted
            .fetch_add(1, std::sync::atomic::Ordering::Relaxed);

        ConnectionGuard {
            stats: self.clone(),
        }
    }
}

impl WorkloadProvider for ConnectionStats {
    fn workload_snapshot(&self) -> Workload {
        Workload {
            requests: None,
            consumers: None,
            connections: Some(Connections {
                active: self.active.load(std::sync::atomic::Ordering::Relaxed),
                total_accepted: self
                    .total_accepted
                    .load(std::sync::atomic::Ordering::Relaxed),
            }),
        }
    }
}

pub struct ConnectionGuard {
    stats: Arc<ConnectionStats>,
}

impl Drop for ConnectionGuard {
    fn drop(&mut self) {
        self.stats
            .active
            .fetch_sub(1, std::sync::atomic::Ordering::Relaxed);
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The case that made a worker count twice: one process serving WebSocket
    /// connections and consuming a queue reports both sections in one snapshot,
    /// instead of dialing the collector once per feature.
    #[test]
    fn merges_disjoint_sections_of_one_process() {
        let mut merged = Workload {
            connections: Some(Connections {
                active: 1,
                total_accepted: 7,
            }),
            ..Workload::default()
        };

        merged.merge(Workload {
            consumers: Some(Consumers {
                delivered: 20,
                acked: 20,
                ..Consumers::default()
            }),
            ..Workload::default()
        });

        let connections = merged.connections.expect("connections kept");

        assert_eq!(1, connections.active);
        assert_eq!(7, connections.total_accepted);

        let consumers = merged.consumers.expect("consumers added");

        assert_eq!(20, consumers.delivered);
        assert_eq!(20, consumers.acked);

        assert!(merged.requests.is_none());
    }

    /// A section nobody reported stays absent, so a snapshot never claims a
    /// workload the process does not have.
    #[test]
    fn keeps_an_unreported_section_absent() {
        let mut merged = Workload::default();

        merged.merge(Workload::default());

        assert!(merged.requests.is_none());
        assert!(merged.connections.is_none());
        assert!(merged.consumers.is_none());
    }

    /// Two servers of the same kind in one process sum, and the average follows
    /// the count each side divided by rather than the plain mean of the two.
    #[test]
    fn weights_the_request_average_by_completed() {
        let mut merged = Workload {
            requests: Some(Requests {
                completed: 10,
                avg_ms: 1.0,
                in_flight: 2,
                ..Requests::default()
            }),
            ..Workload::default()
        };

        merged.merge(Workload {
            requests: Some(Requests {
                completed: 30,
                avg_ms: 5.0,
                in_flight: 1,
                ..Requests::default()
            }),
            ..Workload::default()
        });

        let requests = merged.requests.expect("requests merged");

        assert_eq!(40, requests.completed);
        assert_eq!(3, requests.in_flight);
        assert!((requests.avg_ms - 4.0).abs() < f64::EPSILON);
    }

    /// The consumer average is weighted by the deliveries each side actually
    /// timed — the denominator it divided by.
    #[test]
    fn weights_the_consumer_average_by_timed() {
        let mut merged = Workload {
            consumers: Some(Consumers {
                timed: 1,
                avg_ms: 10.0,
                coroutines: 2,
                ..Consumers::default()
            }),
            ..Workload::default()
        };

        merged.merge(Workload {
            consumers: Some(Consumers {
                timed: 3,
                avg_ms: 2.0,
                coroutines: 4,
                ..Consumers::default()
            }),
            ..Workload::default()
        });

        let consumers = merged.consumers.expect("consumers merged");

        assert_eq!(4, consumers.timed);
        assert_eq!(6, consumers.coroutines);
        assert!((consumers.avg_ms - 4.0).abs() < f64::EPSILON);
    }

    /// Nothing settled anywhere: the average has no denominator and must not
    /// come out as a division by zero.
    #[test]
    fn leaves_the_average_at_zero_without_a_denominator() {
        let mut merged = Workload {
            requests: Some(Requests::default()),
            ..Workload::default()
        };

        merged.merge(Workload {
            requests: Some(Requests::default()),
            ..Workload::default()
        });

        assert_eq!(0.0, merged.requests.expect("requests merged").avg_ms);
    }
}
