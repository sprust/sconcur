//! The live subscriptions of this process.
//!
//! A subscription owns its connection: the protocol puts the connection itself
//! into subscriber mode, so it cannot be shared and cannot go back to the pool.
//! The registry exists so a later add/remove/close task finds the connection
//! its subscription is on — those arrive as tasks of their own, and only the
//! subscription id ties them to it.

use std::collections::HashMap;
use std::sync::{Arc, Mutex};

use redis::aio::PubSubSink;
use tokio::sync::Mutex as AsyncMutex;

/// The write half of one subscription. The stream half lives in the state that
/// PHP pulls messages from; this half is what a later command talks to.
///
/// The sink is behind an async mutex because a change of channels and the
/// stream's own reading are two tasks touching one connection.
pub struct Subscription {
    sink: AsyncMutex<PubSubSink>,
}

impl Subscription {
    pub fn new(sink: PubSubSink) -> Self {
        Subscription {
            sink: AsyncMutex::new(sink),
        }
    }

    pub async fn subscribe(&self, channels: &[Vec<u8>], patterns: &[Vec<u8>]) -> Result<(), String> {
        let mut sink = self.sink.lock().await;

        for channel in channels {
            sink.subscribe(channel.as_slice())
                .await
                .map_err(|error| format!("subscribe: {error}"))?;
        }

        for pattern in patterns {
            sink.psubscribe(pattern.as_slice())
                .await
                .map_err(|error| format!("psubscribe: {error}"))?;
        }

        Ok(())
    }

    pub async fn unsubscribe(&self, channels: &[Vec<u8>], patterns: &[Vec<u8>]) -> Result<(), String> {
        let mut sink = self.sink.lock().await;

        for channel in channels {
            sink.unsubscribe(channel.as_slice())
                .await
                .map_err(|error| format!("unsubscribe: {error}"))?;
        }

        for pattern in patterns {
            sink.punsubscribe(pattern.as_slice())
                .await
                .map_err(|error| format!("punsubscribe: {error}"))?;
        }

        Ok(())
    }
}

pub struct Subscriptions {
    entries: Mutex<HashMap<String, Arc<Subscription>>>,
}

impl Subscriptions {
    pub fn new() -> Self {
        Subscriptions {
            entries: Mutex::new(HashMap::new()),
        }
    }

    pub fn store(&self, id: String, subscription: Arc<Subscription>) {
        self.entries.lock().unwrap().insert(id, subscription);
    }

    pub fn load(&self, id: &str) -> Option<Arc<Subscription>> {
        self.entries.lock().unwrap().get(id).cloned()
    }

    /// Removes one. Idempotent on purpose: an explicit close and the flow
    /// ending both arrive, in whichever order, and the second must find
    /// nothing rather than fail.
    pub fn remove(&self, id: &str) -> Option<Arc<Subscription>> {
        self.entries.lock().unwrap().remove(id)
    }

    pub fn close_all(&self) {
        self.entries.lock().unwrap().clear();
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn removing_twice_is_not_an_error() {
        // Both an explicit close and the flow ending call remove(), in whichever
        // order. The second one has to find nothing rather than fail.
        let registry = Subscriptions::new();

        assert!(registry.load("missing").is_none());
        assert!(registry.remove("missing").is_none());
        assert!(registry.remove("missing").is_none());
    }

    #[test]
    fn closing_everything_leaves_an_empty_registry() {
        let registry = Subscriptions::new();

        registry.close_all();

        assert!(registry.load("sid").is_none());
        assert!(registry.remove("sid").is_none());
    }

    // Storing is not exercised here: a Subscription needs a PubSubSink, which
    // cannot be built without a connection. What a stored entry does with an
    // add/remove/close is covered where it can be — against a live server, in
    // tests/feature/Features/Redis/RedisSubscribeTest.php.

}
