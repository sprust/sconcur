<?php

declare(strict_types=1);

namespace SConcur\Features\Files;

/**
 * The algorithms Files::hashFile() computes a file's checksum in. The wire values are the
 * names PHP's own hash_file() knows them by, so a digest from either side compares
 * against a digest from the other with no translation.
 *
 * Four, not the whole of hash_algos(): these are the ones a checksum is actually written
 * in. Sha1 and Md5 are here because ETags, package indexes and a decade of existing
 * manifests are written in them — not as a security choice.
 *
 * Rust: the values Algorithm::from_wire() matches (ext/src/features/files/hash.rs).
 */
enum FileHashAlgorithm: string
{
    /** The one to reach for when nothing else decides. */
    case Sha256 = 'sha256';

    case Sha512 = 'sha512';

    /** For an existing format that is written in it; not a security choice. */
    case Sha1 = 'sha1';

    /** For an ETag or a legacy manifest; not a security choice. */
    case Md5 = 'md5';
}
