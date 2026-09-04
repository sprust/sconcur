//! A WebSocket load generator: the counterpart of `wrk`, which speaks HTTP only.
//!
//! It opens N persistent connections, each doing back-to-back request/reply
//! round-trips for a fixed duration, and reports sustained throughput with
//! latency percentiles. Run it inside the `php` container, pinned to the load
//! cores, against the local ws-server pool — `tests/benchmarks/ws/load-stats.sh`
//! builds and drives it.
//!
//!   ws-load -url ws://127.0.0.1:18090/ -conns 256 -duration 20 -msg all
//!
//! The flags are single-dash on purpose: this replaces a Go tool of the same
//! name, and the harness that calls it was written against that spelling.
//!
//! Latencies go into a fixed histogram (0.1 ms per bucket, up to ~10 s) rather
//! than a vector of samples: at a few hundred thousand round-trips the vector is
//! the biggest allocation in the process, and a percentile does not need it.

use std::sync::Arc;
use std::sync::atomic::{AtomicI64, Ordering};
use std::time::{Duration, Instant};

use fastwebsockets::{Frame, OpCode, Payload, handshake};
use http_body_util::Empty;
use hyper::Request;
use hyper::body::Bytes;
use hyper_util::rt::{TokioExecutor, TokioIo};

/// 0.1 ms per bucket, up to ~10 s.
const BUCKET_MICROS: u64 = 100;
const BUCKET_COUNT: usize = 100_000;

/// One round-trip's budget. Generous: a server under load is slow, not broken,
/// and a timeout here would be recorded as an error and skew the run.
const OP_TIMEOUT: Duration = Duration::from_secs(30);

/// What the run counts, shared by every connection task.
struct Counters {
    round_trips: AtomicI64,
    errors: AtomicI64,
    histogram: Vec<AtomicI64>,
}

impl Counters {
    fn new() -> Self {
        Counters {
            round_trips: AtomicI64::new(0),
            errors: AtomicI64::new(0),
            histogram: (0..BUCKET_COUNT).map(|_| AtomicI64::new(0)).collect(),
        }
    }

    fn record(&self, elapsed: Duration) {
        let bucket = (elapsed.as_micros() as u64 / BUCKET_MICROS) as usize;

        self.histogram[bucket.min(BUCKET_COUNT - 1)].fetch_add(1, Ordering::Relaxed);
    }
}

struct Options {
    url: String,
    connections: usize,
    duration: Duration,
    message: String,
}

/// `-name value` pairs, the spelling the harness uses. An unknown flag is an
/// error rather than a default: a benchmark run started with a typo would
/// otherwise report numbers for something else.
fn parse_options() -> std::result::Result<Options, String> {
    let mut options = Options {
        url: "ws://127.0.0.1:18090/".to_string(),
        connections: 256,
        duration: Duration::from_secs(20),
        message: "ping".to_string(),
    };

    let arguments: Vec<String> = std::env::args().skip(1).collect();
    let mut index = 0;

    while index < arguments.len() {
        let name = arguments[index].as_str();
        let value = arguments
            .get(index + 1)
            .ok_or_else(|| format!("{name} needs a value"))?;

        match name {
            "-url" => options.url = value.clone(),
            "-conns" => {
                options.connections = value.parse().map_err(|_| format!("-conns: {value}"))?
            }
            "-duration" => {
                let seconds: u64 = value.parse().map_err(|_| format!("-duration: {value}"))?;

                options.duration = Duration::from_secs(seconds);
            }
            "-msg" => options.message = value.clone(),
            other => return Err(format!("unknown flag {other}")),
        }

        index += 2;
    }

    Ok(options)
}

/// Splits `ws://host:port/path` into what the dial and the handshake need.
fn split_url(url: &str) -> std::result::Result<(String, String), String> {
    let rest = url
        .strip_prefix("ws://")
        .ok_or_else(|| format!("only ws:// is supported, got {url}"))?;

    let (authority, path) = match rest.find('/') {
        Some(at) => (&rest[..at], &rest[at..]),
        None => (rest, "/"),
    };

    if authority.is_empty() {
        return Err(format!("no host in {url}"));
    }

    Ok((authority.to_string(), path.to_string()))
}

async fn connect(
    authority: &str,
    path: &str,
) -> std::result::Result<fastwebsockets::WebSocket<TokioIo<hyper::upgrade::Upgraded>>, String> {
    let stream = tokio::net::TcpStream::connect(authority)
        .await
        .map_err(|error| error.to_string())?;

    let _ = stream.set_nodelay(true);

    let request = Request::builder()
        .method("GET")
        .uri(path)
        .header("Host", authority)
        .header(hyper::header::UPGRADE, "websocket")
        .header(hyper::header::CONNECTION, "upgrade")
        .header("Sec-WebSocket-Key", handshake::generate_key())
        .header("Sec-WebSocket-Version", "13")
        .body(Empty::<Bytes>::new())
        .map_err(|error| error.to_string())?;

    let (websocket, _) = handshake::client(&TokioExecutor::new(), request, stream)
        .await
        .map_err(|error| error.to_string())?;

    Ok(websocket)
}

/// Drives one connection: back-to-back round-trips until the deadline,
/// reconnecting on any error, which is what makes a server that drops
/// connections show up as errors rather than as a stalled run.
async fn run_connection(
    authority: String,
    path: String,
    message: String,
    deadline: Instant,
    counters: Arc<Counters>,
) {
    while Instant::now() < deadline {
        let mut websocket = match connect(&authority, &path).await {
            Ok(websocket) => websocket,
            Err(_) => {
                counters.errors.fetch_add(1, Ordering::Relaxed);
                tokio::time::sleep(Duration::from_millis(10)).await;

                continue;
            }
        };

        websocket.set_auto_close(true);
        websocket.set_auto_pong(true);

        while Instant::now() < deadline {
            let started = Instant::now();

            match round_trip(&mut websocket, &message).await {
                Ok(()) => {
                    counters.record(started.elapsed());
                    counters.round_trips.fetch_add(1, Ordering::Relaxed);
                }
                Err(()) => {
                    counters.errors.fetch_add(1, Ordering::Relaxed);

                    break;
                }
            }
        }
    }
}

/// One message out, one message back. Control frames are handled by the library
/// (auto-pong), so anything that arrives here is the reply being waited for.
async fn round_trip(
    websocket: &mut fastwebsockets::WebSocket<TokioIo<hyper::upgrade::Upgraded>>,
    message: &str,
) -> std::result::Result<(), ()> {
    let frame = Frame::text(Payload::Borrowed(message.as_bytes()));

    match tokio::time::timeout(OP_TIMEOUT, websocket.write_frame(frame)).await {
        Ok(Ok(())) => {}
        _ => return Err(()),
    }

    loop {
        let frame = match tokio::time::timeout(OP_TIMEOUT, websocket.read_frame()).await {
            Ok(Ok(frame)) => frame,
            _ => return Err(()),
        };

        match frame.opcode {
            OpCode::Text | OpCode::Binary => return Ok(()),
            OpCode::Close => return Err(()),
            _ => continue,
        }
    }
}

fn percentile_ms(histogram: &[AtomicI64], total: i64, quantile: f64) -> f64 {
    if total == 0 {
        return 0.0;
    }

    let threshold = (quantile * total as f64) as i64;
    let mut cumulative = 0;

    for (bucket, count) in histogram.iter().enumerate() {
        cumulative += count.load(Ordering::Relaxed);

        if cumulative >= threshold {
            return bucket as f64 * BUCKET_MICROS as f64 / 1000.0;
        }
    }

    max_ms(histogram)
}

fn max_ms(histogram: &[AtomicI64]) -> f64 {
    for bucket in (0..histogram.len()).rev() {
        if histogram[bucket].load(Ordering::Relaxed) > 0 {
            return bucket as f64 * BUCKET_MICROS as f64 / 1000.0;
        }
    }

    0.0
}

#[tokio::main]
async fn main() {
    let options = match parse_options() {
        Ok(options) => options,
        Err(error) => {
            eprintln!("ws-load: {error}");
            eprintln!("usage: ws-load -url ws://host:port/ -conns N -duration S -msg NAME");

            std::process::exit(2);
        }
    };

    let (authority, path) = match split_url(&options.url) {
        Ok(parts) => parts,
        Err(error) => {
            eprintln!("ws-load: {error}");

            std::process::exit(2);
        }
    };

    let counters = Arc::new(Counters::new());
    let deadline = Instant::now() + options.duration;
    let started = Instant::now();

    let mut connections = Vec::with_capacity(options.connections);

    for _ in 0..options.connections {
        connections.push(tokio::spawn(run_connection(
            authority.clone(),
            path.clone(),
            options.message.clone(),
            deadline,
            counters.clone(),
        )));
    }

    for connection in connections {
        let _ = connection.await;
    }

    let elapsed = started.elapsed();
    let total = counters.round_trips.load(Ordering::Relaxed);
    let per_second = total as f64 / elapsed.as_secs_f64();

    println!("connections   : {}", options.connections);
    println!("duration      : {:.1}s", elapsed.as_secs_f64());
    println!("message       : {:?}", options.message);
    println!(
        "round-trips   : {total}  ({} errors)",
        counters.errors.load(Ordering::Relaxed)
    );
    println!("throughput    : {per_second:.0} rt/s");
    println!(
        "latency       : p50 {:.1} ms · p90 {:.1} ms · p99 {:.1} ms · max {:.1} ms",
        percentile_ms(&counters.histogram, total, 0.50),
        percentile_ms(&counters.histogram, total, 0.90),
        percentile_ms(&counters.histogram, total, 0.99),
        max_ms(&counters.histogram),
    );
}
