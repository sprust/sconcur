//! The C exports the PHP glue (sconcur.c) binds to, the hand-over of results to
//! the glue's result builder, and the preemption timer.
//!
//! Everything the PHP side can call ends up here: the exports themselves, the
//! shared results channel behind them, and the fields a result crosses as.
//! A method the build does not carry answers "unknown method" through the same
//! facade rather than failing differently.

#[cfg(test)]
mod bench;
mod core;
mod dto;
mod errs;
mod features;
mod flows;
mod handler;
mod socket;
mod stats;
mod ws;
mod helpers;
mod logger;
mod states;
mod tasks;
mod types;

use std::ffi::{CStr, CString, c_char, c_int, c_longlong, c_uint, c_void};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex, OnceLock};
use std::time::{Duration, Instant};

use dto::{Key, Message, Result, Value};
use handler::{Handler, WaitError};
use types::method::Method;

/// The extension version the PHP package pins
/// (Extension::REQUIRED_EXTENSION_VERSION); the package refuses any other.
const VERSION: &str = "0.16.0";

// Defined in sconcur.c: the VM interrupt request of the preemption ticker, the
// result builder the wait exports hand their results to, and the value builder
// that turns a result's value tree into zvals.
unsafe extern "C" {
    fn sconcur_request_vm_interrupt();
    fn sconcur_result_into(destination: *mut c_void, result: *const ResultFields) -> *mut c_void;
    fn sconcur_result_append(list: *mut c_void, result: *const ResultFields) -> *mut c_void;
    fn sconcur_throw(class_name: *const c_char, message: *const c_char);
    fn sconcur_value_null(destination: *mut c_void);
    fn sconcur_value_bool(destination: *mut c_void, flag: c_int);
    fn sconcur_value_long(destination: *mut c_void, number: c_longlong);
    fn sconcur_value_double(destination: *mut c_void, number: f64);
    fn sconcur_value_stringl(destination: *mut c_void, bytes: *const c_char, length: usize);
    fn sconcur_value_array(destination: *mut c_void, size: c_uint);
    fn sconcur_array_next_slot(array: *mut c_void) -> *mut c_void;
    fn sconcur_array_index_slot(array: *mut c_void, index: c_longlong) -> *mut c_void;
    fn sconcur_array_key_slot(array: *mut c_void, key: *const c_char, length: usize) -> *mut c_void;
    fn sconcur_value_object(destination: *mut c_void, class_name: *const c_char, length: usize) -> c_int;
    fn sconcur_object_property_slot(object: *mut c_void, name: *const c_char, length: usize) -> *mut c_void;
    fn sconcur_zval_kind(value: *const c_void) -> c_int;
    fn sconcur_zval_bool(value: *const c_void) -> c_int;
    fn sconcur_zval_long(value: *const c_void) -> c_longlong;
    fn sconcur_zval_double(value: *const c_void) -> f64;
    fn sconcur_zval_string(value: *const c_void, length: *mut usize) -> *const c_char;
    fn sconcur_array_count(array: *const c_void) -> c_uint;
    fn sconcur_array_is_list(array: *const c_void) -> c_int;
    fn sconcur_object_class(object: *const c_void, length: *mut usize) -> *const c_char;
    fn sconcur_object_is_plain(object: *const c_void) -> c_int;
    fn sconcur_object_refused(object: *const c_void) -> c_int;
    fn sconcur_array_iterate(array: *const c_void, context: *mut c_void, visit: VisitFn) -> c_int;
    fn sconcur_object_iterate(object: *const c_void, context: *mut c_void, visit: VisitFn) -> c_int;
    fn sconcur_exception_pending() -> c_int;
}

/// Mirrors sconcur_visit_t of sconcur_core.h: the callback the glue calls per
/// entry of a container it walks for the value reader below.
type VisitFn = unsafe extern "C" fn(
    context: *mut c_void,
    key: *const c_char,
    key_length: usize,
    index: c_longlong,
    value: *const c_void,
) -> c_int;

// ---------------------------------------------------------------------------
// Parameters on their way in.
//
// The push export is given the PHP array a task was called with and reads it
// into a `Value` tree here, on the PHP thread, before it returns: the glue's
// value readers answer what each zval is, and the two iterate calls walk a
// container and call back per entry. Strings are copied, since the array is
// PHP's and may be freed the moment the call returns. A PHP object is read as
// its class and public properties, a stdClass as a plain map.
// ---------------------------------------------------------------------------

const KIND_NULL: c_int = 0;
const KIND_BOOL: c_int = 1;
const KIND_LONG: c_int = 2;
const KIND_DOUBLE: c_int = 3;
const KIND_STRING: c_int = 4;
const KIND_ARRAY: c_int = 5;
const KIND_OBJECT: c_int = 6;

/// How deep a payload may nest. A PHP array cannot contain itself, but an
/// object graph can, and json_encode's own limit is the same order of magnitude.
const READ_DEPTH_LIMIT: usize = 256;

/// The state of one container walk: the entries collected so far and the first
/// failure, if any, which stops the walk.
struct Collected {
    entries: Vec<(Key, Value)>,
    depth: usize,
    error: Option<String>,
}

/// The visit the glue calls per entry. `extern "C"`, so a panic inside would
/// abort the process rather than reach `guarded`; nothing here can panic (an
/// allocation failure aborts regardless), and the error path is the return
/// value, not an unwind.
unsafe extern "C" fn collect_entry(
    context: *mut c_void,
    key: *const c_char,
    key_length: usize,
    index: c_longlong,
    value: *const c_void,
) -> c_int {
    let collected = unsafe { &mut *(context as *mut Collected) };

    let entry_key = if key.is_null() {
        Key::Int(index)
    } else {
        Key::Str(unsafe { byte_view_len(key, key_length) }.to_vec())
    };

    match read_value(value, collected.depth + 1) {
        Ok(value) => {
            collected.entries.push((entry_key, value));

            1
        }
        Err(error) => {
            collected.error = Some(error);

            0
        }
    }
}

fn read_entries(container: *const c_void, depth: usize, capacity: usize, object: bool) -> std::result::Result<Vec<(Key, Value)>, String> {
    let mut collected = Collected {
        entries: Vec::with_capacity(capacity),
        depth,
        error: None,
    };

    let context = &mut collected as *mut Collected as *mut c_void;

    unsafe {
        if object {
            sconcur_object_iterate(container, context, collect_entry);
        } else {
            sconcur_array_iterate(container, context, collect_entry);
        }
    };

    // An object's property table may have been built by user code (a lazy
    // object's initializer); a throw there must refuse the payload, not travel
    // alongside a task that was enqueued anyway.
    if unsafe { sconcur_exception_pending() } != 0 {
        return Err("PHP raised an exception while the payload was read".to_string());
    }

    match collected.error {
        Some(error) => Err(error),
        None => Ok(collected.entries),
    }
}

/// Reads one zval into a value, recursively.
fn read_value(value: *const c_void, depth: usize) -> std::result::Result<Value, String> {
    if depth > READ_DEPTH_LIMIT {
        return Err(format!("the payload nests deeper than {READ_DEPTH_LIMIT} levels"));
    }

    match unsafe { sconcur_zval_kind(value) } {
        KIND_NULL => Ok(Value::Nil),
        KIND_BOOL => Ok(Value::Bool(unsafe { sconcur_zval_bool(value) } != 0)),
        KIND_LONG => Ok(Value::Int(unsafe { sconcur_zval_long(value) })),
        KIND_DOUBLE => Ok(Value::Float(unsafe { sconcur_zval_double(value) })),
        KIND_STRING => {
            let mut length: usize = 0;
            let bytes = unsafe { sconcur_zval_string(value, &mut length) };

            Ok(Value::Str(unsafe { byte_view_len(bytes, length) }.to_vec()))
        }
        KIND_ARRAY => {
            let count = unsafe { sconcur_array_count(value) } as usize;
            let entries = read_entries(value, depth, count, false)?;

            // A list is a list only when PHP says so: keys 0..n in order. A map
            // stays a map even when its keys happen to be integers.
            if unsafe { sconcur_array_is_list(value) } != 0 {
                Ok(Value::List(entries.into_iter().map(|(_, item)| item).collect()))
            } else {
                Ok(Value::Map(entries))
            }
        }
        KIND_OBJECT => {
            match unsafe { sconcur_object_refused(value) } {
                1 => return Err("the payload holds a Closure, which cannot cross into the extension".to_string()),
                2 => return Err("the payload holds an enum case; pass its value or name instead".to_string()),
                _ => {}
            }

            let entries = read_entries(value, depth, 4, true)?;

            if unsafe { sconcur_object_is_plain(value) } != 0 {
                return Ok(Value::Map(entries));
            }

            let mut length: usize = 0;
            let class = unsafe { sconcur_object_class(value, &mut length) };
            let class = String::from_utf8_lossy(unsafe { byte_view_len(class, length) }).into_owned();

            Ok(Value::Object(dto::Object {
                class: std::borrow::Cow::Owned(class),
                properties: entries
                    .into_iter()
                    .map(|(key, item)| (std::borrow::Cow::Owned(key.text()), item))
                    .collect(),
            }))
        }
        // Whatever is left after the kinds above is a resource: a reference is
        // read through and an undefined slot reads as null.
        _ => Err("the payload holds a resource, which cannot cross into the extension".to_string()),
    }
}

// ---------------------------------------------------------------------------
// Results on their way to PHP.
//
// A result is not framed into bytes: the core hands the fields of each one to
// the glue's result builder (sconcur.c), which instantiates the PHP package's
// TaskResultDto on the PHP thread, inside the wait export PHP is parked in,
// and the result's value tree is walked here into the payload slot through the
// glue's value builder. Nothing here touches a zval — the builders are C, every
// destination is an opaque pointer, and an export only reports how the wait
// ended.
// ---------------------------------------------------------------------------

/// Mirrors sconcur_result_t of sconcur_core.h, field for field. The pointers
/// are views into the `Result` the fields were taken from, valid for the
/// builder call and nothing longer.
#[repr(C)]
struct ResultFields {
    flow_key: *const c_char,
    flow_key_len: usize,
    method_case: *const c_char,
    task_key: *const c_char,
    task_key_len: usize,
    is_error: c_int,
    has_next: c_int,
    execution_ms: c_uint,
    total_execution_ms: c_longlong,
    owner_id: c_longlong,
}

impl ResultFields {
    fn of(result: &Result, total_execution_ms: u64) -> Self {
        ResultFields {
            flow_key: result.flow_key.as_ptr() as *const c_char,
            flow_key_len: result.flow_key.len(),
            method_case: result.method.as_php_case().as_ptr(),
            task_key: result.task_key.as_ptr() as *const c_char,
            task_key_len: result.task_key.len(),
            is_error: c_int::from(result.is_error),
            has_next: c_int::from(result.has_next),
            execution_ms: result.execution_ms,
            total_execution_ms: total_execution_ms as c_longlong,
            owner_id: result.owner_id,
        }
    }
}

/// Writes one value tree into `destination`, node by node, through the glue's
/// value builder. A child is written before the next sibling's slot is asked
/// for, because adding to a PHP array may move what the array holds. False
/// means the builder threw; the partial value is released with the whole
/// result by the glue.
fn write_value(destination: *mut c_void, value: &Value) -> bool {
    match value {
        Value::Nil => unsafe { sconcur_value_null(destination) },
        Value::Bool(flag) => unsafe { sconcur_value_bool(destination, c_int::from(*flag)) },
        Value::Int(number) => unsafe { sconcur_value_long(destination, *number as c_longlong) },
        Value::Float(number) => unsafe { sconcur_value_double(destination, *number) },
        Value::Str(bytes) => unsafe {
            sconcur_value_stringl(destination, bytes.as_ptr() as *const c_char, bytes.len())
        },
        Value::List(items) => {
            unsafe { sconcur_value_array(destination, items.len() as c_uint) };

            for item in items {
                let slot = unsafe { sconcur_array_next_slot(destination) };

                if slot.is_null() || !write_value(slot, item) {
                    return false;
                }
            }
        }
        Value::Map(entries) => {
            unsafe { sconcur_value_array(destination, entries.len() as c_uint) };

            for (key, item) in entries {
                let slot = match key {
                    Key::Int(index) => unsafe { sconcur_array_index_slot(destination, *index as c_longlong) },
                    Key::Str(bytes) => unsafe {
                        sconcur_array_key_slot(destination, bytes.as_ptr() as *const c_char, bytes.len())
                    },
                };

                if slot.is_null() || !write_value(slot, item) {
                    return false;
                }
            }
        }
        Value::Object(object) => {
            let created = unsafe {
                sconcur_value_object(destination, object.class.as_ptr() as *const c_char, object.class.len())
            };

            if created == 0 {
                return false;
            }

            for (name, item) in &object.properties {
                let slot = unsafe {
                    sconcur_object_property_slot(destination, name.as_ptr() as *const c_char, name.len())
                };

                if slot.is_null() || !write_value(slot, item) {
                    return false;
                }
            }
        }
    }

    true
}

/// Copies a message into a NUL-terminated libc::malloc block, the shape every
/// `char *` export returns; the C side frees it with free(), so it is never
/// allocated with the Rust allocator.
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

/// The SCONCUR_WAIT_* codes of sconcur_core.h: how a wait export ended.
const WAIT_READY: c_int = 0;
const WAIT_TIMEOUT: c_int = 1;
const WAIT_FAILED: c_int = 2;

/// The exception a failed wait throws, the PHP package's own: the glue looks
/// the class up by name, so the caller catches what it has always caught.
const TASK_ERROR_EXCEPTION: &CStr = c"SConcur\\Exceptions\\TaskErrorException";

/// Builds one TaskResultDto into `destination`. `total_execution_ms` is how long
/// the export waited for it, measured here rather than on the PHP side, which
/// no longer sees the crossing's edges.
fn write_result(destination: *mut c_void, result: &Result, total_execution_ms: u64) -> c_int {
    let fields = ResultFields::of(result, total_execution_ms);
    let payload = unsafe { sconcur_result_into(destination, &fields) };

    if payload.is_null() || !write_value(payload, &result.payload) {
        return WAIT_FAILED;
    }

    WAIT_READY
}

/// Builds the list of a batch into `destination`. Only the first result waited:
/// the tail was already ready when the crossing returned, so it carries 0,
/// the per-call semantics of the singular wait, where a ready result's own wait
/// returns at once.
fn write_result_batch(destination: *mut c_void, results: &[Result], first_total_execution_ms: u64) -> c_int {
    unsafe { sconcur_value_array(destination, results.len() as c_uint) };

    for (index, result) in results.iter().enumerate() {
        let total_execution_ms = if index == 0 { first_total_execution_ms } else { 0 };
        let fields = ResultFields::of(result, total_execution_ms);
        let payload = unsafe { sconcur_result_append(destination, &fields) };

        if payload.is_null() || !write_value(payload, &result.payload) {
            return WAIT_FAILED;
        }
    }

    WAIT_READY
}

/// Throws the TaskErrorException of a failed wait, naming the export it failed
/// in, and answers FAILED so a call site returns the two in one expression.
fn throw_wait_error(context: &str, error: &str) -> c_int {
    // A NUL inside the text would end the C string early; none is expected, but a
    // message is not worth a panic, so it is replaced rather than refused.
    let message = CString::new(format!("{context}: {error}").replace('\0', " ")).unwrap_or_default();

    unsafe { sconcur_throw(TASK_ERROR_EXCEPTION.as_ptr(), message.as_ptr()) };

    WAIT_FAILED
}

/// The glue's side of the contract, for the unit-test harness only: `cargo test
/// --lib` links the core without sconcur.c, and these stand in for the C
/// functions it would otherwise supply. Nothing in a unit test reaches them;
/// they exist so the symbols resolve. rustc does not check a stub against the
/// `extern` block, so a drifted signature here still links: the real contract
/// is checked by `make ext-build` alone.
#[cfg(test)]
mod glue_stubs {
    use std::ffi::{c_char, c_int, c_longlong, c_uint, c_void};

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_request_vm_interrupt() {}

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_result_into(_destination: *mut c_void, _result: *const super::ResultFields) -> *mut c_void {
        std::ptr::null_mut()
    }

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_result_append(_list: *mut c_void, _result: *const super::ResultFields) -> *mut c_void {
        std::ptr::null_mut()
    }

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_throw(_class_name: *const c_char, _message: *const c_char) {}

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_value_null(_destination: *mut c_void) {}

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_value_bool(_destination: *mut c_void, _flag: c_int) {}

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_value_long(_destination: *mut c_void, _number: c_longlong) {}

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_value_double(_destination: *mut c_void, _number: f64) {}

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_value_stringl(_destination: *mut c_void, _bytes: *const c_char, _length: usize) {}

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_value_array(_destination: *mut c_void, _size: c_uint) {}

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_array_next_slot(_array: *mut c_void) -> *mut c_void {
        std::ptr::null_mut()
    }

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_array_index_slot(_array: *mut c_void, _index: c_longlong) -> *mut c_void {
        std::ptr::null_mut()
    }

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_array_key_slot(_array: *mut c_void, _key: *const c_char, _length: usize) -> *mut c_void {
        std::ptr::null_mut()
    }

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_value_object(_destination: *mut c_void, _class_name: *const c_char, _length: usize) -> c_int {
        0
    }

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_object_property_slot(_object: *mut c_void, _name: *const c_char, _length: usize) -> *mut c_void {
        std::ptr::null_mut()
    }

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_zval_kind(_value: *const c_void) -> c_int {
        0
    }

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_zval_bool(_value: *const c_void) -> c_int {
        0
    }

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_zval_long(_value: *const c_void) -> c_longlong {
        0
    }

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_zval_double(_value: *const c_void) -> f64 {
        0.0
    }

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_zval_string(_value: *const c_void, length: *mut usize) -> *const c_char {
        unsafe { *length = 0 };

        std::ptr::null()
    }

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_array_count(_array: *const c_void) -> c_uint {
        0
    }

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_array_is_list(_array: *const c_void) -> c_int {
        1
    }

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_object_class(_object: *const c_void, length: *mut usize) -> *const c_char {
        unsafe { *length = 0 };

        std::ptr::null()
    }

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_object_is_plain(_object: *const c_void) -> c_int {
        1
    }

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_object_refused(_object: *const c_void) -> c_int {
        0
    }

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_array_iterate(_array: *const c_void, _context: *mut c_void, _visit: super::VisitFn) -> c_int {
        1
    }

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_object_iterate(_object: *const c_void, _context: *mut c_void, _visit: super::VisitFn) -> c_int {
        1
    }

    #[unsafe(no_mangle)]
    pub extern "C" fn sconcur_exception_pending() -> c_int {
        0
    }
}

// ---------------------------------------------------------------------------
// Runtime and handler singletons
// ---------------------------------------------------------------------------

/// The runtime every task runs on.
///
/// Built on first use, together with the rest of the process state, and rebuilt
/// after a fork. See the `core` module for why that matters.
fn runtime() -> &'static tokio::runtime::Runtime {
    core::get().runtime()
}

fn handler() -> Arc<Handler> {
    core::get().handler()
}

// ---------------------------------------------------------------------------
// Boundary helpers
// ---------------------------------------------------------------------------

/// Returns a slice backed directly by the C buffer — no copy. Valid only until
/// the call returns (PHP owns and may free the buffer after that), so it must
/// only be used for lookups that do not retain it: comparisons, map reads, or
/// resolving against a fixed set.
///
/// Retaining one means an explicit `.to_vec()` or
/// `String::from_utf8_lossy(...).into_owned()` at the call site, so every copy
/// that outlives the call is visible in the code.
unsafe fn byte_view<'a>(pointer: *const c_char, length: c_int) -> &'a [u8] {
    if pointer.is_null() || length <= 0 {
        return &[];
    }

    unsafe { std::slice::from_raw_parts(pointer as *const u8, length as usize) }
}

/// `byte_view` for a length that is a size_t: what the value readers answer, so
/// a string past 2 GiB is read whole rather than truncated by a narrowing cast.
unsafe fn byte_view_len<'a>(pointer: *const c_char, length: usize) -> &'a [u8] {
    if pointer.is_null() || length == 0 {
        return &[];
    }

    unsafe { std::slice::from_raw_parts(pointer as *const u8, length) }
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
    owner_id: c_longlong,
) -> *mut c_char {
    guarded(|| malloc_cstring("error: push: panic"), || {
        // Read before anything else: the array is PHP's, valid for this call.
        let payload = match read_value(payload, 0) {
            Ok(payload) => payload,
            Err(error) => return malloc_cstring(&format!("error: push: {error}")),
        };

        let message = Message {
            flow_key: unsafe { owned_string(flow_key, flow_key_len) },
            // Resolved through a view: the method set is fixed, so nothing is
            // copied to identify it.
            method: Method::from_wire(unsafe { byte_view(method, method_len) }),
            task_key: unsafe { owned_string(task_key, task_key_len) },
            payload,
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
            payload: Value::Nil,
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
pub extern "C" fn wait(flow_key: *const c_char, flow_key_len: c_int, return_value: *mut c_void) -> c_int {
    guarded(|| throw_wait_error("wait", "panic"), || {
        let started = Instant::now();
        let flow_key = unsafe { owned_string(flow_key, flow_key_len) };

        begin_blocking_wait();
        let outcome = handler().wait(&flow_key);
        end_blocking_wait();

        match outcome {
            Ok(result) => write_result(return_value, &result, elapsed_ms(started)),
            Err(error) => throw_wait_error("wait", &error),
        }
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn waitAny(return_value: *mut c_void) -> c_int {
    guarded(|| throw_wait_error("waitAny", "panic"), || {
        let started = Instant::now();

        begin_blocking_wait();
        let outcome = handler().wait_any();
        end_blocking_wait();

        match outcome {
            Ok(result) => write_result(return_value, &result, elapsed_ms(started)),
            Err(error) => throw_wait_error("waitAny", &error),
        }
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn waitAnyTimeout(timeout_ms: c_int, return_value: *mut c_void) -> c_int {
    guarded(|| throw_wait_error("waitAnyTimeout", "panic"), || {
        let started = Instant::now();

        begin_blocking_wait();
        let outcome = handler().wait_any_timeout(timeout_ms);
        end_blocking_wait();

        match outcome {
            Ok(result) => write_result(return_value, &result, elapsed_ms(started)),
            // A timeout is not an error: the glue answers null for it.
            Err(WaitError::Timeout) => WAIT_TIMEOUT,
            Err(WaitError::Failed(error)) => throw_wait_error("waitAnyTimeout", &error),
        }
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn waitAnyBatch(max_results: c_int, return_value: *mut c_void) -> c_int {
    guarded(|| throw_wait_error("waitAnyBatch", "panic"), || {
        let started = Instant::now();
        let handler = handler();

        begin_blocking_wait();
        let outcome = handler.wait_any_batch(max_results);
        end_blocking_wait();

        match outcome {
            Ok(results) => {
                let status = write_result_batch(return_value, &results, elapsed_ms(started));

                handler.recycle_batch(results);

                status
            }
            Err(error) => throw_wait_error("waitAnyBatch", &error),
        }
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn waitAnyTimeoutBatch(timeout_ms: c_int, max_results: c_int, return_value: *mut c_void) -> c_int {
    guarded(|| throw_wait_error("waitAnyTimeoutBatch", "panic"), || {
        let started = Instant::now();
        let handler = handler();

        begin_blocking_wait();
        let outcome = handler.wait_any_timeout_batch(timeout_ms, max_results);
        end_blocking_wait();

        match outcome {
            Ok(results) => {
                let status = write_result_batch(return_value, &results, elapsed_ms(started));

                handler.recycle_batch(results);

                status
            }
            Err(WaitError::Timeout) => WAIT_TIMEOUT,
            Err(WaitError::Failed(error)) => throw_wait_error("waitAnyTimeoutBatch", &error),
        }
    })
}

/// Whole milliseconds since `started`, the unit TaskResultDto carries.
fn elapsed_ms(started: Instant) -> u64 {
    started.elapsed().as_millis() as u64
}

// The exports from here to destroy() only act on work already running. In a
// process that has built no core there is none, so they answer at once instead
// of building one to find that out: a core is a runtime with threads, and a
// process that may be about to fork must not grow them as a side effect of
// asking, stopping or tearing down.

#[unsafe(no_mangle)]
pub extern "C" fn tasksCount() -> c_int {
    guarded(|| 0, || match core::built() {
        Some(core) => core.handler().tasks_count() as c_int,
        None => 0,
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn stopFlow(flow_key: *const c_char, flow_key_len: c_int) {
    guarded(|| (), || {
        let Some(core) = core::built() else {
            return;
        };

        // The length comes from the C side, which already has it from
        // zend_parse_parameters — no strlen scan per stop.
        let flow_key = unsafe { owned_string(flow_key, flow_key_len) };

        core.handler().stop_flow(&flow_key);
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn httpStopAccepting(flow_key: *const c_char) {
    guarded(|| (), || {
        if core::built().is_none() {
            return;
        }

        features::httpserver::stop_accepting(&unsafe { owned_string_nul(flow_key) });
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn socketStopAccepting(flow_key: *const c_char) {
    guarded(|| (), || {
        if core::built().is_none() {
            return;
        }

        features::socketserver::stop_accepting(&unsafe { owned_string_nul(flow_key) });
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn wsStopAccepting(flow_key: *const c_char) {
    guarded(|| (), || {
        if core::built().is_none() {
            return;
        }

        features::wsserver::stop_accepting(&unsafe { owned_string_nul(flow_key) });
    })
}

/// Cancels every consumer of a supervised worker's stream, leaving its channels
/// open so the acknowledgements of the handlers still running land. Mirrors the
/// servers' early listener close: the stream stops taking new work while the
/// work already handed over finishes.
#[unsafe(no_mangle)]
pub extern "C" fn amqpStopConsuming(flow_key: *const c_char) {
    guarded(|| (), || {
        if core::built().is_none() {
            return;
        }

        features::amqp::consume_serve::stop_consuming(&unsafe { owned_string_nul(flow_key) });
    })
}

/// Cancels the consumer a supervised worker can no longer settle on, so its
/// slot opens a fresh one on a fresh channel. The counterpart of
/// `amqpStopConsuming`: the worker sees a lost acknowledgement before the broker
/// does, and without this the queue stands still until the broker's own consumer
/// timeout.
#[unsafe(no_mangle)]
pub extern "C" fn amqpReopenConsumer(flow_key: *const c_char, channel_id: *const c_char) {
    guarded(|| (), || {
        if core::built().is_none() {
            return;
        }

        features::amqp::consume_serve::reopen_consumer(
            &unsafe { owned_string_nul(flow_key) },
            &unsafe { owned_string_nul(channel_id) },
        );
    })
}

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

        // Extension::__destruct calls this in every process that touched the
        // package, the ones that never pushed anything included.
        let Some(core) = core::built() else {
            return;
        };

        // Flush the buffered access lines before the runtime goes: a process
        // that exits right after must still show its last ones.
        core.logger().flush();

        core.handler().destroy();

        core.replace_handler();
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn version() -> *mut c_char {
    guarded(std::ptr::null_mut, || {
        malloc_cstring(VERSION)
    })
}
