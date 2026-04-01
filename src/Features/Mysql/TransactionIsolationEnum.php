<?php

declare(strict_types=1);

namespace SConcur\Features\Mysql;

enum TransactionIsolationEnum: int
{
    case Default          = 0;
    case ReadUncommitted  = 1;
    case ReadCommitted    = 2;
    case RepeatableRead   = 3;
    case Serializable     = 4;
    case Linearizable     = 5;
}
