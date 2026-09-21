<?php

declare(strict_types=1);

namespace SConcur\Features\Files;

/**
 * Sub-operations of the files feature, carried in the payload envelope (the `cm` field)
 * under the single MethodEnum::Files.
 *
 * Every case names the Rust struct its parameters are decoded into. That cross-reference
 * lives here rather than on a class per command, because a file operation's parameters
 * are a flat map of paths, sizes and flags with no logic of their own (see
 * Payloads\FilesPayload) — the same reasoning as RedisCommandEnum and AmqpCommandEnum.
 *
 * Rust: the command values matched in ext/src/features/files/mod.rs.
 */
enum FilesCommandEnum: string
{
    /** Reads a file whole, or a byte range of it. Rust: payloads::ReadParams. */
    case Read = 'rd';

    /** Writes, appends or creates a file in one shot. Rust: payloads::WriteParams. */
    case Write = 'wr';

    /**
     * Writes through a temporary file and a rename, so a reader never sees a half-write.
     * Rust: payloads::WriteAtomicParams.
     */
    case WriteAtomic = 'wra';

    /** Cuts a file to a given length. Rust: payloads::TruncateParams. */
    case Truncate = 'tr';

    /** Copies a file inside the extension; the bytes never cross the boundary. Rust: payloads::CopyParams. */
    case Copy = 'cp';

    /**
     * Renames a file; across devices, copies through a temporary beside the destination
     * and renames that into place. Rust: payloads::MoveParams.
     */
    case Move = 'mv';

    /** Removes a file. Rust: payloads::DeleteParams. */
    case Delete = 'dl';

    /** Hashes a file of any size without moving its bytes. Rust: payloads::HashFileParams. */
    case HashFile = 'hsh';

    /** Everything one stat() call knows about a path. Rust: payloads::StatParams. */
    case Stat = 'st';

    /** Changes a path's permission bits. Rust: payloads::ChmodParams. */
    case Chmod = 'chm';

    /** Creates a file, or moves its modification time. Rust: payloads::TouchParams. */
    case Touch = 'tch';

    /** Canonicalizes a path. Rust: payloads::RealPathParams. */
    case RealPath = 'rp';

    /** Creates a uniquely named file and answers with its path. Rust: payloads::TemporaryFileParams. */
    case TemporaryFile = 'tmp';

    /** Creates a directory, optionally with its parents. Rust: payloads::MakeDirectoryParams. */
    case MakeDirectory = 'mkd';

    /** Removes a directory, optionally with everything under it. Rust: payloads::RemoveDirectoryParams. */
    case RemoveDirectory = 'rmd';

    /** Lists one directory in a single crossing. Rust: payloads::ListParams. */
    case List = 'ls';

    /** Reads a file in batches, streamed through next(). Rust: payloads::ReadChunksParams. */
    case ReadChunks = 'rdc';

    /** Reads a file line by line, streamed in batches through next(). Rust: payloads::ReadLinesParams. */
    case ReadLines = 'rdl';

    /**
     * Walks a directory tree, streamed batch by batch. Rust: payloads::WalkParams.
     *
     * `wlk`, not the `lst` it used to be: that reads as an abbreviation of List, and the
     * two answer the same shape, so confusing them would not fail on decode — it would
     * quietly return a whole tree where one directory was meant.
     */
    case Walk = 'wlk';

    /** Opens a streamed writer and registers it by id. Rust: payloads::WriteOpenParams. */
    case WriteOpen = 'wro';

    /** Hands one chunk to an open writer, waiting until it is taken. Rust: payloads::WriteChunkParams. */
    case WriteChunk = 'wrc';

    /** Closes an open writer and answers with the bytes written. Rust: payloads::WriteCloseParams. */
    case WriteClose = 'wrx';
}
