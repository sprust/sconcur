//! The message a push carries in, the result a task publishes back into the
//! shared channel, and the value that result carries to PHP.

pub mod message;
pub mod result;
pub mod value;
pub mod value_de;

pub use message::Message;
pub use result::Result;
pub use value::{Key, Map, Object, Value};
