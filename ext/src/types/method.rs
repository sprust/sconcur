//! Mirrors ext-go-legacy/internal/types/method.go.
//!
//! Go stores the method as a string and interns it through a map so a message
//! can hold the canonical constant instead of a per-push copy
//! (types.InternMethod). An enum makes that free here: parsing the boundary
//! bytes yields a Copy value with no allocation and no map lookup at all, so
//! the interning table has no counterpart on this side.

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Method {
    Sleep,
    Mongodb,
    HttpServe,
    HttpRespond,
    HttpClient,
    Mysql,
    Pgsql,
    SocketServe,
    SocketRespond,
    SocketClient,
    WsServe,
    WsRespond,
    WsClient,
    Amqp,
    /// A method the core does not know. Kept as a variant rather than a parse
    /// error so the unknown-method message still reaches the feature factory,
    /// which is where Go reports it.
    Unknown,
}

impl Method {
    /// Resolves the wire value. Takes bytes, not a String: the caller holds a
    /// view of the C buffer that dies with the call, and nothing here retains it.
    pub fn from_wire(bytes: &[u8]) -> Self {
        match bytes {
            b"sl" => Method::Sleep,
            b"mng" => Method::Mongodb,
            b"hs" => Method::HttpServe,
            b"hr" => Method::HttpRespond,
            b"hc" => Method::HttpClient,
            b"my" => Method::Mysql,
            b"pg" => Method::Pgsql,
            b"ss" => Method::SocketServe,
            b"sr" => Method::SocketRespond,
            b"sc" => Method::SocketClient,
            b"wss" => Method::WsServe,
            b"wsr" => Method::WsRespond,
            b"wsc" => Method::WsClient,
            b"amq" => Method::Amqp,
            _ => Method::Unknown,
        }
    }

    /// The wire value, written back into every result frame.
    pub fn as_wire(&self) -> &'static str {
        match self {
            Method::Sleep => "sl",
            Method::Mongodb => "mng",
            Method::HttpServe => "hs",
            Method::HttpRespond => "hr",
            Method::HttpClient => "hc",
            Method::Mysql => "my",
            Method::Pgsql => "pg",
            Method::SocketServe => "ss",
            Method::SocketRespond => "sr",
            Method::SocketClient => "sc",
            Method::WsServe => "wss",
            Method::WsRespond => "wsr",
            Method::WsClient => "wsc",
            Method::Amqp => "amq",
            Method::Unknown => "",
        }
    }
}
