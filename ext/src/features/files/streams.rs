//! The commands that open a stream: readChunks, readLines and walk.
//!
//! Each one validates its parameters, opens what it needs, and hands the state
//! to the registry — which reads the first batch and hooks the state's cleanup
//! to the flow, so a stream PHP abandons halfway is released when its coroutine
//! ends rather than held for the life of the process.

use std::sync::Arc;

use crate::dto::Result;
use crate::states;
use crate::tasks::Task;

use super::errors::{io_message, message as fail, Kind};
use super::payloads;
use super::params;
use super::read_state::{Mode, ReadState};
use super::walk_state::WalkState;

/// 64 KiB, the same granularity HttpClient's download copies at.
const DEFAULT_BUFFER_BYTES: usize = 65_536;
const MAX_BUFFER_BYTES: usize = 8 * 1024 * 1024;

/// How many lines or entries a batch carries when the caller names no size.
const DEFAULT_BATCH_SIZE: usize = 200;
const MAX_BATCH_SIZE: usize = 100_000;

/// The cap on one line, so a file with no newline in it cannot be buffered
/// whole in the name of streaming.
const DEFAULT_MAX_LINE_BYTES: usize = 1024 * 1024;

fn bounded_size(value: i64, default: usize, maximum: usize) -> usize {
    if value <= 0 {
        return default;
    }

    (value as usize).min(maximum)
}

/// Registers a state, reads its first batch and answers with it. The tail every
/// stream in this module shares.
async fn start(task: &Task, state: Arc<dyn states::StateContract>) {
    let message = task.message();

    match states::get()
        .start(task.context().clone(), &message.task_key, state)
        .await
    {
        Ok(result) => task.add_result(result).await,
        Err(error) => {
            task.add_result(Result::error(message, fail(Kind::State, &error)))
                .await;
        }
    }
}

/// Reads a file in raw batches.
pub async fn read_chunks(task: &Task, envelope: &payloads::Envelope) {
    let Some(parameters) =
        params::<payloads::ReadChunksParams>(task, envelope, "readChunks").await
    else {
        return;
    };

    let Some(file) = open_for_read(task, &parameters.path).await else {
        return;
    };

    let state = ReadState::new(
        parameters.path.clone(),
        Mode::Chunks,
        bounded_size(parameters.buffer_size_bytes, DEFAULT_BUFFER_BYTES, MAX_BUFFER_BYTES),
        1,
        DEFAULT_MAX_LINE_BYTES,
        task.message_arc(),
        file,
    );

    start(task, Arc::new(state)).await;
}

/// Reads a file as batches of lines.
pub async fn read_lines(task: &Task, envelope: &payloads::Envelope) {
    let Some(parameters) = params::<payloads::ReadLinesParams>(task, envelope, "readLines").await
    else {
        return;
    };

    let Some(file) = open_for_read(task, &parameters.path).await else {
        return;
    };

    let state = ReadState::new(
        parameters.path.clone(),
        Mode::Lines,
        bounded_size(parameters.buffer_size_bytes, DEFAULT_BUFFER_BYTES, MAX_BUFFER_BYTES),
        bounded_size(parameters.batch_size, DEFAULT_BATCH_SIZE, MAX_BATCH_SIZE),
        bounded_size(
            parameters.max_line_bytes,
            DEFAULT_MAX_LINE_BYTES,
            MAX_BUFFER_BYTES,
        ),
        task.message_arc(),
        file,
    );

    start(task, Arc::new(state)).await;
}

/// Walks a directory tree in batches of entries.
pub async fn walk(task: &Task, envelope: &payloads::Envelope) {
    let message = task.message();

    let Some(parameters) = params::<payloads::WalkParams>(task, envelope, "walk").await else {
        return;
    };

    // Checked before the walk starts rather than surfacing in the first batch:
    // "not a directory" belongs to the call that named the path.
    match tokio::fs::metadata(&parameters.path).await {
        Ok(metadata) if !metadata.is_dir() => {
            task.add_result(Result::error(
                message,
                fail(
                    Kind::FileType,
                    &format!("walk {}: is not a directory", parameters.path),
                ),
            ))
            .await;

            return;
        }
        Ok(_) => {}
        Err(error) => {
            task.add_result(Result::error(
                message,
                io_message("open directory", &parameters.path, &error),
            ))
            .await;

            return;
        }
    }

    let state = Arc::new(WalkState::new(
        parameters.path.clone(),
        parameters.pattern.clone(),
        parameters.with_metadata,
        bounded_size(parameters.batch_size, DEFAULT_BATCH_SIZE, MAX_BATCH_SIZE),
        task.message_arc(),
    ));

    start(task, state).await;
}

/// Opens a file for a stream, refusing a directory by type rather than letting
/// the first read fail with something less useful.
async fn open_for_read(task: &Task, path: &str) -> Option<tokio::fs::File> {
    let message = task.message();

    let file = match tokio::fs::File::open(path).await {
        Ok(file) => file,
        Err(error) => {
            task.add_result(Result::error(message, io_message("open", path, &error)))
                .await;

            return None;
        }
    };

    match file.metadata().await {
        Ok(metadata) if metadata.is_dir() => {
            task.add_result(Result::error(
                message,
                fail(Kind::FileType, &format!("read {path}: is a directory")),
            ))
            .await;

            None
        }
        Ok(_) => Some(file),
        Err(error) => {
            task.add_result(Result::error(message, io_message("stat", path, &error)))
                .await;

            None
        }
    }
}
