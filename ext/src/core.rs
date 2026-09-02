//! Process-wide state, and the one thing the Go build cannot do: surviving a
//! `fork`.
//!
//! Go starts its runtime inside `dlopen`, so by the time PHP's `MINIT` runs
//! there are already threads in the process — which is why the library forbids
//! `pcntl_fork` after the extension is loaded, and why FPM and mod_php are out.
//! Nothing here starts until the first push, so a process that loads the
//! extension and forks without using it hands each child a clean slate.
//!
//! A fork *after* first use is the harder case and is handled explicitly: only
//! the forking thread survives into the child, so the inherited runtime is a
//! shell with no worker threads behind it. A `pthread_atfork` child handler
//! flags that, and the next access rebuilds everything.
//!
//! The old state is **leaked, never dropped**: dropping a `tokio::Runtime`
//! joins its worker threads, and in the child those threads do not exist — the
//! join would block forever. Leaking is correct here, not sloppy: the child's
//! copy-on-write image already holds those pages, and the process either
//! rebuilds and runs, or exits.

use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Once, RwLock};

use crate::features::amqp;
use crate::features::httpclient;
use crate::features::httpserver;
use crate::features::mongodb;
use crate::features::socketclient;
use crate::features::socketserver;
use crate::features::sql;
use crate::features::wsclient;
use crate::features::wsserver;
use crate::handler::Handler;

pub struct Core {
    runtime: tokio::runtime::Runtime,
    /// Behind a lock only because `destroy()` replaces it; every export takes an
    /// uncontended read (PHP is NTS — one thread issues them all).
    handler: RwLock<Arc<Handler>>,
    /// The HTTP feature's registries live here rather than in their own statics
    /// so a fork throws them away with everything else, instead of leaving the
    /// child holding a map of the parent's connections behind a mutex that may
    /// have been locked at the moment of the fork.
    http: httpserver::HttpRegistries,
    /// The SQL feature's pools and live transactions, here for the same reason:
    /// a pool handle and an open transaction belong to the process that made
    /// them, and a child must start with neither.
    sql: sql::Registries,
    /// The MongoDB clients, here for the same reason: a driver topology
    /// belongs to the process that opened it.
    mongodb: mongodb::Registries,
    /// The socket server's connections and listeners, for the same reason.
    socketserver: socketserver::Registries,
    /// The WebSocket server's connections and listeners, for the same reason.
    wsserver: wsserver::Registries,
    /// The socket client's dialed connections, for the same reason.
    socketclient: socketclient::Registries,
    /// The WebSocket client's dialed connections, for the same reason.
    wsclient: wsclient::Registries,
    /// The HTTP client's pooled clients and open uploads, for the same reason.
    httpclient: httpclient::Registries,
    /// The AMQP connections, channels and supervised delivery streams, for the
    /// same reason: a socket to the broker and the channels multiplexed over it
    /// belong to the process that opened them.
    amqp: amqp::Registries,
}

static CORE: RwLock<Option<&'static Core>> = RwLock::new(None);

/// Set by the `pthread_atfork` child handler. Checked with a relaxed load on
/// every access — one atomic against a crossing measured in microseconds.
static FORKED_IN_CHILD: AtomicBool = AtomicBool::new(false);

static REGISTER_ATFORK: Once = Once::new();

/// Runs in the child right after `fork`, where only async-signal-safe work is
/// allowed. A single relaxed store qualifies; the rebuild itself happens later,
/// on the child's next access, in ordinary code.
extern "C" fn on_fork_in_child() {
    FORKED_IN_CHILD.store(true, Ordering::Relaxed);
}

pub fn get() -> &'static Core {
    if !FORKED_IN_CHILD.load(Ordering::Relaxed) {
        if let Some(core) = *CORE.read().unwrap() {
            return core;
        }
    }

    build()
}

#[cold]
fn build() -> &'static Core {
    let mut slot = CORE.write().unwrap();

    // Racing callers: the first one through clears the flag and rebuilds, the
    // rest find the fresh core already in the slot.
    if FORKED_IN_CHILD.swap(false, Ordering::Relaxed) {
        // Drop the reference, not the value. See the module comment.
        *slot = None;
    }

    if let Some(core) = *slot {
        return core;
    }

    // Registered once per process image. A child inherits the registration, so
    // a fork of a fork is covered without registering again — and `Once` is
    // inherited already-completed, which is exactly the behaviour wanted.
    REGISTER_ATFORK.call_once(|| unsafe {
        libc::pthread_atfork(None, None, Some(on_fork_in_child));
    });

    let core: &'static Core = Box::leak(Box::new(Core::new()));

    *slot = Some(core);

    core
}

impl Core {
    fn new() -> Self {
        // One thread, because one process per core is the deployment model: the
        // worker master starts as many workers as there are cores, and the PHP
        // side of each is a single thread anyway.
        //
        // This used to follow `available_parallelism`, mirroring how Go derives
        // GOMAXPROCS from the affinity mask. That only gave the right answer
        // under `taskset`, which the benchmark harness does and the worker
        // master does not — so the number was right in every measurement and
        // wrong in production, where an N-core box ran N processes of N threads
        // each.
        //
        // Measured on the empty endpoint, unpinned, 12 workers: +4.2% rps at
        // -8.6% CPU per request. Neutral where the runtime has real work to
        // schedule — /all (six DB operations per request) and a single process
        // decoding fifty 1 MiB results at once both land inside the noise.
        //
        // SCONCUR_RUNTIME_THREADS raises it, which is what a single long-lived
        // process wants if it expects the extension to use more than one core.
        let worker_threads = std::env::var("SCONCUR_RUNTIME_THREADS")
            .ok()
            .and_then(|value| value.parse::<usize>().ok())
            .unwrap_or(1)
            .max(1);

        let runtime = tokio::runtime::Builder::new_multi_thread()
            .worker_threads(worker_threads)
            .enable_all()
            .thread_name("sconcur")
            .build()
            .expect("sconcur: failed to build the async runtime");

        Core {
            runtime,
            handler: RwLock::new(Arc::new(Handler::new())),
            http: httpserver::HttpRegistries::new(),
            sql: sql::Registries::new(),
            mongodb: mongodb::Registries::new(),
            socketserver: socketserver::Registries::new(),
            wsserver: wsserver::Registries::new(),
            socketclient: socketclient::Registries::new(),
            wsclient: wsclient::Registries::new(),
            httpclient: httpclient::Registries::new(),
            amqp: amqp::Registries::new(),
        }
    }

    pub fn runtime(&'static self) -> &'static tokio::runtime::Runtime {
        &self.runtime
    }

    pub fn handler(&'static self) -> Arc<Handler> {
        self.handler.read().unwrap().clone()
    }

    pub fn http(&'static self) -> &'static httpserver::HttpRegistries {
        &self.http
    }

    pub fn sql(&'static self) -> &'static sql::Registries {
        &self.sql
    }

    pub fn mongodb(&'static self) -> &'static mongodb::Registries {
        &self.mongodb
    }

    pub fn socketserver(&'static self) -> &'static socketserver::Registries {
        &self.socketserver
    }

    pub fn wsserver(&'static self) -> &'static wsserver::Registries {
        &self.wsserver
    }

    pub fn socketclient(&'static self) -> &'static socketclient::Registries {
        &self.socketclient
    }

    pub fn wsclient(&'static self) -> &'static wsclient::Registries {
        &self.wsclient
    }

    pub fn httpclient(&'static self) -> &'static httpclient::Registries {
        &self.httpclient
    }

    pub fn amqp(&'static self) -> &'static amqp::Registries {
        &self.amqp
    }

    /// Mirrors Handler.fresh(): the destroyed handler is dropped and a new one
    /// takes its place, so the next push starts from a clean registry and a
    /// fresh results channel. The runtime is kept — `destroy()` ends the work,
    /// not the process.
    pub fn replace_handler(&'static self) {
        *self.handler.write().unwrap() = Arc::new(Handler::new());
    }
}
