//! Accepting on a socket other processes accept on too, on Linux.
//!
//! Registered with the runtime like a socket of its own, a shared socket wakes
//! every worker holding it on every connection, and whoever runs first takes
//! what is queued: across 12 workers the busiest then served 7–8× the requests
//! of the idlest, and with a handful of keep-alive connections some served none.
//! Here a server accepts on a thread of its own that waits with EPOLLEXCLUSIVE,
//! so a connection wakes one waiting worker instead of all of them, and that
//! registers anew for every connection, which puts it at the back of the queue
//! of waiters: connections go round the waiting workers in turn. nginx and
//! Rapira accept the same way.
//!
//! The thread waits on the socket only while its server asks for a connection,
//! so nothing is taken off the queue before a server wants it: a worker that
//! stops, or is too busy to ask, leaves the connections to the others, as with a
//! plain accept.

use socket2::Socket;
use std::net::SocketAddr;
use std::os::fd::{AsRawFd, FromRawFd, OwnedFd, RawFd};
use std::sync::Arc;
use tokio::net::TcpStream;
use tokio::sync::mpsc;

/// What the thread hands over: a connection, or the error accepting it ended in.
type Accepted = std::io::Result<(std::net::TcpStream, SocketAddr)>;

/// The epoll tokens of the three things the thread waits on.
const DEMAND: u64 = 0;
const STOP: u64 = 1;
const LISTENER: u64 = 2;

/// The two counters the server rings the thread with.
struct Bells {
    /// Rung once per connection the server asks for.
    demand: OwnedFd,
    /// Rung once, when the server is done with the socket.
    stop: OwnedFd,
}

/// The server's end: asks the thread for connections and takes them.
pub struct Acceptor {
    bells: Arc<Bells>,
    accepted: mpsc::Receiver<Accepted>,
    /// Whether a connection was asked for and has not come yet. An accept that
    /// is dropped half way, as a select! drops the branch that lost, leaves the
    /// request standing for the next one rather than asking twice.
    asked: bool,
}

impl Acceptor {
    /// Starts the thread on the socket, which it owns from then on and closes
    /// when the acceptor is dropped.
    pub fn start(socket: Socket) -> std::io::Result<Self> {
        socket.set_nonblocking(true)?;

        let epoll = check(unsafe { libc::epoll_create1(libc::EPOLL_CLOEXEC) })?;
        let epoll = unsafe { OwnedFd::from_raw_fd(epoll) };

        let bells = Arc::new(Bells {
            demand: counter()?,
            stop: counter()?,
        });

        control(epoll.as_raw_fd(), libc::EPOLL_CTL_ADD, bells.demand.as_raw_fd(), DEMAND, 0)?;
        control(epoll.as_raw_fd(), libc::EPOLL_CTL_ADD, bells.stop.as_raw_fd(), STOP, 0)?;

        // One slot is enough: the thread accepts only what was asked for, and
        // nothing more is asked for until it has been taken.
        let (sender, accepted) = mpsc::channel(1);

        let thread_bells = bells.clone();

        std::thread::Builder::new()
            .name("sconcur-accept".to_string())
            .spawn(move || run(socket, epoll, thread_bells, sender))?;

        Ok(Self {
            bells,
            accepted,
            asked: false,
        })
    }

    pub async fn accept(&mut self) -> std::io::Result<(TcpStream, SocketAddr)> {
        if !self.asked {
            ring(&self.bells.demand)?;

            self.asked = true;
        }

        let accepted = self.accepted.recv().await;

        self.asked = false;

        match accepted {
            Some(Ok((stream, address))) => Ok((TcpStream::from_std(stream)?, address)),
            Some(Err(error)) => Err(error),
            // The thread only ends on its own when an epoll call fails, and it
            // hands that error over first. Waiting here after it leaves the server
            // to its stop instead of spinning the accept loop on an error.
            None => std::future::pending().await,
        }
    }
}

/// Ends the thread, which closes its copy of the socket. A connection it had
/// just accepted for an accept that will not be awaited again — the server
/// stopping in the microseconds between — is closed with it.
impl Drop for Acceptor {
    fn drop(&mut self) {
        let _ = ring(&self.bells.stop);
    }
}

/// The epoll call that failed, and how.
type Failure = (&'static str, std::io::Error);

/// The thread: accepts for its server until the server stops, then lets go of
/// the socket.
///
/// An epoll call that fails ends it as well — a registration past the user's
/// fs.epoll.max_user_watches is refused with ENOSPC — and that ending the server
/// would not hear of otherwise: it would wait for a connection nothing accepts
/// any more. So the failure goes to the log, and the server's next accept
/// returns it.
fn run(socket: Socket, epoll: OwnedFd, bells: Arc<Bells>, accepted: mpsc::Sender<Accepted>) {
    let Err((call, error)) = serve(&socket, &epoll, &bells, &accepted) else {
        return;
    };

    // Let go of first, as a thread that ended would: the error may wait a long
    // time for a server that does not ask again.
    drop(epoll);
    drop(socket);

    // A server runs on the runtime, so there is a core to log through; a test
    // may run without one, and is not given one just for this line.
    if let Some(core) = crate::core::built() {
        core.logger().write(format!(
            "accept: {call} failed on a shared listening socket: {error}; this server accepts no more \
             connections\n"
        ));
    }

    let _ = accepted.blocking_send(Err(error));
}

/// Waits for a request for a connection, then for a connection, accepts it and
/// hands it over, until the server stops or an epoll call fails.
fn serve(
    socket: &Socket,
    epoll: &OwnedFd,
    bells: &Bells,
    accepted: &mpsc::Sender<Accepted>,
) -> Result<(), Failure> {
    let epoll = epoll.as_raw_fd();
    let listener = socket.as_raw_fd();

    let mut events = [libc::epoll_event { events: 0, u64: 0 }; 3];

    loop {
        let count = unsafe {
            libc::epoll_wait(epoll, events.as_mut_ptr(), events.len() as libc::c_int, -1)
        };

        if count < 0 {
            let error = std::io::Error::last_os_error();

            if error.kind() == std::io::ErrorKind::Interrupted {
                continue;
            }

            return Err(("epoll_wait", error));
        }

        let ready = &events[..count as usize];

        if ready.iter().any(|event| event.u64 == STOP) {
            return Ok(());
        }

        if ready.iter().any(|event| event.u64 == DEMAND) {
            drain(&bells.demand);

            // At the back of the queue of waiters. Registered even when there is
            // already a connection waiting: epoll then reports it at once.
            control(epoll, libc::EPOLL_CTL_ADD, listener, LISTENER, libc::EPOLLEXCLUSIVE)
                .map_err(|error| ("epoll_ctl(EPOLL_CTL_ADD)", error))?;
        }

        if !ready.iter().any(|event| event.u64 == LISTENER) {
            continue;
        }

        let outcome = match socket.accept() {
            Ok((connection, address)) => accepted_connection(connection, address),
            Err(error) => match error.kind() {
                // Another worker took it, or the client gave up before it was
                // taken: the request stands, so wait on.
                std::io::ErrorKind::WouldBlock
                | std::io::ErrorKind::Interrupted
                | std::io::ErrorKind::ConnectionAborted => continue,
                _ => Err(error),
            },
        };

        // Before the hand-over, so the socket is not waited on until the server
        // asks again.
        let deregistered = control(epoll, libc::EPOLL_CTL_DEL, listener, LISTENER, 0);

        // A connection already accepted is the server's either way.
        if accepted.blocking_send(outcome).is_err() {
            return Ok(());
        }

        // Still registered, the thread would take the next connections unasked.
        deregistered.map_err(|error| ("epoll_ctl(EPOLL_CTL_DEL)", error))?;
    }
}

fn accepted_connection(connection: Socket, address: socket2::SockAddr) -> Accepted {
    connection.set_nonblocking(true)?;

    let address = address
        .as_socket()
        .ok_or_else(|| std::io::Error::other("the connection has no IP address"))?;

    Ok((connection.into(), address))
}

fn control(
    epoll: RawFd,
    operation: libc::c_int,
    descriptor: RawFd,
    token: u64,
    flags: libc::c_int,
) -> std::io::Result<()> {
    let mut event = libc::epoll_event {
        events: (libc::EPOLLIN | flags) as u32,
        u64: token,
    };

    check(unsafe { libc::epoll_ctl(epoll, operation, descriptor, &mut event) }).map(|_| ())
}

fn counter() -> std::io::Result<OwnedFd> {
    let descriptor = check(unsafe { libc::eventfd(0, libc::EFD_CLOEXEC | libc::EFD_NONBLOCK) })?;

    Ok(unsafe { OwnedFd::from_raw_fd(descriptor) })
}

fn ring(bell: &OwnedFd) -> std::io::Result<()> {
    let one: u64 = 1;

    let written = unsafe { libc::write(bell.as_raw_fd(), (&one as *const u64).cast(), 8) };

    check(written as libc::c_int).map(|_| ())
}

fn drain(bell: &OwnedFd) {
    let mut value: u64 = 0;

    unsafe { libc::read(bell.as_raw_fd(), (&mut value as *mut u64).cast(), 8) };
}

fn check(answer: libc::c_int) -> std::io::Result<libc::c_int> {
    if answer < 0 {
        return Err(std::io::Error::last_os_error());
    }

    Ok(answer)
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::os::fd::{AsFd, IntoRawFd};
    use std::time::Duration;

    /// A listening socket on a free port, as a worker master binds one.
    fn shared_socket() -> (Socket, SocketAddr) {
        let socket = crate::features::httpserver::listen::bind("127.0.0.1:0", false, 64).unwrap();
        let address = socket.local_addr().unwrap().as_socket().unwrap();

        (socket, address)
    }

    /// Another process's hold on the socket: a copy of the descriptor.
    fn copy(socket: &Socket) -> Socket {
        Socket::from(socket.as_fd().try_clone_to_owned().unwrap())
    }

    async fn within<T>(future: impl std::future::Future<Output = T>) -> T {
        tokio::time::timeout(Duration::from_secs(5), future).await.expect("timed out")
    }

    /// Two servers waiting on one socket take the connections in turn rather than
    /// the first to wake taking them all. Measured over 30 runs: within two of an
    /// even split with EPOLLEXCLUSIVE, anything up to 20 to 0 without it.
    #[tokio::test(flavor = "multi_thread", worker_threads = 2)]
    async fn the_waiting_servers_take_the_connections_in_turn() {
        let (socket, address) = shared_socket();
        let (taken, mut takes) = mpsc::unbounded_channel();

        for server in 0..2 {
            let mut acceptor = Acceptor::start(copy(&socket)).unwrap();
            let taken = taken.clone();

            tokio::spawn(async move {
                loop {
                    let (stream, _) = acceptor.accept().await.unwrap();

                    taken.send(server).unwrap();

                    drop(stream);
                }
            });
        }

        let mut counts = [0; 2];

        for _ in 0..20 {
            let _client = std::net::TcpStream::connect(address).unwrap();

            counts[within(takes.recv()).await.unwrap()] += 1;
        }

        assert!(counts.iter().all(|&count| count >= 6), "{counts:?}");
    }

    /// A server that has not asked for a connection does not take one: it stays
    /// in the queue for the server that asks.
    #[tokio::test(flavor = "multi_thread", worker_threads = 2)]
    async fn a_connection_waits_for_a_server_that_asks() {
        let (socket, address) = shared_socket();

        let _idle = Acceptor::start(copy(&socket)).unwrap();

        let _client = std::net::TcpStream::connect(address).unwrap();

        tokio::time::sleep(Duration::from_millis(50)).await;

        let mut asking = Acceptor::start(copy(&socket)).unwrap();

        let (_, peer) = within(asking.accept()).await.unwrap();

        assert_eq!(peer.ip(), address.ip());
    }

    /// An epoll call that fails ends the thread, and the server hears of it: its
    /// accept returns the error once, then waits for the server's stop rather than
    /// return it again to an accept loop that would spin on it. /dev/null stands in
    /// for the socket because epoll refuses to watch it, as it refuses a socket
    /// past the user's max_user_watches.
    #[tokio::test]
    async fn an_epoll_call_that_fails_reaches_the_server() {
        let null = std::fs::File::open("/dev/null").unwrap();

        let mut acceptor = Acceptor::start(Socket::from(OwnedFd::from(null))).unwrap();

        let error = within(acceptor.accept()).await.expect_err("accepted from /dev/null");

        assert_eq!(error.raw_os_error(), Some(libc::EPERM), "{error}");
        assert!(
            tokio::time::timeout(Duration::from_millis(50), acceptor.accept())
                .await
                .is_err(),
            "the error was returned again"
        );
    }

    /// Dropping the acceptor ends its thread, and the thread closes its copy of
    /// the descriptor: with every other copy closed, the port refuses.
    #[tokio::test]
    async fn a_dropped_acceptor_lets_go_of_the_socket() {
        let (socket, address) = shared_socket();

        let acceptor = Acceptor::start(copy(&socket)).unwrap();

        unsafe { libc::close(socket.into_raw_fd()) };

        drop(acceptor);

        within(async {
            while std::net::TcpStream::connect(address).is_ok() {
                tokio::time::sleep(Duration::from_millis(10)).await;
            }
        })
        .await;
    }
}
