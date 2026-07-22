<?php

declare(strict_types=1);

namespace SConcur\Features\HttpClient;

/**
 * How HttpClient::download() opens the destination file. Human-readable names over
 * the fopen-style flags; the actual os.O_* flags are mapped on the Go side
 * (httpclient_feature.downloadModeToFlags).
 *
 * Go: download mode constants (ext/internal/features/httpclient/download.go).
 */
enum DownloadFileMode: string
{
    /** Create the file, or truncate it if it already exists (like `w`). */
    case Replace = 'rpl';

    /** Create the file, failing if it already exists (like `x`). */
    case Create = 'crt';

    /** Create the file, or append to it if it already exists (like `a`). */
    case Append = 'app';
}
