<?php

declare(strict_types=1);

namespace SConcur\Features\Mysql;

enum CommandEnum: int
{
    case Query    = 1;
    case Exec     = 2;
    case Begin    = 3;
    case Commit   = 4;
    case Rollback = 5;
}
