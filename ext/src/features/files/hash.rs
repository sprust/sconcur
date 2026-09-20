//! Checksums of a file, computed where the file is.
//!
//! The point is what does not happen: the bytes never cross the boundary. PHP's
//! hash_file() already reads in a loop without holding the file whole, so the
//! memory is not the gain here — the gain is that the loop, and the disk waits
//! inside it, run on the runtime instead of on the PHP thread. A checksum of a
//! multi-gigabyte file stops being a stall of the whole worker.

use std::time::Instant;

use md5::Md5;
use sha1::Sha1;
use sha2::{Digest, Sha256, Sha512};
use tokio::io::AsyncReadExt;

use crate::dto::Result;
use crate::tasks::Task;

use super::errors::{io_message, message as fail, Kind};
use super::meta::{encode_text, publish};
use super::params;
use super::payloads;

/// How much is read per turn. Large enough that the syscalls are not the cost,
/// small enough that the buffer is nothing next to the file.
const READ_BUFFER_BYTES: usize = 65_536;

/// The algorithms, by the wire value of the PHP FileHashAlgorithm enum.
enum Algorithm {
    Sha256,
    Sha512,
    Sha1,
    Md5,
}

impl Algorithm {
    fn from_wire(value: &str) -> Option<Self> {
        match value {
            "sha256" => Some(Algorithm::Sha256),
            "sha512" => Some(Algorithm::Sha512),
            "sha1" => Some(Algorithm::Sha1),
            "md5" => Some(Algorithm::Md5),
            _ => None,
        }
    }
}

/// The digest of a file, lowercase hex — the same spelling hash_file() answers
/// in, so a value from either side compares against a value from the other.
pub async fn hash_file(task: &Task, envelope: &mut payloads::Envelope) {
    let message = task.message();
    let start_time = Instant::now();

    let Some(parameters) = params::<payloads::HashFileParams>(task, envelope, "hashFile").await
    else {
        return;
    };

    let Some(algorithm) = Algorithm::from_wire(&parameters.algorithm) else {
        task.add_result(Result::error(
            message,
            fail(
                Kind::Argument,
                &format!("unknown hash algorithm {}", parameters.algorithm),
            ),
        ))
        .await;

        return;
    };

    let path = parameters.path.clone();

    let work = async move {
        let digest = match algorithm {
            Algorithm::Sha256 => digest_of::<Sha256>(&path).await?,
            Algorithm::Sha512 => digest_of::<Sha512>(&path).await?,
            Algorithm::Sha1 => digest_of::<Sha1>(&path).await?,
            Algorithm::Md5 => digest_of::<Md5>(&path).await?,
        };

        Ok::<Vec<u8>, String>(encode_text("h", &digest))
    };

    publish(task, envelope, start_time, work).await;
}

/// Reads the file a buffer at a time and folds it into the digest. Generic over
/// the algorithm so the read loop is written once rather than four times.
async fn digest_of<D>(path: &str) -> std::result::Result<String, String>
where
    D: Digest,
{
    let mut file = tokio::fs::File::open(path)
        .await
        .map_err(|error| io_message("open", path, &error))?;

    let metadata = file
        .metadata()
        .await
        .map_err(|error| io_message("stat", path, &error))?;

    if metadata.is_dir() {
        return Err(fail(
            Kind::FileType,
            &format!("hash {path}: is a directory"),
        ));
    }

    let mut hasher = D::new();
    let mut buffer = vec![0_u8; READ_BUFFER_BYTES];

    loop {
        let read = file
            .read(&mut buffer)
            .await
            .map_err(|error| io_message("read", path, &error))?;

        if read == 0 {
            break;
        }

        hasher.update(&buffer[..read]);
    }

    Ok(hex(&hasher.finalize()))
}

/// Lowercase hex, written out rather than pulled in: the hex crate is not in
/// the tree, and this is the whole of what would be used from it.
fn hex(bytes: &[u8]) -> String {
    const DIGITS: &[u8; 16] = b"0123456789abcdef";

    let mut text = String::with_capacity(bytes.len() * 2);

    for byte in bytes {
        text.push(DIGITS[(byte >> 4) as usize] as char);
        text.push(DIGITS[(byte & 0x0f) as usize] as char);
    }

    text
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn hex_is_lowercase_and_zero_padded() {
        assert_eq!(hex(&[0x00, 0x0f, 0xff, 0xa0]), "000fffa0");
        assert_eq!(hex(&[]), "");
    }

    #[test]
    fn the_algorithms_are_named_as_php_names_them() {
        for value in ["sha256", "sha512", "sha1", "md5"] {
            assert!(
                Algorithm::from_wire(value).is_some(),
                "{value} is not accepted"
            );
        }

        assert!(Algorithm::from_wire("sha-256").is_none());
        assert!(Algorithm::from_wire("").is_none());
    }

    #[tokio::test]
    async fn a_digest_matches_the_published_vector_for_the_empty_file() {
        let path = std::env::temp_dir().join(format!("sconcur-hash-{}", std::process::id()));
        let path = path.to_string_lossy().to_string();

        tokio::fs::write(&path, b"").await.unwrap();

        // The published digests of the empty input, which is the one vector
        // every implementation agrees on and the one a buffered read loop is
        // most likely to get wrong.
        assert_eq!(
            digest_of::<Sha256>(&path).await.unwrap(),
            "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
        );
        assert_eq!(
            digest_of::<Sha1>(&path).await.unwrap(),
            "da39a3ee5e6b4b0d3255bfef95601890afd80709"
        );
        assert_eq!(
            digest_of::<Md5>(&path).await.unwrap(),
            "d41d8cd98f00b204e9800998ecf8427e"
        );

        tokio::fs::remove_file(&path).await.unwrap();
    }

    #[tokio::test]
    async fn a_file_longer_than_the_buffer_folds_every_turn_in() {
        let path = std::env::temp_dir().join(format!("sconcur-hash-long-{}", std::process::id()));
        let path = path.to_string_lossy().to_string();

        // Two buffers and a tail, so the loop runs three times and a bug that
        // hashes only the first or the last turn shows up.
        let contents = vec![b'x'; READ_BUFFER_BYTES * 2 + 17];

        tokio::fs::write(&path, &contents).await.unwrap();

        let streamed = digest_of::<Sha256>(&path).await.unwrap();

        let mut hasher = Sha256::new();

        hasher.update(&contents);

        assert_eq!(streamed, hex(&hasher.finalize()));

        tokio::fs::remove_file(&path).await.unwrap();
    }
}
