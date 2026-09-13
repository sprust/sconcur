//! The connections behind the feature: a small pool of multiplexed ones per
//! DSN for ordinary commands, and dedicated ones for the commands that cannot
//! share a socket.
//!
//! Redis serves one connection strictly in order, so what a connection is used
//! for decides how it may be shared:
//!
//! - an ordinary command shares a multiplexed connection with every other task,
//!   which is where the feature's whole gain comes from — thirty GETs from
//!   thirty coroutines go out as one pipeline and come back in one round trip;
//! - a blocking command (BLPOP, XREAD BLOCK, WAIT) holds the server's attention
//!   for as long as it waits, so on a shared socket it would stall every command
//!   queued behind it. It gets a connection of its own for the call;
//! - a subscription owns its connection outright, because the protocol puts the
//!   connection itself into subscriber mode.

use std::collections::HashMap;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Mutex, OnceLock};
use std::time::{Duration, Instant};

use redis::aio::{ConnectionManager, ConnectionManagerConfig, MultiplexedConnection};
use redis::{AsyncConnectionConfig, Client};

use super::dsn;
use super::errors::Kind;

const POOL_IDLE_TTL: Duration = Duration::from_secs(5 * 60);
const POOL_SWEEP_INTERVAL: Duration = Duration::from_secs(60);

/// The pool size applied when the caller asks for none. One multiplexed
/// connection already carries any number of concurrent commands; more than one
/// exists so a single large value in flight does not hold up everything queued
/// behind it on the same socket.
const DEFAULT_POOL_SIZE: usize = 4;

/// The ceiling on what a caller may ask for. A pool is per DSN and per process,
/// and a worker pool multiplies it by the worker count on the server's side.
const MAX_POOL_SIZE: usize = 64;

/// How long dialling the server may take. Only the dial: everything after it is
/// bounded by the payload's own deadline, which is the number the caller set.
///
/// It exists because the driver's reconnect loop runs behind the commands, with
/// nobody's deadline over it — an attempt against a host that swallows packets
/// has to end by itself or the loop never gets to the next one. The driver's own
/// default is one second, which is a local-network number.
const CONNECT_TIMEOUT: Duration = Duration::from_secs(5);

/// How many connections one dsn may hold for blocking commands at once. A
/// blocking command parks a whole socket for as long as it waits, so without a
/// ceiling a hundred coroutines waiting on a queue are a hundred sockets, and
/// the server's `maxclients` is what finds out.
const MAX_DEDICATED_PER_DSN: usize = 64;

/// How many of those are kept for the next command instead of being closed.
/// Reuse is what keeps a consumer loop from paying a TCP handshake, an AUTH and
/// a SELECT on every iteration.
const MAX_IDLE_DEDICATED_PER_DSN: usize = 8;

/// How long an idle dedicated connection may be lent again. A socket nobody has
/// used for longer than this is dropped rather than handed to a command that
/// would then fail on it — a blocking command failing because it was given a
/// dead socket is a failure the caller cannot do anything about.
const DEDICATED_IDLE_TTL: Duration = Duration::from_secs(30);

/// How many times the manager re-dials before giving the failure to the caller.
///
/// The driver's own default is six, with a backoff that reaches six seconds — and
/// a connection that cannot be made is usually one that will not be: a wrong
/// password, a closed port, a wrong host. Six retries turn that into a deadline
/// firing on a command, which tells the caller nothing about what is wrong.
/// Two keeps a genuinely transient drop covered while a real failure still
/// arrives as itself, inside any sensible deadline.
const CONNECT_RETRIES: usize = 2;

/// The ceiling on the backoff between those retries, for the same reason.
const CONNECT_RETRY_MAX_DELAY: Duration = Duration::from_millis(200);

/// The driver's per-command timeout, turned off.
///
/// redis-rs defaults it to 500 ms, and on this feature that is simply wrong: a
/// blocking command waits for minutes by design, and an ordinary one against a
/// busy server or a large value can take longer than half a second. The deadline
/// here is the payload's `to`, applied by the feature, and there must not be a
/// second one underneath it that nobody asked for.
const RESPONSE_TIMEOUT: Option<Duration> = None;

/// Identifies a pool by everything that changes what its connections are.
#[derive(PartialEq, Eq, Hash, Clone)]
struct PoolKey {
    dsn: String,
    pool_size: usize,
    conn_max_lifetime_ms: i64,
}

struct Entry {
    connections: Vec<ConnectionManager>,
    /// Round-robin cursor. Which connection a command lands on does not matter
    /// beyond spreading them, so this is a counter rather than a load measure.
    next_index: usize,
    opened_at: Instant,
    in_use: i64,
    last_used_at: Instant,
    /// Tells this pool from the one that replaces it under the same key. A
    /// command that started on the old pool must not be counted off the new one.
    generation: u64,
}

/// Hands out the generations. Process-wide and monotonic: a number is never
/// reused, so an old pool's release can always be recognised as one.
static GENERATION: AtomicU64 = AtomicU64::new(1);

/// The connections held for blocking commands on one dsn: the ones parked for
/// reuse, and how many exist at all.
struct DedicatedPool {
    idle: Vec<(MultiplexedConnection, Instant)>,
    live: usize,
}

impl DedicatedPool {
    /// Drops the parked connections nobody has wanted for the idle ttl, and
    /// counts them out of `live` — they existed, and now they do not. Forgetting
    /// that second half is a slot leaked per expiry, which a consumer running
    /// once a minute reaches the ceiling with in about an hour.
    fn drop_stale(&mut self) {
        let now = Instant::now();
        let before = self.idle.len();

        self.idle
            .retain(|(_, returned_at)| now.duration_since(*returned_at) <= DEDICATED_IDLE_TTL);

        self.live = self.live.saturating_sub(before - self.idle.len());
    }
}

pub struct Pools {
    entries: Mutex<HashMap<PoolKey, Entry>>,
    dedicated: Mutex<HashMap<String, DedicatedPool>>,
}

impl Pools {
    pub fn new() -> Self {
        Pools {
            entries: Mutex::new(HashMap::new()),
            dedicated: Mutex::new(HashMap::new()),
        }
    }
}

/// A borrowed multiplexed connection. Dropping it releases the pool's owner
/// count, so an early return cannot leak a reference the way a missed release
/// call would.
pub struct Acquired {
    key: PoolKey,
    connection: ConnectionManager,
    /// The pool this was taken from. The one under that key may be a later one by
    /// the time this is given back.
    generation: u64,
}

impl Acquired {
    /// The connection to issue commands on. Cloned rather than borrowed:
    /// redis-rs wants `&mut`, and a ConnectionManager clone is a handle to the
    /// same multiplexed socket.
    pub fn connection(&self) -> ConnectionManager {
        self.connection.clone()
    }
}

impl Drop for Acquired {
    fn drop(&mut self) {
        get().release(&self.key, self.generation);
    }
}

/// How long dialling may take, for the paths that have to apply it themselves —
/// the pub/sub connection, which the driver opens without taking any
/// configuration.
pub fn connect_timeout() -> Duration {
    CONNECT_TIMEOUT
}

/// A connection lent to one blocking command, and the slot it occupies in the
/// dsn's ceiling.
///
/// It is created *before* the dial, holding no connection yet, because the slot
/// has to be given back however the call ends — and one of the ways it ends is
/// the deadline firing mid-dial, which drops this future without running another
/// line of it. That was the shape of the bug this comment replaces: the count
/// was taken under the lock and given back only on a dial that returned, so a
/// cancelled dial leaked a slot and sixty-four of them killed the dsn.
///
/// `reusable` starts false for the same reason. A connection is lent on only
/// after a command the server answered — well or badly — has left it clean; one
/// cut off by a deadline or a stop is still owed a reply, and lending it on
/// would hand somebody else that reply. Nothing sets the flag on the paths that
/// end by being dropped, which is what makes "false unless proven clean" the
/// only safe default.
///
/// The same rule the AMQP feature applies to a channel handed back dirty.
pub struct Dedicated {
    dsn: String,
    connection: Option<MultiplexedConnection>,
    reusable: bool,
}

impl Dedicated {
    pub fn connection(&mut self) -> &mut MultiplexedConnection {
        self.connection
            .as_mut()
            .expect("a lent connection is held until it is dropped")
    }

    /// Keeps the connection for the next command when this one finished on the
    /// wire. A failure that is not the server's answer — a dropped socket, a
    /// deadline, a stop — leaves it dirty, and it is dropped instead.
    ///
    /// Kind::Command means exactly "the server answered": values::classify_error
    /// gives it only to an error carrying a code, which is the first token of an
    /// error reply. Nothing whose framing is in doubt wears it, so nothing whose
    /// framing is in doubt is lent on.
    pub fn keep_if_clean<T>(&mut self, outcome: &std::result::Result<T, (Kind, String)>) {
        self.reusable = match outcome {
            Ok(_) => true,
            Err((Kind::Command, _)) => true,
            Err(_) => false,
        };
    }
}

impl Drop for Dedicated {
    fn drop(&mut self) {
        let connection = self.connection.take();

        get().release_dedicated(&self.dsn, connection.filter(|_| self.reusable));
    }
}

/// The pools live on the Core, like every other process-wide registry here, so
/// a fork discards them: a child that inherited the parent's entries would be
/// writing into sockets that belong to another process.
pub fn get() -> &'static Pools {
    crate::core::get().redis().pools()
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
                get().sweep_dedicated();
            }
        });
    });
}

impl Pools {
    /// Returns one of the pool's multiplexed connections, opening the pool on
    /// first use, and marks it held. Like sql's pools this does not connect:
    /// the manager is lazy and the first command dials under its own deadline.
    pub fn acquire(
        &'static self,
        dsn: &str,
        pool_size: i64,
        conn_max_lifetime_ms: i64,
    ) -> Result<Acquired, String> {
        let key = PoolKey {
            dsn: dsn.to_string(),
            pool_size: normalize_pool_size(pool_size),
            conn_max_lifetime_ms,
        };

        let mut entries = self.entries.lock().unwrap();

        // A lifetime cap is honoured by dropping the pool and opening a new one:
        // the manager holds the socket, so retiring one connection means
        // replacing the handle that owns it.
        //
        // It goes whether or not commands are still on it. Waiting for in_use to
        // reach zero is waiting for a moment that never comes under continuous
        // traffic, or while one cursor holds its connection for the length of a
        // walk — and the setting then reads as honoured while nothing ever retires
        // anything. The commands already running keep the manager they took, and
        // their generation is what stops them counting themselves off the new pool.
        if let Some(entry) = entries.get(&key) {
            if is_expired(entry, conn_max_lifetime_ms) {
                entries.remove(&key);
            }
        }

        if !entries.contains_key(&key) {
            let entry = build(&key)?;

            entries.insert(key.clone(), entry);
        }

        let entry = entries.get_mut(&key).expect("the entry was just inserted");

        let connection = entry.connections[entry.next_index % entry.connections.len()].clone();

        entry.next_index = entry.next_index.wrapping_add(1);
        entry.in_use += 1;
        entry.last_used_at = Instant::now();

        let generation = entry.generation;

        Ok(Acquired {
            key,
            connection,
            generation,
        })
    }

    /// Lends a connection nothing else will use for the length of one blocking
    /// command. Taken from the parked ones when there is a fresh one, opened
    /// otherwise, and refused once this dsn is holding as many as it may.
    ///
    /// The failure carries its kind, because the first thing this does is parse
    /// the dsn: a blocking command against an unusable dsn has to raise what
    /// every other path raises for it, and reporting the lot as a connection
    /// failure made `blPop` the one call where a typo in the scheme arrived as a
    /// RuntimeException rather than the usage mistake it is.
    pub async fn dedicated(&'static self, dsn: &str) -> std::result::Result<Dedicated, (Kind, String)> {
        let parked = {
            let mut pools = self.dedicated.lock().unwrap();

            let pool = pools.entry(dsn.to_string()).or_insert_with(|| DedicatedPool {
                idle: Vec::new(),
                live: 0,
            });

            pool.drop_stale();

            match pool.idle.pop() {
                Some((connection, _)) => Some(connection),
                None => {
                    if pool.live >= MAX_DEDICATED_PER_DSN {
                        return Err((
                            Kind::Connection,
                            format!(
                                "this connection is already holding {MAX_DEDICATED_PER_DSN} connections for blocking commands"
                            ),
                        ));
                    }

                    pool.live += 1;

                    None
                }
            }
        };

        // From here the slot belongs to this handle: every way out of this
        // function, the `?` operators and being dropped mid-dial included, runs
        // its Drop and gives the slot back.
        let mut dedicated = Dedicated {
            dsn: dsn.to_string(),
            connection: parked,
            reusable: false,
        };

        if dedicated.connection.is_some() {
            return Ok(dedicated);
        }

        let client = self.client(dsn).map_err(|error| (Kind::Dsn, error))?;

        let config = AsyncConnectionConfig::new()
            .set_response_timeout(RESPONSE_TIMEOUT)
            .set_connection_timeout(Some(CONNECT_TIMEOUT));

        let connection = client
            .get_multiplexed_async_connection_with_config(&config)
            .await
            .map_err(|error| (Kind::Connection, format!("connect: {error}")))?;

        dedicated.connection = Some(connection);

        Ok(dedicated)
    }

    /// Takes a lent connection back: parked for the next command when it is
    /// still clean and there is room, forgotten otherwise.
    fn release_dedicated(&self, dsn: &str, connection: Option<MultiplexedConnection>) {
        let mut pools = self.dedicated.lock().unwrap();

        let Some(pool) = pools.get_mut(dsn) else {
            return;
        };

        match connection {
            Some(connection) if pool.idle.len() < MAX_IDLE_DEDICATED_PER_DSN => {
                pool.idle.push((connection, Instant::now()));
            }
            _ => {
                if pool.live > 0 {
                    pool.live -= 1;
                }
            }
        }
    }

    /// The client for a DSN, which is what opens a dedicated connection or a
    /// subscription. Cheap to build — it holds the parsed connection info, not
    /// a socket — so it is not cached.
    pub fn client(&'static self, dsn: &str) -> Result<Client, String> {
        let parsed = dsn::parse(dsn)?;

        Client::open(parsed.url.as_str()).map_err(|error| format!("open client: {error}"))
    }

    fn release(&self, key: &PoolKey, generation: u64) {
        if let Some(entry) = self.entries.lock().unwrap().get_mut(key) {
            // The pool under this key may be a later one: a lifetime cap retires a
            // pool while its commands are still running. Counting an old pool's
            // command off the new one leaves that pool permanently short, and with
            // in_use never reaching zero it would never be swept either.
            if entry.generation != generation {
                return;
            }

            if entry.in_use > 0 {
                entry.in_use -= 1;
            }

            entry.last_used_at = Instant::now();
        }
    }

    /// Removes idle, unreferenced pools. The removed managers close their
    /// sockets in their own Drop once the last clone goes, so nothing is closed
    /// under the lock.
    fn sweep(&self) {
        let now = Instant::now();

        self.entries.lock().unwrap().retain(|key, entry| {
            // The other half of the lifetime cap. Retiring on acquire alone leaves a
            // pool whose traffic stopped exactly as it aged out open for ever, and a
            // pool nobody acquires from is precisely the one no acquire can retire.
            if is_expired(entry, key.conn_max_lifetime_ms) {
                return false;
            }

            entry.in_use > 0 || now.duration_since(entry.last_used_at) <= POOL_IDLE_TTL
        });
    }

    /// Drops the parked connections of every dsn that have aged out. Without it
    /// the last eight of a dsn stay open for the life of the process once the
    /// traffic stops, which is not what "closed five minutes after its last
    /// command" says.
    fn sweep_dedicated(&self) {
        let mut pools = self.dedicated.lock().unwrap();

        for pool in pools.values_mut() {
            pool.drop_stale();
        }

        pools.retain(|_, pool| pool.live > 0 || !pool.idle.is_empty());
    }

    /// Called on extension shutdown.
    pub fn close_all(&self) {
        let drained: Vec<Entry> = self
            .entries
            .lock()
            .unwrap()
            .drain()
            .map(|(_, entry)| entry)
            .collect();

        let parked: Vec<DedicatedPool> = self
            .dedicated
            .lock()
            .unwrap()
            .drain()
            .map(|(_, pool)| pool)
            .collect();

        drop(drained);
        drop(parked);
    }
}

fn normalize_pool_size(pool_size: i64) -> usize {
    if pool_size <= 0 {
        return DEFAULT_POOL_SIZE;
    }

    (pool_size as usize).min(MAX_POOL_SIZE)
}

fn is_expired(entry: &Entry, conn_max_lifetime_ms: i64) -> bool {
    if conn_max_lifetime_ms <= 0 {
        return false;
    }

    entry.opened_at.elapsed() >= Duration::from_millis(conn_max_lifetime_ms as u64)
}

fn build(key: &PoolKey) -> Result<Entry, String> {
    let parsed = dsn::parse(&key.dsn)?;

    let client = Client::open(parsed.url.as_str()).map_err(|error| format!("open client: {error}"))?;

    let mut connections = Vec::with_capacity(key.pool_size);

    for _ in 0..key.pool_size {
        // Lazy, so building the pool does no network work: the first command
        // through it dials, and does so under its own deadline. The retry
        // numbers are the manager's own defaults; what matters here is that a
        // dropped connection is re-established behind the commands rather than
        // ending the pool.
        let config = ConnectionManagerConfig::new()
            .set_response_timeout(RESPONSE_TIMEOUT)
            .set_connection_timeout(Some(CONNECT_TIMEOUT))
            .set_number_of_retries(CONNECT_RETRIES)
            .set_max_delay(CONNECT_RETRY_MAX_DELAY);

        let connection = client
            .get_connection_manager_lazy(config)
            .map_err(|error| format!("open connection: {error}"))?;

        connections.push(connection);
    }

    Ok(Entry {
        connections,
        next_index: 0,
        opened_at: Instant::now(),
        in_use: 0,
        last_used_at: Instant::now(),
        generation: GENERATION.fetch_add(1, Ordering::Relaxed),
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    /// How many connections a dsn holds for blocking commands.
    fn live(pools: &Pools, dsn: &str) -> usize {
        pools
            .dedicated
            .lock()
            .unwrap()
            .get(dsn)
            .map(|pool| pool.live)
            .unwrap_or(0)
    }

    #[test]
    fn a_missing_pool_size_falls_back_to_the_default() {
        assert_eq!(normalize_pool_size(0), DEFAULT_POOL_SIZE);
        assert_eq!(normalize_pool_size(-3), DEFAULT_POOL_SIZE);
    }

    #[test]
    fn a_pool_size_is_capped() {
        assert_eq!(normalize_pool_size(2), 2);
        assert_eq!(normalize_pool_size(10_000), MAX_POOL_SIZE);
    }

    #[test]
    fn giving_a_dedicated_connection_back_frees_its_slot() {
        let pools = Pools::new();

        pools.dedicated.lock().unwrap().insert(
            "dsn".to_string(),
            DedicatedPool {
                idle: Vec::new(),
                live: 2,
            },
        );

        assert_eq!(live(&pools, "dsn"), 2);

        // A connection given up rather than parked — what a deadline or a stop
        // leaves behind — frees the slot instead of holding it for ever.
        pools.release_dedicated("dsn", None);

        assert_eq!(live(&pools, "dsn"), 1);

        pools.release_dedicated("dsn", None);

        assert_eq!(live(&pools, "dsn"), 0);
    }

    #[test]
    fn releasing_an_unknown_dsn_does_nothing() {
        let pools = Pools::new();

        pools.release_dedicated("never-seen", None);

        assert_eq!(live(&pools, "never-seen"), 0);
    }

    #[test]
    fn the_slot_count_never_goes_below_zero() {
        let pools = Pools::new();

        pools.dedicated.lock().unwrap().insert(
            "dsn".to_string(),
            DedicatedPool {
                idle: Vec::new(),
                live: 0,
            },
        );

        pools.release_dedicated("dsn", None);

        assert_eq!(live(&pools, "dsn"), 0);
    }

    #[test]
    fn a_pool_without_a_lifetime_never_expires() {
        let entry = Entry {
            connections: Vec::new(),
            next_index: 0,
            opened_at: Instant::now() - Duration::from_secs(3600),
            in_use: 0,
            last_used_at: Instant::now(),
            generation: 1,
        };

        assert!(!is_expired(&entry, 0));
        assert!(is_expired(&entry, 1000));
    }

    fn key(conn_max_lifetime_ms: i64) -> PoolKey {
        PoolKey {
            dsn: "redis://127.0.0.1:6379".to_string(),
            pool_size: 1,
            conn_max_lifetime_ms,
        }
    }

    fn entry(generation: u64, in_use: i64, age: Duration) -> Entry {
        Entry {
            connections: Vec::new(),
            next_index: 0,
            opened_at: Instant::now() - age,
            in_use,
            last_used_at: Instant::now(),
            generation,
        }
    }

    fn in_use(pools: &Pools, key: &PoolKey) -> i64 {
        pools
            .entries
            .lock()
            .unwrap()
            .get(key)
            .map(|entry| entry.in_use)
            .unwrap_or(0)
    }

    #[test]
    fn a_command_of_a_retired_pool_is_not_counted_off_the_one_that_replaced_it() {
        let pools = Pools::new();
        let pool_key = key(0);

        pools
            .entries
            .lock()
            .unwrap()
            .insert(pool_key.clone(), entry(7, 2, Duration::ZERO));

        // A command that started before the lifetime cap retired the old pool.
        pools.release(&pool_key, 6);

        assert_eq!(in_use(&pools, &pool_key), 2, "an older pool's command was counted off this one");

        pools.release(&pool_key, 7);

        assert_eq!(in_use(&pools, &pool_key), 1);
    }

    #[test]
    fn a_pool_past_its_lifetime_is_swept_even_with_commands_on_it() {
        // The case the old condition could not reach: under continuous traffic, or
        // while a cursor holds its connection, in_use is never zero at the moment
        // anything looks — and conn_max_lifetime_ms read as honoured while nothing
        // was ever retired.
        let pools = Pools::new();
        let pool_key = key(1000);

        pools
            .entries
            .lock()
            .unwrap()
            .insert(pool_key.clone(), entry(1, 3, Duration::from_secs(60)));

        pools.sweep();

        assert!(
            pools.entries.lock().unwrap().get(&pool_key).is_none(),
            "a pool past its lifetime stayed open because commands were still running",
        );
    }

    #[test]
    fn a_pool_inside_its_lifetime_survives_the_sweep() {
        let pools = Pools::new();
        let pool_key = key(60_000);

        pools
            .entries
            .lock()
            .unwrap()
            .insert(pool_key.clone(), entry(1, 0, Duration::from_secs(1)));

        pools.sweep();

        assert!(pools.entries.lock().unwrap().get(&pool_key).is_some());
    }
}
