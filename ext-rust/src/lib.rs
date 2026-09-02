//! Mirrors ext/main.go: the C exports the PHP glue (sconcur.c) binds to, the
//! binary result frame, and the preemption timer.
//!
//! Core spike (branch spike/rust-core): the exports, the shared results
//! channel, the sleeper feature and the HTTP server rungs the attribution
//! ladder needs. Every other feature answers "unknown method" through the same
//! facade the Go build uses.

mod core;
mod dto;
mod errs;
mod features;
mod flows;
mod handler;
mod socket;
mod ws;
mod helpers;
mod logger;
mod states;
mod tasks;
mod types;

use std::ffi::{c_char, c_int, c_longlong, c_void};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex, OnceLock};
use std::time::Duration;

use dto::{Message, Result};
use handler::{Handler, WaitError};
use types::method::Method;

/// The extension version the PHP package pins
/// (Extension::REQUIRED_EXTENSION_VERSION). The spike answers with the same
/// value so an unmodified package loads it.
const VERSION: &str = "0.11.0";

// Defined in sconcur.c: atomically requests a VM interrupt on the PHP thread.
unsafe extern "C" {
    fn sconcur_request_vm_interrupt();
}

/// Mirrors the buffer_result_t of sconcur.c. `data` and `err` are released by
/// the C side with free(), so both are allocated with libc::malloc here — never
/// with the Rust allocator.
#[repr(C)]
pub struct BufferResult {
    data: *mut c_void,
    len: c_int,
    err: *mut c_char,
}

// ---------------------------------------------------------------------------
// Result frame layout (core -> PHP). The envelope is a fixed binary header, not
// MessagePack, so the result is never double-encoded: only the feature payload
// stays MessagePack and is decoded once on the PHP side. Must match
// Extension::parseWaitResponse.
//
//  [0]      flags    uint8  (bit0 isError, bit1 hasNext)
//  [1]      method   length uint8
//  [2:6]    execMs   uint32 (big-endian)
//  [6:8]    flowKey  length uint16 (big-endian)
//  [8:10]   taskKey  length uint16 (big-endian)
//  [10:18]  ownerId  uint64 (big-endian; the PHP coroutine awaiting this
//           result, 0 = none — lets the PHP side route without a task map)
//  [18:]    method bytes, then flowKey bytes, then taskKey bytes, then the raw
//           payload (the rest)
// ---------------------------------------------------------------------------

const FRAME_HEADER_SIZE: usize = 18;
const FRAME_FLAG_ERROR: u8 = 1 << 0;
const FRAME_FLAG_HAS_NEXT: u8 = 1 << 1;

fn result_frame_size(result: &Result) -> usize {
    FRAME_HEADER_SIZE
        + result.method.as_wire().len()
        + result.flow_key.len()
        + result.task_key.len()
        + result.payload.len()
}

fn append_result_frame(destination: &mut Vec<u8>, result: &Result) {
    let mut flags: u8 = 0;

    if result.is_error {
        flags |= FRAME_FLAG_ERROR;
    }

    if result.has_next {
        flags |= FRAME_FLAG_HAS_NEXT;
    }

    let method = result.method.as_wire();

    destination.push(flags);
    destination.push(method.len() as u8);
    destination.extend_from_slice(&result.execution_ms.to_be_bytes());
    destination.extend_from_slice(&(result.flow_key.len() as u16).to_be_bytes());
    destination.extend_from_slice(&(result.task_key.len() as u16).to_be_bytes());
    destination.extend_from_slice(&(result.owner_id as u64).to_be_bytes());
    destination.extend_from_slice(method.as_bytes());
    destination.extend_from_slice(result.flow_key.as_bytes());
    destination.extend_from_slice(result.task_key.as_bytes());
    destination.extend_from_slice(&result.payload);
}

fn build_result_frame(result: &Result) -> Vec<u8> {
    let mut frame = Vec::with_capacity(result_frame_size(result));

    append_result_frame(&mut frame, result);

    frame
}

/// Concatenates result frames into the multiframe the PHP side iterates:
/// [count uint16][frameLen uint32][frame]... (big-endian), each inner frame in
/// the exact single-result format. Must match Extension::parseWaitBatchResponse.
fn build_result_batch_frame(results: &[Result]) -> Vec<u8> {
    let total = 2 + results
        .iter()
        .map(|result| 4 + result_frame_size(result))
        .sum::<usize>();

    let mut batch = Vec::with_capacity(total);

    batch.extend_from_slice(&(results.len() as u16).to_be_bytes());

    for result in results {
        batch.extend_from_slice(&(result_frame_size(result) as u32).to_be_bytes());
        append_result_frame(&mut batch, result);
    }

    batch
}

/// Copies bytes into a libc::malloc block the C side owns and frees.
fn malloc_copy(bytes: &[u8]) -> *mut c_void {
    if bytes.is_empty() {
        // malloc(0) may legally return NULL; a zero-length RETVAL_STRINGL still
        // needs a non-null pointer, so give it one byte.
        let block = unsafe { libc::malloc(1) };

        return block;
    }

    let block = unsafe { libc::malloc(bytes.len()) };

    if !block.is_null() {
        unsafe { std::ptr::copy_nonoverlapping(bytes.as_ptr(), block as *mut u8, bytes.len()) };
    }

    block
}

/// Copies a message into a NUL-terminated libc::malloc block, the shape every
/// `char *` export returns.
fn malloc_cstring(text: &str) -> *mut c_char {
    let bytes = text.as_bytes();
    let block = unsafe { libc::malloc(bytes.len() + 1) } as *mut u8;

    if block.is_null() {
        return std::ptr::null_mut();
    }

    unsafe {
        std::ptr::copy_nonoverlapping(bytes.as_ptr(), block, bytes.len());
        block.add(bytes.len()).write(0);
    }

    block as *mut c_char
}

/// Wraps framed bytes into the buffer_result_t crossing the boundary. c_int is
/// 32-bit: a frame past 2 GiB would truncate into a negative length and crash
/// the PHP side on RETVAL_STRINGL — answer with an error instead (the result is
/// lost either way; a loud error beats a segfault).
fn buffer_from_frame(frame: &[u8]) -> BufferResult {
    if frame.len() > i32::MAX as usize {
        return error_buffer("error: result frame exceeds the 2 GiB boundary limit");
    }

    BufferResult {
        data: malloc_copy(frame),
        len: frame.len() as c_int,
        err: std::ptr::null_mut(),
    }
}

fn error_buffer(text: &str) -> BufferResult {
    BufferResult {
        data: std::ptr::null_mut(),
        len: 0,
        err: malloc_cstring(text),
    }
}

// ---------------------------------------------------------------------------
// Runtime and handler singletons
// ---------------------------------------------------------------------------

/// The runtime every task runs on — the goroutine scheduler's counterpart.
///
/// Built on first use, together with the rest of the process state, and rebuilt
/// after a fork. See the `core` module for why that matters.
fn runtime() -> &'static tokio::runtime::Runtime {
    core::get().runtime()
}

fn handler() -> Arc<Handler> {
    core::get().handler()
}

fn replace_handler() {
    core::get().replace_handler();
}

// ---------------------------------------------------------------------------
// Boundary helpers
// ---------------------------------------------------------------------------

/// Returns a slice backed directly by the C buffer — no copy. Valid only until
/// the call returns (PHP owns and may free the buffer after that), so it must
/// only be used for lookups that do not retain it: comparisons, map reads, or
/// resolving against a fixed set.
///
/// Where Go must still copy to store a key (C.GoStringN), the copy here is an
/// explicit `.to_vec()` / `String::from_utf8_lossy(...).into_owned()` at the
/// call site, so every retained copy is visible in the code.
unsafe fn byte_view<'a>(pointer: *const c_char, length: c_int) -> &'a [u8] {
    if pointer.is_null() || length <= 0 {
        return &[];
    }

    unsafe { std::slice::from_raw_parts(pointer as *const u8, length as usize) }
}

unsafe fn owned_string(pointer: *const c_char, length: c_int) -> String {
    String::from_utf8_lossy(unsafe { byte_view(pointer, length) }).into_owned()
}

unsafe fn owned_string_nul(pointer: *const c_char) -> String {
    if pointer.is_null() {
        return String::new();
    }

    unsafe { std::ffi::CStr::from_ptr(pointer) }
        .to_string_lossy()
        .into_owned()
}

/// Runs an export body with unwinding contained: a panic crossing into C is
/// undefined behaviour and would take the PHP process down.
///
/// The fallback is a closure, not a value: several exports fall back to a
/// freshly malloc'ed error string, and an eager argument would allocate (and
/// leak) one on every successful call — push is the hot path.
fn guarded<T>(fallback: impl FnOnce() -> T, body: impl FnOnce() -> T) -> T {
    match std::panic::catch_unwind(std::panic::AssertUnwindSafe(body)) {
        Ok(value) => value,
        Err(_) => fallback(),
    }
}

// ---------------------------------------------------------------------------
// Preemption timer
// ---------------------------------------------------------------------------
//
// While armed, a thread periodically requests a VM interrupt so the C-side
// handler can park the currently running coroutine between opcodes.
//
// The ticker pauses while the PHP thread is parked inside a blocking wait
// export: no PHP code is running, so an interrupt could not be serviced anyway,
// and 1000/quantum wakeups per second per idle worker are pure waste.

static PHP_PARKED_IN_WAIT: AtomicBool = AtomicBool::new(false);

/// Serializes arm/disarm against each other; the value the ticker reads is the
/// atomic below, so the ticker never takes a lock.
fn preemption_lock() -> &'static Mutex<()> {
    static PREEMPTION: OnceLock<Mutex<()>> = OnceLock::new();

    PREEMPTION.get_or_init(|| Mutex::new(()))
}

/// Bumped by every arm and disarm. A ticker whose generation no longer matches
/// exits, so a re-arm cannot leave two tickers running and a disarm needs no
/// channel to stop one.
static PREEMPTION_GENERATION: std::sync::atomic::AtomicU64 = std::sync::atomic::AtomicU64::new(0);

fn begin_blocking_wait() {
    PHP_PARKED_IN_WAIT.store(true, Ordering::Release);
}

fn end_blocking_wait() {
    PHP_PARKED_IN_WAIT.store(false, Ordering::Release);
}

// ---------------------------------------------------------------------------
// Exports
// ---------------------------------------------------------------------------

#[unsafe(no_mangle)]
pub extern "C" fn ping(text: *const c_char) -> *mut c_char {
    guarded(std::ptr::null_mut, || {
        let text = unsafe { owned_string_nul(text) };

        malloc_cstring(&format!("ping: {text}"))
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn push(
    flow_key: *const c_char,
    flow_key_len: c_int,
    method: *const c_char,
    method_len: c_int,
    task_key: *const c_char,
    task_key_len: c_int,
    payload: *const c_void,
    payload_len: c_int,
    owner_id: c_longlong,
) -> *mut c_char {
    guarded(|| malloc_cstring("error: push: panic"), || {
        let message = Message {
            flow_key: unsafe { owned_string(flow_key, flow_key_len) },
            // Resolved through a view: the method set is fixed, so nothing is
            // copied to identify it.
            method: Method::from_wire(unsafe { byte_view(method, method_len) }),
            task_key: unsafe { owned_string(task_key, task_key_len) },
            payload: unsafe { byte_view(payload as *const c_char, payload_len) }.to_vec(),
            is_next: false,
            owner_id: owner_id as i64,
        };

        let _guard = runtime().enter();

        match handler().push(message) {
            // NULL, not an allocated empty string: success is the hot path, and
            // the C side turns NULL into the interned empty string — no
            // malloc/free per push.
            Ok(()) => std::ptr::null_mut(),
            Err(error) => malloc_cstring(&format!("error: push: {error}")),
        }
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn next(
    flow_key: *const c_char,
    task_key: *const c_char,
    owner_id: c_longlong,
) -> *mut c_char {
    guarded(|| malloc_cstring("error: next: panic"), || {
        let message = Message {
            flow_key: unsafe { owned_string_nul(flow_key) },
            method: Method::Unknown,
            task_key: unsafe { owned_string_nul(task_key) },
            payload: Vec::new(),
            is_next: true,
            owner_id: owner_id as i64,
        };

        let _guard = runtime().enter();

        match handler().push(message) {
            Ok(()) => std::ptr::null_mut(),
            Err(error) => malloc_cstring(&format!("error: next: {error}")),
        }
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn wait(flow_key: *const c_char, flow_key_len: c_int) -> BufferResult {
    guarded(|| error_buffer("error: wait: panic"), || {
        // A view is enough: wait only compares and looks the key up.
        let flow_key = unsafe { owned_string(flow_key, flow_key_len) };

        begin_blocking_wait();
        let outcome = handler().wait(&flow_key);
        end_blocking_wait();

        match outcome {
            Ok(result) => buffer_from_frame(&build_result_frame(&result)),
            Err(error) => error_buffer(&format!("error: {error}")),
        }
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn waitAny() -> BufferResult {
    guarded(|| error_buffer("error: waitAny: panic"), || {
        begin_blocking_wait();
        let outcome = handler().wait_any();
        end_blocking_wait();

        match outcome {
            Ok(result) => buffer_from_frame(&build_result_frame(&result)),
            Err(error) => error_buffer(&format!("error: {error}")),
        }
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn waitAnyTimeout(timeout_ms: c_int) -> BufferResult {
    guarded(|| error_buffer("error: waitAnyTimeout: panic"), || {
        begin_blocking_wait();
        let outcome = handler().wait_any_timeout(timeout_ms);
        end_blocking_wait();

        match outcome {
            Ok(result) => buffer_from_frame(&build_result_frame(&result)),
            // A timeout is not an error: signal it with a distinct,
            // non-"error:" sentinel the PHP side maps to "no result yet".
            Err(WaitError::Timeout) => error_buffer("timeout"),
            Err(WaitError::Failed(error)) => error_buffer(&format!("error: {error}")),
        }
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn waitAnyBatch(max_results: c_int) -> BufferResult {
    guarded(|| error_buffer("error: waitAnyBatch: panic"), || {
        let handler = handler();

        begin_blocking_wait();
        let outcome = handler.wait_any_batch(max_results);
        end_blocking_wait();

        match outcome {
            Ok(results) => {
                let buffer = buffer_from_frame(&build_result_batch_frame(&results));

                handler.recycle_batch(results);

                buffer
            }
            Err(error) => error_buffer(&format!("error: {error}")),
        }
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn waitAnyTimeoutBatch(timeout_ms: c_int, max_results: c_int) -> BufferResult {
    guarded(|| error_buffer("error: waitAnyTimeoutBatch: panic"), || {
        let handler = handler();

        begin_blocking_wait();
        let outcome = handler.wait_any_timeout_batch(timeout_ms, max_results);
        end_blocking_wait();

        match outcome {
            Ok(results) => {
                let buffer = buffer_from_frame(&build_result_batch_frame(&results));

                handler.recycle_batch(results);

                buffer
            }
            Err(WaitError::Timeout) => error_buffer("timeout"),
            Err(WaitError::Failed(error)) => error_buffer(&format!("error: {error}")),
        }
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn tasksCount() -> c_int {
    guarded(|| 0, || handler().tasks_count() as c_int)
}

#[unsafe(no_mangle)]
pub extern "C" fn stopFlow(flow_key: *const c_char, flow_key_len: c_int) {
    guarded(|| (), || {
        // The length comes from the C side, which already has it from
        // zend_parse_parameters — no strlen scan per stop.
        let flow_key = unsafe { owned_string(flow_key, flow_key_len) };

        handler().stop_flow(&flow_key);
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn httpStopAccepting(flow_key: *const c_char) {
    guarded(|| (), || {
        features::httpserver::stop_accepting(&unsafe { owned_string_nul(flow_key) });
    })
}

/// AMQP is not ported yet. Its export stays so the same sconcur.c and the same
/// PHP package load unchanged; a push for that method already fails in the
/// feature facade.
#[unsafe(no_mangle)]
pub extern "C" fn socketStopAccepting(flow_key: *const c_char) {
    guarded(|| (), || {
        features::socketserver::stop_accepting(&unsafe { owned_string_nul(flow_key) });
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn wsStopAccepting(flow_key: *const c_char) {
    guarded(|| (), || {
        features::wsserver::stop_accepting(&unsafe { owned_string_nul(flow_key) });
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn amqpStopConsuming(_flow_key: *const c_char) {}

#[unsafe(no_mangle)]
pub extern "C" fn preemptionArm(quantum_ms: c_int) {
    guarded(|| (), || {
        let _lock = preemption_lock().lock().unwrap();

        let generation = PREEMPTION_GENERATION.fetch_add(1, Ordering::AcqRel) + 1;

        let interval = Duration::from_millis(quantum_ms.max(1) as u64);

        // A plain OS thread, not a runtime task: it must keep ticking while the
        // runtime is idle, and it does nothing but an atomic store into the PHP
        // VM's interrupt flag.
        std::thread::Builder::new()
            .name("sconcur-preempt".to_string())
            .spawn(move || {
                loop {
                    std::thread::sleep(interval);

                    // A disarm, or a re-arm that replaced this ticker.
                    if PREEMPTION_GENERATION.load(Ordering::Acquire) != generation {
                        return;
                    }

                    // PHP is parked inside a blocking wait export: no PHP code
                    // is running, so an interrupt could not be serviced anyway.
                    if PHP_PARKED_IN_WAIT.load(Ordering::Acquire) {
                        continue;
                    }

                    unsafe { sconcur_request_vm_interrupt() };
                }
            })
            .ok();
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn preemptionDisarm() {
    guarded(|| (), || {
        let _lock = preemption_lock().lock().unwrap();

        PREEMPTION_GENERATION.fetch_add(1, Ordering::AcqRel);
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn destroy() {
    guarded(|| (), || {
        preemptionDisarm();

        // Flush the buffered access lines before the runtime goes: a process
        // that exits right after must still show its last ones.
        logger::flush();

        handler().destroy();

        replace_handler();
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn version() -> *mut c_char {
    guarded(std::ptr::null_mut, || {
        malloc_cstring(VERSION)
    })
}
