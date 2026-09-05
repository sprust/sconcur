//! The single facade that resolves a
//! method to its feature handler.

pub mod amqp;
pub mod httpclient;
pub mod httpserver;
pub mod mongodb;
pub mod sleeper;
pub mod socketclient;
pub mod socketserver;
pub mod sql;
pub mod wsclient;
pub mod wsserver;

use std::future::Future;
use std::pin::Pin;

use crate::dto::Result;
use crate::tasks::Task;
use crate::types::method::Method;

pub type BoxFuture = Pin<Box<dyn Future<Output = ()> + Send + 'static>>;

/// Mirrors contracts.FeatureContract.
///
/// The awaiting path and the detached path are different functions rather than
/// one: the detached one runs synchronously on the PHP thread inside push() and
/// must never block, which the type system is better placed to enforce than a
/// comment.
pub trait Feature: Send + Sync {
    fn handle(&self, task: Task) -> BoxFuture;

    /// Runs a fire-and-forget task on the PHP thread. Only reached for methods
    /// the handler's `detachable` allow-list admits — so the default is the
    /// answer for a method that slipped through the list without implementing
    /// one, and it publishes best-effort because this runs on the PHP thread.
    fn handle_detached(&self, task: Task) {
        task.add_result_detached(Result::error(
            task.message(),
            format!(
                "method {} has no detached handler",
                task.message().method.as_wire()
            ),
        ));
    }
}

/// What a flow does with one message: run a feature, or advance a streaming
/// state. Mirrors the `if msg.IsNext { states.Get().Next } else { … }` branch
/// in flows.Flow.HandleMessage.
#[derive(Clone, Copy)]
pub enum Handler {
    Feature(&'static dyn Feature),
    State,
}

/// Every method the PHP package can push is here; anything else is refused by
/// name, so an unsupported push fails loudly instead of hanging.
pub fn detect_message_handler(method: Method) -> std::result::Result<&'static dyn Feature, String> {
    match method {
        Method::Sleep => Ok(sleeper::get()),
        Method::HttpServe | Method::HttpRespond => Ok(httpserver::get()),
        Method::HttpClient => Ok(httpclient::get()),
        Method::Mongodb => Ok(mongodb::get()),
        Method::Mysql => Ok(sql::get_mysql()),
        Method::Pgsql => Ok(sql::get_pgsql()),
        Method::SocketServe | Method::SocketRespond => Ok(socketserver::get()),
        Method::SocketClient => Ok(socketclient::get()),
        Method::WsServe | Method::WsRespond => Ok(wsserver::get()),
        Method::WsClient => Ok(wsclient::get()),
        Method::Amqp => Ok(amqp::get()),
        _ => Err(format!("unknown method: {}", method.as_wire())),
    }
}

/// Mirrors features.Shutdown: releases what the features hold — the HTTP
/// server's listener registry and the SQL connection pools.
pub fn shutdown() {
    httpclient::shutdown();
    httpserver::shutdown();
    socketserver::shutdown();
    wsserver::shutdown();
    mongodb::shutdown();
    amqp::shutdown();
    sql::close_all_pools();
}
