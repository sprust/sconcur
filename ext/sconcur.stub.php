<?php

namespace SConcur\Extension;

function ping(string $str): string
{
}

function push(string $fk, int $mt, string $tk, string $pl): string
{
}

function pushBin(string $fk, int $mt, string $tk, string $pl): string
{
}

function next(string $fk, string $tk): string
{
}

function wait(string $fk): string
{
}

function waitBin(string $fk): string
{
}

function count(): int
{
}

function stopFlow(string $fk): void
{
}

function destroy(): void
{
}

function version(): string
{
}
