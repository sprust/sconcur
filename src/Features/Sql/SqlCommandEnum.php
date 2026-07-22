<?php

declare(strict_types=1);

namespace SConcur\Features\Sql;

/**
 * Sub-operations of the SQL feature, selected via the payload envelope's `cm`
 * (like Mongodb's CommandEnum and the HTTP-client's HttpClientCommandEnum).
 *
 * Go: types.SqlCommand (ext/internal/types/sql.go).
 */
enum SqlCommandEnum: string
{
    case Query    = 'qry';
    case Exec     = 'exe';
    case Begin    = 'beg';
    case Commit   = 'cmt';
    case Rollback = 'rlb';
}
