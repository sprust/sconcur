<?php

namespace SConcur\Extension;

function ping(string $str): string
{
}

function push(string $fk, int $mt, string $tk, string $pl): string
{
}

function wait(string $fk, int $ms): string
{
}

function count(): int
{
}

function cancelTask(string $fk, string $tk): void
{
}

function stopFlow(string $fk): void
{
}

function destroy(): void
{
}
