//! The pool of
//! connections to the broker, and the handles PHP holds on them.
//!
//! Two `Connection` objects built with the same options share one socket, so
//! building one per request is cheap. They share it in the broker's eyes as
//! well — an exclusive queue declared through one is usable through the other —
//! which is why `connection_name` is part of the key: naming a connection is
//! how an application asks for one that is not shared.

use std::collections::HashMap;
use std::sync::atomic::{AtomicBool, AtomicI64, Ordering};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use lapin::uri::{AMQPAuthority, AMQPQueryString, AMQPScheme, AMQPUri, AMQPUserInfo};
use lapin::tcp::{OwnedIdentity, OwnedTLSConfig};
use lapin::{Connection, ConnectionProperties};

use super::payloads::ConnectParams;

/// Bounds the dial when the credentials name no connect timeout.
pub const DEFAULT_CONNECT_TIMEOUT: Duration = Duration::from_secs(10);
/// Bounds one broker method when the credentials name no rpc timeout: a task
/// must never run unbounded.
pub const DEFAULT_RPC_TIMEOUT: Duration = Duration::from_secs(30);
/// Bounds one publish when the credentials name no write timeout.
pub const DEFAULT_WRITE_TIMEOUT: Duration = Duration::from_secs(30);

/// A pooled connection nothing holds any more is closed after staying idle this
/// long, mirroring the MongoDB and SQL pools.
const CONNECTION_IDLE_TTL: Duration = Duration::from_secs(5 * 60);
const CONNECTION_SWEEP_INTERVAL: Duration = Duration::from_secs(60);

/// Mirrors `SaslMethodEnum::External`: authenticate with the TLS client
/// certificate instead of a login and a password.
const SASL_METHOD_EXTERNAL: i64 = 1;

/// How many channel numbers a connection keeps in reserve before it is retired.
/// Small, because a lost number is rare — every one of them is a channel the
/// broker refused something on — but not zero, so the swap happens while opens
/// still succeed.
const EXHAUSTION_MARGIN: u16 = 16;

/// Identifies a pooled connection. It holds what the broker sees of a
/// connection: the address, the credentials, the TLS material and everything
/// the handshake settles on.
///
/// The connect timeout is deliberately not part of it — it bounds the dial and
/// nothing beyond it, so two `Connection` objects that differ only there share
/// one connection, which is what the feature promises for the same credentials.
#[derive(Clone, PartialEq, Eq, Hash)]
pub struct ConnectionKey {
    secure: bool,
    host: String,
    port: i64,
    vhost: String,
    login: String,
    password: String,
    ca_cert_path: String,
    cert_path: String,
    key_path: String,
    verify: bool,
    sasl_method: i64,
    connection_name: String,
    channel_max: i64,
    frame_max_bytes: i64,
    heartbeat_seconds: i64,
}

impl ConnectionKey {
    fn from_params(params: &ConnectParams) -> Self {
        ConnectionKey {
            secure: params.secure,
            host: params.host.clone(),
            port: params.port,
            vhost: params.vhost.clone(),
            login: params.login.clone(),
            password: params.password.clone(),
            ca_cert_path: params.ca_cert_path.clone(),
            cert_path: params.cert_path.clone(),
            key_path: params.key_path.clone(),
            verify: params.verify,
            sasl_method: params.sasl_method,
            connection_name: params.connection_name.clone(),
            channel_max: params.channel_max,
            frame_max_bytes: params.frame_max_bytes,
            heartbeat_seconds: params.heartbeat_seconds,
        }
    }

    /// Whether to speak TLS. The flag is what the caller asked for and is
    /// decisive on its own: a connection with no certificate paths — the system
    /// trust store, or verification turned off against a development broker —
    /// is still a TLS connection, and inferring otherwise would put the login
    /// and password on the wire in the clear.
    ///
    /// The paths are still honoured for their own sake, so material named
    /// without the flag cannot be silently ignored either.
    fn secure_dial(&self) -> bool {
        self.secure
            || !self.ca_cert_path.is_empty()
            || !self.cert_path.is_empty()
            || !self.key_path.is_empty()
    }
}

/// One live connection to the broker plus the owner count that keeps it from
/// being swept while PHP still holds a handle on it.
pub struct PooledConnection {
    pub connection: Connection,
    key: ConnectionKey,
    pub max_channels: u16,
    pub max_frame_size: u32,
    pub heartbeat: u16,
    in_use: AtomicI64,
    last_used_at: Mutex<Instant>,
    /// Channel numbers this connection can no longer hand out.
    ///
    /// A channel the broker closes leaves its number unusable for the life of
    /// the connection: the driver hands the number to the next `channel.open`,
    /// and that open is answered with the error the *previous* owner died of.
    /// Nothing on the connection says so — it stays connected, the broker is
    /// fine, and every later open fails with somebody else's error.
    ///
    /// So they are counted, and a connection that has lost too many is retired
    /// (see `is_closed`). Without this the 256th broker-closed channel makes the
    /// connection permanently useless — a passive declare of a missing queue in
    /// a loop is enough to do it.
    lost_channels: AtomicI64,
}

impl PooledConnection {
    fn touch(&self) {
        *self.last_used_at.lock().unwrap() = Instant::now();
    }

    /// Records a channel number lost to a broker-side close.
    pub fn note_channel_lost(&self) {
        self.lost_channels.fetch_add(1, Ordering::SeqCst);
    }

    /// Whether the connection has burnt enough channel numbers to be worth
    /// replacing. The margin is deliberate: retiring it while numbers are still
    /// available means the swap is reported on an open that could still have
    /// succeeded, rather than as the confusing failure of one that could not.
    ///
    /// Deliberately NOT folded into `is_closed`: that answers "the socket is
    /// gone", and a channel consults it to decide whether its own failure was
    /// connection-level. An exhausted connection is still perfectly alive for
    /// every channel already open on it.
    pub fn is_exhausted(&self) -> bool {
        let budget = i64::from(self.max_channels) - i64::from(EXHAUSTION_MARGIN);

        self.lost_channels.load(Ordering::SeqCst) >= budget.max(1)
    }

    pub fn is_closed(&self) -> bool {
        !self.connection.status().connected()
    }
}

/// What one PHP `Connection` holds: a share of a pooled connection and the
/// channels opened through it.
pub struct ConnectionHandle {
    pub id: String,
    pub pooled: Arc<PooledConnection>,
    /// Numbers the channels of this handle; it only ever grows, so a closed
    /// channel never hands its number to the next one.
    channel_counter: AtomicI64,
    released: AtomicBool,
}

impl ConnectionHandle {
    /// Whether PHP has handed this handle back. The channels opened through it
    /// are closed with it, so a consumer on one of them cannot be reopened.
    pub fn is_released(&self) -> bool {
        self.released.load(Ordering::SeqCst)
    }

    pub fn next_channel_number(&self) -> i64 {
        self.channel_counter.fetch_add(1, Ordering::SeqCst) + 1
    }

    /// Whether the connection this handle rides is beyond use — the socket died,
    /// or PHP handed the handle back.
    pub fn connection_gone(&self) -> bool {
        self.is_released() || self.pooled.is_closed()
    }
}

pub struct Connections {
    pool: Mutex<HashMap<ConnectionKey, Arc<PooledConnection>>>,
    handles: Mutex<HashMap<String, Arc<ConnectionHandle>>>,
    handle_counter: AtomicI64,
    /// Tells shutdown whether this process ever opened a connection, and gates
    /// the sweeper so a process that never consumed starts no task.
    started: AtomicBool,
}

impl Connections {
    pub fn new() -> Self {
        Connections {
            pool: Mutex::new(HashMap::new()),
            handles: Mutex::new(HashMap::new()),
            handle_counter: AtomicI64::new(0),
            started: AtomicBool::new(false),
        }
    }

    pub fn ever_opened(&self) -> bool {
        self.started.load(Ordering::SeqCst)
    }

    /// Hands out a handle on a connection matching the parameters, dialing one
    /// if the pool holds none. The dial happens outside the lock, so connecting
    /// to a slow broker does not stall every other connect.
    pub async fn open(
        &'static self,
        params: &ConnectParams,
    ) -> Result<Arc<ConnectionHandle>, lapin::Error> {
        let key = ConnectionKey::from_params(params);

        let pooled = match self.take(&key) {
            Some(pooled) => pooled,
            None => {
                let connection = dial(params, &key).await?;

                self.store(key, connection)
            }
        };

        let handle = Arc::new(ConnectionHandle {
            id: format!(
                "amqp:c:{}",
                self.handle_counter.fetch_add(1, Ordering::SeqCst) + 1
            ),
            pooled,
            channel_counter: AtomicI64::new(0),
            released: AtomicBool::new(false),
        });

        self.handles
            .lock()
            .unwrap()
            .insert(handle.id.clone(), handle.clone());

        if !self.started.swap(true, Ordering::SeqCst) {
            self.start_sweeper();
        }

        Ok(handle)
    }

    /// A pooled connection for the key, already marked as held, or `None` when
    /// the pool has none that is still alive.
    fn take(&self, key: &ConnectionKey) -> Option<Arc<PooledConnection>> {
        let mut pool = self.pool.lock().unwrap();

        let pooled = pool.get(key)?.clone();

        // An exhausted connection leaves the pool the way a dead one does: it
        // still works for the channels already on it, but it cannot open new
        // ones, so handing it to the next caller would only reproduce the
        // failure. The connection itself lives until its last handle goes.
        if pooled.is_closed() || pooled.is_exhausted() {
            pool.remove(key);

            return None;
        }

        pooled.in_use.fetch_add(1, Ordering::SeqCst);
        pooled.touch();

        Some(pooled)
    }

    /// Puts a freshly dialed connection into the pool and marks it held. If
    /// another connect won the race for the same key, the newcomer is closed
    /// and the winner is used — so the pool never holds two connections for one
    /// key.
    fn store(&'static self, key: ConnectionKey, connection: Connection) -> Arc<PooledConnection> {
        let mut pool = self.pool.lock().unwrap();

        if let Some(existing) = pool.get(&key) {
            if !existing.is_closed() && !existing.is_exhausted() {
                let existing = existing.clone();

                existing.in_use.fetch_add(1, Ordering::SeqCst);
                existing.touch();

                drop(pool);

                close_connection(connection);

                return existing;
            }
        }

        let configuration = connection.configuration();

        let pooled = Arc::new(PooledConnection {
            max_channels: configuration.channel_max(),
            max_frame_size: configuration.frame_max(),
            heartbeat: configuration.heartbeat(),
            key: key.clone(),
            connection,
            in_use: AtomicI64::new(1),
            last_used_at: Mutex::new(Instant::now()),
            lost_channels: AtomicI64::new(0),
        });

        pool.insert(key, pooled.clone());

        drop(pool);

        self.watch(pooled.clone());

        pooled
    }

    /// Drops a connection from the pool as soon as the broker or the network
    /// ends it, and clears the channels that were open on it — their handles
    /// would otherwise hand out ids nothing answers to.
    fn watch(&'static self, pooled: Arc<PooledConnection>) {
        let runtime = crate::core::get().runtime();

        runtime.spawn(async move {
            // lapin reports a dead connection through its status rather than a
            // close channel, so this watches the status. The interval is the
            // sweeper's, because nothing here is latency-sensitive: a command on
            // a dead connection fails on its own, and this only tidies up.
            loop {
                tokio::time::sleep(Duration::from_secs(1)).await;

                if !pooled.is_closed() {
                    continue;
                }

                let registries = super::registries();

                {
                    let mut pool = registries.connections.pool.lock().unwrap();

                    if let Some(current) = pool.get(&pooled.key) {
                        if Arc::ptr_eq(current, &pooled) {
                            pool.remove(&pooled.key);
                        }
                    }
                }

                registries.connections.drop_channels_of(&pooled).await;

                return;
            }
        });
    }

    /// Closes every channel registered on a dead connection.
    async fn drop_channels_of(&'static self, pooled: &Arc<PooledConnection>) {
        let handles: Vec<Arc<ConnectionHandle>> = self
            .handles
            .lock()
            .unwrap()
            .values()
            .filter(|handle| Arc::ptr_eq(&handle.pooled, pooled))
            .cloned()
            .collect();

        for handle in handles {
            super::channels().drop_handle(&handle).await;
        }
    }

    pub fn find(&self, connection_id: &str) -> Option<Arc<ConnectionHandle>> {
        self.handles.lock().unwrap().get(connection_id).cloned()
    }

    /// Drops a handle: its channels are closed and the share it held on the
    /// pooled connection is given back, so the connection can be swept once
    /// nothing holds it.
    pub async fn release(&'static self, connection_id: &str) {
        let handle = self.handles.lock().unwrap().remove(connection_id);

        let Some(handle) = handle else {
            return;
        };

        handle.released.store(true, Ordering::SeqCst);

        super::channels().drop_handle(&handle).await;

        if handle.pooled.in_use.load(Ordering::SeqCst) > 0 {
            handle.pooled.in_use.fetch_sub(1, Ordering::SeqCst);
        }

        handle.pooled.touch();

        // A retired connection is out of the pool, and the sweeper only walks the
        // pool — so the last handle to let go is the only thing left that can
        // close it. Without this the socket stays open on the broker for the life
        // of the process, one per retirement.
        if handle.pooled.is_exhausted() && handle.pooled.in_use.load(Ordering::SeqCst) == 0 {
            close_connection_owned(handle.pooled.clone());
        }
    }

    fn start_sweeper(&'static self) {
        crate::core::get().runtime().spawn(async move {
            // Never stopped: the sweeper runs for the life of the process.
            loop {
                tokio::time::sleep(CONNECTION_SWEEP_INTERVAL).await;

                for pooled in self.collect_expired() {
                    close_connection_owned(pooled);
                }
            }
        });
    }

    /// Removes and returns the pooled connections nothing holds any more and
    /// nothing has touched for longer than the TTL. Closing is left to the
    /// caller, outside the lock.
    fn collect_expired(&self) -> Vec<Arc<PooledConnection>> {
        let mut pool = self.pool.lock().unwrap();

        let now = Instant::now();

        let expired: Vec<ConnectionKey> = pool
            .iter()
            .filter(|(_, pooled)| {
                pooled.in_use.load(Ordering::SeqCst) == 0
                    && now.duration_since(*pooled.last_used_at.lock().unwrap())
                        > CONNECTION_IDLE_TTL
            })
            .map(|(key, _)| key.clone())
            .collect();

        expired
            .into_iter()
            .filter_map(|key| pool.remove(&key))
            .collect()
    }

    /// Tears down every connection and channel: called from the feature's
    /// shutdown.
    pub async fn close_all(&'static self) {
        let handles: Vec<Arc<ConnectionHandle>> = {
            let mut registry = self.handles.lock().unwrap();
            let handles = registry.values().cloned().collect();

            registry.clear();

            handles
        };

        for handle in handles {
            super::channels().drop_handle(&handle).await;
        }

        let pooled: Vec<Arc<PooledConnection>> = {
            let mut pool = self.pool.lock().unwrap();
            let pooled = pool.values().cloned().collect();

            pool.clear();

            pooled
        };

        for connection in pooled {
            close_connection_owned(connection);
        }
    }
}

/// Opens one connection, bounded by the connect timeout. Abandoning a late
/// arrival needs nothing of its own: the dial is a future, and a timeout drops
/// it.
async fn dial(params: &ConnectParams, key: &ConnectionKey) -> Result<Connection, lapin::Error> {
    let timeout = ms_or_default(params.connect_timeout_ms, DEFAULT_CONNECT_TIMEOUT);

    let uri = connection_uri(key, params);

    let mut properties = ConnectionProperties::default();

    if !params.connection_name.is_empty() {
        properties = properties.with_connection_name(params.connection_name.clone().into());
    }

    let tls = match tls_config(key) {
        Ok(tls) => tls,
        Err(reason) => return Err(dial_error(reason)),
    };

    let runtime = match lapin::runtime::default_runtime() {
        Ok(runtime) => runtime,
        Err(error) => return Err(error),
    };

    let secure = key.secure_dial();

    let dialing = Connection::connect_uri_with_config(uri, properties, tls, runtime);

    match tokio::time::timeout(timeout, dialing).await {
        Ok(Ok(connection)) => Ok(connection),
        Ok(Err(error)) if secure => Err(dial_error(format!("tls dial failed: {error}"))),
        Ok(Err(error)) => Err(error),
        // A TLS dial that ran out the clock is worth naming as one. Pointed at
        // a plaintext listener it is exactly what happens — the broker waits
        // for the AMQP protocol header while the client waits for a
        // ServerHello, and neither side says anything — and reporting it as a
        // bare timeout would read as an unreachable broker rather than as the
        // handshake that never happened.
        Err(_) if secure => Err(dial_error(format!(
            "tls handshake did not complete within {} ms",
            timeout.as_millis()
        ))),
        Err(_) => Err(dial_error(format!(
            "dial timed out after {} ms",
            timeout.as_millis()
        ))),
    }
}

/// A failure of the dial itself, in the shape the classifier reads as a dead
/// connection.
fn dial_error(reason: String) -> lapin::Error {
    lapin::ErrorKind::IOError(Arc::new(std::io::Error::other(reason))).into()
}

/// The TLS material, read here in the worker's own process — so the paths are
/// the ones that process sees.
///
/// One thing this refuses rather than pretends to do: `verify: false`. The driver's TLS layer takes a certificate chain
/// and a client identity, and has no switch for accepting a certificate it
/// cannot check; silently verifying anyway would fail confusingly against the
/// self-signed broker the option exists for, and silently skipping is not
/// something to do behind an operator's back.
fn tls_config(key: &ConnectionKey) -> Result<OwnedTLSConfig, String> {
    if !key.secure_dial() {
        return Ok(OwnedTLSConfig::default());
    }

    if !key.verify {
        return Err(
            "verify=false is not supported by this core: it cannot accept a broker \
             certificate it does not check"
                .to_string(),
        );
    }

    let cert_chain = if key.ca_cert_path.is_empty() {
        None
    } else {
        Some(
            std::fs::read_to_string(&key.ca_cert_path)
                .map_err(|error| format!("could not read the CA certificate {}: {error}", key.ca_cert_path))?,
        )
    };

    // The two go together: naming one without the other fails the dial.
    let identity = if key.cert_path.is_empty() && key.key_path.is_empty() {
        None
    } else {
        let pem = std::fs::read(&key.cert_path)
            .map_err(|error| format!("could not read the client certificate {}: {error}", key.cert_path))?;
        let private_key = std::fs::read(&key.key_path)
            .map_err(|error| format!("could not read the client key {}: {error}", key.key_path))?;

        Some(OwnedIdentity::PKCS8 {
            pem,
            key: private_key,
        })
    };

    Ok(OwnedTLSConfig {
        identity,
        cert_chain,
    })
}

/// Builds what the driver dials. The credentials go into the URI's user info
/// rather than being spelled into the string, so nothing has to survive URI
/// escaping — a login or password holding `%`, `/`, `?`, `#` or `:` travels as
/// it is, which is why they are handed to the dial config rather than a URI
/// string.
fn connection_uri(key: &ConnectionKey, params: &ConnectParams) -> AMQPUri {
    let secure = key.secure_dial();

    let mut query = AMQPQueryString::default();

    if params.channel_max > 0 {
        query.channel_max = Some(params.channel_max.clamp(0, u16::MAX as i64) as u16);
    }

    if params.frame_max_bytes > 0 {
        query.frame_max = Some(params.frame_max_bytes.clamp(0, u32::MAX as i64) as u32);
    }

    if params.heartbeat_seconds > 0 {
        query.heartbeat = Some(params.heartbeat_seconds.clamp(0, u16::MAX as i64) as u16);
    }

    if params.sasl_method == SASL_METHOD_EXTERNAL {
        query.auth_mechanism = Some(lapin::uri::SASLMechanism::External);
    }

    AMQPUri {
        scheme: if secure {
            AMQPScheme::AMQPS
        } else {
            AMQPScheme::AMQP
        },
        authority: AMQPAuthority {
            userinfo: AMQPUserInfo {
                username: key.login.clone(),
                password: key.password.clone(),
            },
            host: key.host.clone(),
            port: default_port(secure, key.port),
        },
        vhost: key.vhost.clone(),
        query,
    }
}

fn default_port(secure: bool, port: i64) -> u16 {
    if port > 0 {
        return port.clamp(0, u16::MAX as i64) as u16;
    }

    if secure { 5671 } else { 5672 }
}

/// Closes a connection without waiting on the caller's deadline: whatever
/// triggered the close may already be cancelled.
fn close_connection(connection: Connection) {
    crate::core::get().runtime().spawn(async move {
        let _ = tokio::time::timeout(
            Duration::from_secs(5),
            connection.close(200, "sconcur".into()),
        )
        .await;
    });
}

fn close_connection_owned(pooled: Arc<PooledConnection>) {
    crate::core::get().runtime().spawn(async move {
        let _ = tokio::time::timeout(
            Duration::from_secs(5),
            pooled.connection.close(200, "sconcur".into()),
        )
        .await;
    });
}

pub fn ms_or_default(milliseconds: i64, fallback: Duration) -> Duration {
    if milliseconds <= 0 {
        return fallback;
    }

    Duration::from_millis(milliseconds as u64)
}
