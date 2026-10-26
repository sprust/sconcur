//! The connection pools, one per DSN, shared by every task of both drivers.
//!
//! One pool per driver+DSN+sizing, refcounted so an idle unreferenced pool can
//! be swept while in-flight work keeps it alive. sqlx's pool is itself the
//! connection pool; what this adds is
//! the sharing between tasks and the sweep.

use sqlx::{AssertSqlSafe, Executor};
use std::collections::HashMap;
use std::sync::Mutex;
use std::sync::atomic::{AtomicBool, Ordering};
use std::time::{Duration, Instant};

use super::dsn;

const POOL_IDLE_TTL: Duration = Duration::from_secs(5 * 60);
const POOL_SWEEP_INTERVAL: Duration = Duration::from_secs(60);

/// The cap applied when the caller asks for none. See `build`.
const DEFAULT_MAX_CONNECTIONS: u32 = 32;

/// The driver behind a pool, selected by
/// name; the two concrete pool types make that an enum here.
#[derive(Clone)]
pub enum Driver {
    Mysql(sqlx::MySqlPool),
    Pgsql(sqlx::PgPool),
}

/// Identifies a pool by driver, DSN and sizing — the comparable key used to
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
    /// Whether the idle sweeper runs. On the pools rather than in a static, so a
    /// child forked after the parent used them — and given fresh pools with the
    /// fresh core — starts a sweeper of its own on its own runtime.
    sweeping: AtomicBool,
}

impl Pools {
    pub fn new() -> Self {
        Pools {
            entries: Mutex::new(HashMap::new()),
            sweeping: AtomicBool::new(false),
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

impl Pools {
    /// Starts the idle sweeper the first time it is asked to. Called once the
    /// runtime exists (a spawn needs one), not from the constructor.
    pub fn start_sweeper(&'static self) {
        if self.sweeping.swap(true, Ordering::SeqCst) {
            return;
        }

        tokio::spawn(async move {
            let mut ticker = tokio::time::interval(POOL_SWEEP_INTERVAL);

            loop {
                ticker.tick().await;

                self.sweep();
            }
        });
    }

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
        // down when the last clone goes, where an explicit, timeout-bounded
        // Close because a *sql.DB is not reference-counted that way.
        drop(drained);
    }
}

/// One `SET` carrying every session variable the DSN named, so a new connection
/// costs one round trip however many there are.
///
/// The names were held to `[A-Za-z0-9_.]` when the DSN was parsed, and the
/// values travel exactly as the DSN wrote them — quoted there where quoting is
/// needed, which is what this format asks of whoever writes the DSN and what the
/// Go client does with the same string.
fn mysql_session_statement(variables: &[(String, String)]) -> String {
    let assignments = variables
        .iter()
        .map(|(name, value)| format!("{name}={value}"))
        .collect::<Vec<_>>()
        .join(", ");

    format!("SET {assignments}")
}

fn build(
    driver_name: &'static str,
    dsn: &str,
    max_open_conns: i64,
    max_idle_conns: i64,
    conn_max_lifetime_ms: i64,
) -> std::result::Result<Driver, String> {
    // The pool starts empty and grows on demand: the floor is zero, which is
    // sqlx's own default and is stated below so the pool does not rest on it.
    let max_connections = if max_open_conns > 0 {
        max_open_conns as u32
    } else {
        // A deliberate divergence, and the one place this feature cannot match
        // the historical default: the pool used to be unbounded, while
        // sqlx requires a maximum and sizes its slot table from it — asking for
        // u32::MAX asks the allocator for ~200 GiB. Callers that care set
        // maxOpenConns (every benchmark route and the demo server do); this is
        // the stand-in for the ones that do not.
        DEFAULT_MAX_CONNECTIONS
    };

    // max_idle_conns has no counterpart: sqlx puts no cap on idle connections.
    // It keeps them up to the maximum and closes each one that has sat idle for
    // its idle timeout (10 minutes by default, left as it is), so the value is
    // accepted and unused. The same defaults give up on an acquire, opening a
    // connection included, after 30 seconds — begin() included, which nothing
    // else bounds; docs/mysql.md describes both.
    let _ = max_idle_conns;

    let max_lifetime = if conn_max_lifetime_ms > 0 {
        Some(Duration::from_millis(conn_max_lifetime_ms as u64))
    } else {
        None
    };

    match driver_name {
        "mysql" => {
            let parsed = dsn::mysql_dsn(dsn)?;

            let mut pool_options = sqlx::mysql::MySqlPoolOptions::new()
                .max_connections(max_connections)
                .min_connections(0)
                .max_lifetime(max_lifetime);

            // The DSN's session variables, in the one place they can be issued:
            // sqlx sends its own SET while connecting, so these have to land
            // after it, on every connection the pool opens rather than once.
            // This is what makes `?transaction_isolation=…` and the rest of the
            // format's system-variable parameters mean something — see dsn.rs.
            if !parsed.session_variables.is_empty() {
                let statement = mysql_session_statement(&parsed.session_variables);

                pool_options = pool_options.after_connect(move |connection, _meta| {
                    let statement = statement.clone();

                    Box::pin(async move {
                        connection.execute(AssertSqlSafe(statement)).await?;

                        Ok(())
                    })
                });
            }

            Ok(Driver::Mysql(pool_options.connect_lazy_with(parsed.options)))
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
