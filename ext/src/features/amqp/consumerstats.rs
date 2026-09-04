//! The worker's
//! queue-consumer telemetry.
//!
//! Read off the traffic that already crosses the boundary rather than reported
//! separately: a delivery is counted when it is handed to PHP, and settled when
//! the acknowledgement or the refusal comes back as an ordinary command. "The
//! job is done, or it is not" is `basic.ack` against `basic.nack`, and both
//! already travel.

use std::collections::HashMap;
use std::sync::{Arc, Mutex, OnceLock};
use std::time::{Duration, Instant};

use crate::stats::{self, Consumers, Workload, WorkloadProvider};

/// The buckets a delivery in flight falls into, by how long PHP has been
/// holding it. Exclusive, and the same thresholds the HTTP server uses, so a
/// panel reads the two sections the same way.
const IN_FLIGHT_WARN_AFTER: Duration = Duration::from_secs(1);
const IN_FLIGHT_SLOW_AFTER: Duration = Duration::from_secs(5);
const IN_FLIGHT_STUCK_AFTER: Duration = Duration::from_secs(15);

const TELEMETRY_NAME_ENV: &str = "SCONCUR_SERVER_NAME";
const TELEMETRY_SOCKET_ENV: &str = "SCONCUR_TELEMETRY_SOCKET";
const TELEMETRY_INTERVAL_ENV: &str = "SCONCUR_TELEMETRY_INTERVAL_MS";

/// Labels the snapshots of a worker nobody named — the same default the servers
/// carry in their constructor.
const DEFAULT_POOL_NAME: &str = "sconcur-server";

/// Identifies one unsettled delivery. A tag is only unique within its channel,
/// so the channel is part of the key.
type DeliveryKey = (String, u64);

/// Identifies one open consumer, for the same reason.
type ConsumerKey = (String, String);

#[derive(Default)]
struct Counters {
    delivered: i64,
    acked: i64,
    refused: i64,

    settled_count: i64,
    settled_total_ms: f64,

    in_flight: HashMap<DeliveryKey, Instant>,

    /// The consumers this worker has open, one per coroutine. A set rather than
    /// a counter because a consumer can be closed by either its own cleanup or
    /// the death of its channel, and both must be able to run.
    live: HashMap<ConsumerKey, ()>,
}

pub struct ConsumerStats {
    counters: Mutex<Counters>,
}

/// Built eagerly, unlike the registries around it: it is two empty maps and no
/// task, and every channel close reports into it whether or not this process
/// ever consumed.
///
/// An `Arc` rather than a plain static because the pusher takes its provider as
/// one, and it is the same instance either way.
static INSTANCE: OnceLock<Arc<ConsumerStats>> = OnceLock::new();

pub fn stats() -> &'static Arc<ConsumerStats> {
    INSTANCE.get_or_init(|| {
        Arc::new(ConsumerStats {
            counters: Mutex::new(Counters::default()),
        })
    })
}

/// Guards the one pusher this process runs. `None` until a consumer opens.
static PUSHER: Mutex<Option<stats::Pusher>> = Mutex::new(None);

/// Brings up the worker's pusher the first time a consumer opens, and never
/// again — one pusher per process, like one per server. The collector address
/// and the pool name come from the environment the master gives every worker,
/// so nothing about this crosses the PHP boundary.
pub fn start_telemetry() {
    let mut slot = PUSHER.lock().unwrap();

    if slot.is_some() {
        return;
    }

    let socket_path = std::env::var(TELEMETRY_SOCKET_ENV).unwrap_or_default();

    if socket_path.is_empty() {
        return;
    }

    let interval_ms = std::env::var(TELEMETRY_INTERVAL_ENV)
        .ok()
        .and_then(|value| value.parse::<i64>().ok())
        .unwrap_or(0);

    let mut name = std::env::var(TELEMETRY_NAME_ENV).unwrap_or_default();

    // A snapshot with no name is dropped by the collector without a word, so a
    // worker started outside a master — which sets the label — would push every
    // interval and never appear. The servers default theirs the same way.
    if name.is_empty() {
        name = DEFAULT_POOL_NAME.to_string();
    }

    // The start time is this call: the worker begins consuming here, which is
    // what the serve-loop start means for a server.
    *slot = Some(stats::Pusher::start(
        name,
        socket_path,
        interval_ms,
        Instant::now(),
        stats().clone(),
    ));
}

/// Ends the push loop; called from the feature's shutdown. Dropping the pusher
/// is what stops it — it owns a cancellation token and cancels on drop. A
/// pusher stopped here can be started again: `destroy()` leaves the handler
/// usable, and a consumer opened after it must report like any other.
pub fn stop_telemetry() {
    PUSHER.lock().unwrap().take();
}

impl ConsumerStats {
    /// Records a coroutine that started consuming.
    pub fn consumer_opened(&self, channel_id: &str, consumer_tag: &str) {
        let mut counters = self.counters.lock().unwrap();

        counters
            .live
            .insert((channel_id.to_string(), consumer_tag.to_string()), ());
    }

    /// Records one that stopped, whichever of the two paths got there first.
    pub fn consumer_closed(&self, channel_id: &str, consumer_tag: &str) {
        let mut counters = self.counters.lock().unwrap();

        counters
            .live
            .remove(&(channel_id.to_string(), consumer_tag.to_string()));
    }

    /// Records a delivery on its way to PHP. An auto-acknowledged one is
    /// settled on the spot: no acknowledgement will ever come back for it, and
    /// leaving it in flight would grow the map for the life of the process.
    pub fn delivery_dispatched(&self, channel_id: &str, delivery_tag: u64, auto_ack: bool) {
        let mut counters = self.counters.lock().unwrap();

        counters.delivered += 1;

        if auto_ack {
            // No acknowledgement will come back, so there is no handler time to
            // measure: counted as settled, left out of the average it would
            // otherwise pull to zero.
            counters.acked += 1;

            return;
        }

        counters
            .in_flight
            .insert((channel_id.to_string(), delivery_tag), Instant::now());
    }

    /// Records the acknowledgement or the refusal of a delivery.
    pub fn delivery_settled(
        &self,
        channel_id: &str,
        delivery_tag: u64,
        multiple: bool,
        acked: bool,
    ) {
        let mut counters = self.counters.lock().unwrap();

        let mut settled = 0i64;

        // "Up to and including this tag" settles every earlier delivery of that
        // channel too, which is the whole point of a multiple ack. The counters
        // follow deliveries, not commands: one multiple-ack of a hundred
        // messages is a hundred settled, and counting it as one would have the
        // panel report 99% of them unacknowledged.
        if multiple {
            let keys: Vec<DeliveryKey> = counters
                .in_flight
                .keys()
                .filter(|(channel, tag)| channel == channel_id && *tag <= delivery_tag)
                .cloned()
                .collect();

            for key in keys {
                if let Some(started_at) = counters.in_flight.remove(&key) {
                    record_settled(&mut counters, started_at.elapsed());

                    settled += 1;
                }
            }
        } else if let Some(started_at) = counters
            .in_flight
            .remove(&(channel_id.to_string(), delivery_tag))
        {
            record_settled(&mut counters, started_at.elapsed());

            settled = 1;
        }

        // A tag this worker never handed out — a message pulled with basic.get,
        // or one settled twice — still settled something as far as the broker is
        // concerned.
        if settled == 0 {
            settled = 1;
        }

        if acked {
            counters.acked += settled;
        } else {
            counters.refused += settled;
        }
    }

    /// Drops whatever a dead channel was still holding. A handler that threw
    /// without settling its message leaves it here, and the broker has taken it
    /// back — so keeping it in flight would only inflate the number forever.
    pub fn channel_gone(&self, channel_id: &str) {
        let mut counters = self.counters.lock().unwrap();

        counters.in_flight.retain(|(channel, _), _| channel != channel_id);
        counters.live.retain(|(channel, _), _| channel != channel_id);
    }
}

fn record_settled(counters: &mut Counters, took: Duration) {
    counters.settled_count += 1;
    counters.settled_total_ms += took.as_secs_f64() * 1000.0;
}

impl WorkloadProvider for ConsumerStats {
    /// A worker that has consumed nothing reports no section at all, so a
    /// snapshot never claims a workload it does not have.
    fn workload_snapshot(&self) -> Workload {
        let counters = self.counters.lock().unwrap();

        if counters.delivered == 0
            && counters.acked == 0
            && counters.refused == 0
            && counters.in_flight.is_empty()
            && counters.live.is_empty()
        {
            return Workload::default();
        }

        let mut consumers = Consumers {
            coroutines: counters.live.len() as i64,
            delivered: counters.delivered,
            acked: counters.acked,
            refused: counters.refused,
            timed: counters.settled_count,
            in_flight: counters.in_flight.len() as i64,
            avg_ms: 0.0,
            in_flight_1_to_5s: 0,
            in_flight_5_to_15s: 0,
            in_flight_over_15s: 0,
        };

        if counters.settled_count > 0 {
            consumers.avg_ms = counters.settled_total_ms / counters.settled_count as f64;
        }

        for started_at in counters.in_flight.values() {
            let age = started_at.elapsed();

            if age >= IN_FLIGHT_STUCK_AFTER {
                consumers.in_flight_over_15s += 1;
            } else if age >= IN_FLIGHT_SLOW_AFTER {
                consumers.in_flight_5_to_15s += 1;
            } else if age >= IN_FLIGHT_WARN_AFTER {
                consumers.in_flight_1_to_5s += 1;
            }
        }

        Workload {
            requests: None,
            connections: None,
            consumers: Some(consumers),
        }
    }
}
