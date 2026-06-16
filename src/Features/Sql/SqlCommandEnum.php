<?php

declare(strict_types=1);

namespace SConcur\Features\Sql;

/**
 * Sub-operations of the SQL feature, selected via the payload envelope's `cm`
 * (like Mongodb's CommandEnum and the HTTP-client's HttpClientCommandEnum).
 *
 * Go: types.SqlCommand (ext/internal/types/sql.go).
 */
enum SqlCommandEnum: int
{
    case Query    = 1;
    case Exec     = 2;
    case Begin    = 3;
    case Commit   = 4;
    case Rollback = 5;
}
