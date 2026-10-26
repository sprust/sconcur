<?php

declare(strict_types=1);

namespace SConcur\Worker;

/**
 * Where a group's workers write their access lines — one per request or connection.
 *
 * Stdout, the default, is the worker's stdout: under a master it is read by the master and
 * written into the journal with the rest of the worker's output. Under load that reader is
 * one PHP process for every group, and it falls behind. File has every worker write its access lines itself, to a file of the day
 * beside the journal (MasterLogger::accessLogPrefix), which nobody has to relay. Off
 * writes none.
 */
enum AccessLogTarget: string
{
    case Stdout = 'stdout';
    case File   = 'file';
    case Off    = 'off';
}
