//! Mirrors ext-go-legacy/internal/features/sql/pools.go.
//!
//! One pool per driver+DSN+sizing, refcounted so an idle unreferenced pool can
//! be swept while in-flight work keeps it alive. sqlx's pool is itself the
//! connection pool, exactly as `*sql.DB` is on the Go side; what this adds is
//! the sharing between tasks and the sweep.

use std::collections::HashMap;
use std::sync::{Mutex, OnceLock};
use std::time::{Duration, Instant};

use super::dsn;

const POOL_IDLE_TTL: Duration = Duration::from_secs(5 * 60);
const POOL_SWEEP_INTERVAL: Duration = Duration::from_secs(60);

/// The cap applied when the caller asks for none. See `build`.
const DEFAULT_MAX_CONNECTIONS: u32 = 32;

/// The driver behind a pool. Go selects a registered database/sql driver by
/// name; the two concrete pool types make that an enum here.
#[derive(Clone)]
pub enum Driver {
    Mysql(sqlx::MySqlPool),
    Pgsql(sqlx::PgPool),
}

/// Identifies a pool by driver, DSN and sizing — the comparable key Go uses to
/// avoid formatting a string on every acquire.
#[derive(PartialEq, Eq, Hash, Clone)]
struct PoolKey {
    driver_name: &'static str,
    dsn: String,
    max_open_conns: i64,
    max_idle_conns: i64,
    conn_max_lifetime_ms: i64,
}

struct Entry {
    driver: Driver,
    in_use: i64,
    last_used_at: Instant,
}

pub struct Pools {
    entries: Mutex<HashMap<PoolKey, Entry>>,
}

impl Pools {
    pub fn new() -> Self {
        Pools {
            entries: Mutex::new(HashMap::new()),
        }
    }
}

/// A held pool. Dropping it releases the owner count, so an early return cannot
/// leak a reference the way a missed `release` call would.
pub struct Acquired {
    key: PoolKey,
    driver: Driver,
}

impl Acquired {
    pub fn driver(&self) -> &Driver {
        &self.driver
    }
}

impl Drop for Acquired {
    fn drop(&mut self) {
        get().release(&self.key);
    }
}

/// The pools live on the Core, like every other process-wide registry here, so
/// a fork discards them: a child that inherited the parent's entries would be
/// holding pool handles whose connections belong to another process.
pub fn get() -> &'static Pools {
    crate::core::get().sql().pools()
}

/// Starts the idle sweeper. Called once the runtime exists (a spawn needs one),
/// not from the registry's constructor.
pub fn start_sweeper() {
    static STARTED: OnceLock<()> = OnceLock::new();

    STARTED.get_or_init(|| {
        tokio::spawn(async {
            let mut ticker = tokio::time::interval(POOL_SWEEP_INTERVAL);

            loop {
                ticker.tick().await;

                get().sweep();
            }
        });
    });
}

impl Pools {
    /// Returns the pool for driver+dsn+sizing, opening it on first use, and
    /// marks it held. Like `sql.Open`, this does not connect: the first query
    /// connects under its own deadline, so a connect timeout still applies.
    pub async fn acquire(
        &'static self,
        driver_name: &'static str,
        dsn: &str,
        max_open_conns: i64,
        max_idle_conns: i64,
        conn_max_lifetime_ms: i64,
    ) -> std::result::Result<Acquired, String> {
        let key = PoolKey {
            driver_name,
            dsn: dsn.to_string(),
            max_open_conns,
            max_idle_conns,
            conn_max_lifetime_ms,
        };

        if let Some(entry) = self.entries.lock().unwrap().get_mut(&key) {
            entry.in_use += 1;
            entry.last_used_at = Instant::now();

            return Ok(Acquired {
                key: key.clone(),
                driver: entry.driver.clone(),
            });
        }

        let driver = build(driver_name, dsn, max_open_conns, max_idle_conns, conn_max_lifetime_ms)?;

        let mut entries = self.entries.lock().unwrap();

        // Another task may have opened the same pool while this one was building
        // its own; keep the one already in the map and drop the newcomer.
        let entry = entries.entry(key.clone()).or_insert_with(|| Entry {
            driver,
            in_use: 0,
            last_used_at: Instant::now(),
        });

        entry.in_use += 1;
        entry.last_used_at = Instant::now();

        Ok(Acquired {
            key: key.clone(),
            driver: entry.driver.clone(),
        })
    }

    fn release(&self, key: &PoolKey) {
        if let Some(entry) = self.entries.lock().unwrap().get_mut(key) {
            if entry.in_use > 0 {
                entry.in_use -= 1;
            }

            entry.last_used_at = Instant::now();
        }
    }

    /// Removes idle, unreferenced pools. The removed handles are closed by their
    /// own Drop once the last clone goes, so nothing is closed under the lock.
    fn sweep(&self) {
        let now = Instant::now();

        self.entries
            .lock()
            .unwrap()
            .retain(|_, entry| entry.in_use > 0 || now.duration_since(entry.last_used_at) <= POOL_IDLE_TTL);
    }

    /// Mirrors CloseAllPools: called on extension shutdown.
    pub fn close_all(&self) {
        let drained: Vec<Driver> = self
            .entries
            .lock()
            .unwrap()
            .drain()
            .map(|(_, entry)| entry.driver)
            .collect();

        // Dropping the handle is the close: sqlx tears the pool's connections
        // down when the last clone goes. Go needs an explicit, timeout-bounded
        // Close because a *sql.DB is not reference-counted that way.
        drop(drained);
    }
}

fn build(
    driver_name: &'static str,
    dsn: &str,
    max_open_conns: i64,
    max_idle_conns: i64,
    conn_max_lifetime_ms: i64,
) -> std::result::Result<Driver, String> {
    // sqlx wants a minimum too, and defaults it to the maximum — which would
    // hold open as many connections as the cap allows for the life of the
    // process. Go's pool starts empty and grows, so the floor is zero here.
    let max_connections = if max_open_conns > 0 {
        max_open_conns as u32
    } else {
        // A deliberate divergence, and the one place this feature cannot match
        // Go exactly: database/sql leaves the pool unbounded by default, while
        // sqlx requires a maximum and sizes its slot table from it — asking for
        // u32::MAX asks the allocator for ~200 GiB. Callers that care set
        // maxOpenConns (every benchmark route and the demo server do); this is
        // the stand-in for the ones that do not.
        DEFAULT_MAX_CONNECTIONS
    };

    // Go's pool starts empty and grows on demand; sqlx would otherwise default
    // its minimum to the maximum and hold that many connections open for the
    // life of the process. max_idle_conns has no direct counterpart — sqlx keeps
    // every idle connection up to the maximum — so it is accepted and unused.
    let _ = max_idle_conns;

    let max_lifetime = if conn_max_lifetime_ms > 0 {
        Some(Duration::from_millis(conn_max_lifetime_ms as u64))
    } else {
        None
    };

    match driver_name {
        "mysql" => {
            let options = dsn::mysql_options(dsn)?;

            let pool = sqlx::mysql::MySqlPoolOptions::new()
                .max_connections(max_connections)
                .min_connections(0)
                .max_lifetime(max_lifetime)
                .connect_lazy_with(options);

            Ok(Driver::Mysql(pool))
        }
        _ => {
            let options: sqlx::postgres::PgConnectOptions =
                dsn.parse().map_err(|error: sqlx::Error| error.to_string())?;

            let pool = sqlx::postgres::PgPoolOptions::new()
                .max_connections(max_connections)
                .min_connections(0)
                .max_lifetime(max_lifetime)
                .connect_lazy_with(options);

            Ok(Driver::Pgsql(pool))
        }
    }
}
