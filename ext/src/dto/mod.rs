//! Mirrors ext-go-legacy/internal/dto: the message a push carries in, and the result a
//! task publishes back into the shared channel.

pub mod message;
pub mod result;

pub use message::Message;
pub use result::Result;
