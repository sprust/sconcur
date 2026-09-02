//! Mirrors ext/internal/helpers.

use std::time::Instant;

/// Mirrors helpers.CalcExecutionMs.
pub fn calc_execution_ms(start: Instant) -> u32 {
    start.elapsed().as_millis().min(u32::MAX as u128) as u32
}
