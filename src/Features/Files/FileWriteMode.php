<?php

declare(strict_types=1);

namespace SConcur\Features\Files;

/**
 * How a write opens its destination file. Human-readable names over the fopen-style
 * flags; the actual open flags are mapped on the extension side (files::write_options).
 *
 * The wire values are the same three HttpClient's DownloadFileMode uses, and the two
 * enums are deliberately separate rather than one shared: that one is documented as the
 * mode of an HTTP download's sink, and neither feature should depend on the other's.
 *
 * Rust: the values write_options() matches (ext/src/features/files/mod.rs).
 */
enum FileWriteMode: string
{
    /** Create the file, or truncate it if it already exists (like `w`). */
    case Replace = 'rpl';

    /** Create the file, failing if it already exists (like `x`). */
    case Create = 'crt';

    /** Create the file, or append to it if it already exists (like `a`). */
    case Append = 'app';
}
