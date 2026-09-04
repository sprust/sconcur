//! One client per
//! connection string, shared by every task that names it.
//!
//! The driver's `Client` is itself a pooled, cheaply cloneable handle, so this
//! layer only has to make sure two tasks asking for the same URI get the same
//! one instead of each opening its own topology.

use mongodb::options::ClientOptions;
use mongodb::Client;
use std::collections::HashMap;
use std::sync::Mutex;
use std::time::Duration;

pub type Error = String;

/// Keyed by the settings that change what the client *is*, not merely what a
/// single call does — two tasks differing only in their per-call timeout share
/// a client, two differing in server selection do not.
#[derive(PartialEq, Eq, Hash, Clone)]
struct ClientKey {
    url: String,
    server_selection_timeout_ms: i64,
}

pub struct Clients {
    clients: Mutex<HashMap<ClientKey, Client>>,
}

impl Clients {
    pub fn new() -> Self {
        Clients {
            clients: Mutex::new(HashMap::new()),
        }
    }

    pub async fn get(
        &self,
        url: &str,
        server_selection_timeout_ms: i64,
    ) -> Result<Client, Error> {
        let key = ClientKey {
            url: url.to_string(),
            server_selection_timeout_ms,
        };

        if let Some(client) = self.clients.lock().unwrap().get(&key) {
            return Ok(client.clone());
        }

        let mut options = ClientOptions::parse(url)
            .await
            .map_err(|error| format!("parse connection string: {error}"))?;

        if server_selection_timeout_ms > 0 {
            options.server_selection_timeout =
                Some(Duration::from_millis(server_selection_timeout_ms as u64));
        }

        let client = Client::with_options(options).map_err(|error| format!("connect: {error}"))?;

        let mut clients = self.clients.lock().unwrap();

        // Another task may have built the same client meanwhile; keep the one
        // already registered so the topology is not duplicated.
        Ok(clients.entry(key).or_insert(client).clone())
    }

    /// Mirrors DisconnectAll: called on extension shutdown. Dropping the handles
    /// shuts each topology down once the last clone goes.
    pub fn disconnect_all(&self) {
        self.clients.lock().unwrap().clear();
    }
}
