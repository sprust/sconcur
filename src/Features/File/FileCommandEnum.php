<?php

declare(strict_types=1);

namespace SConcur\Features\File;

/**
 * Sub-operations of the File feature, selected via the payload envelope's `cm`
 * (like Mongodb's CommandEnum and the SQL feature's SqlCommandEnum).
 *
 * Go: types.FileCommand (ext/internal/types/file.go).
 */
enum FileCommandEnum: int
{
    case Open     = 1;
    case Read     = 2;
    case Write    = 3;
    case Seek     = 4;
    case Truncate = 5;
    case Sync     = 6;
    case Stat     = 7;
    case Close    = 8;
}
