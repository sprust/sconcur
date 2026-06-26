<?php

namespace SConcur\Extension;

function ping(string $str): string
{
}

function push(string $fk, int $mt, string $tk, string $pl): string
{
}

function next(string $fk, string $tk): string
{
}

function wait(string $fk): string
{
}

function waitAny(): string
{
}

function waitAnyTimeout(int $timeoutMs): string
{
}

function tasksCount(): int
{
}

function stopFlow(string $fk): void
{
}

function httpStopAccepting(string $fk): void
{
}

function socketStopAccepting(string $fk): void
{
}

function wsStopAccepting(string $fk): void
{
}

function destroy(): void
{
}

function version(): string
{
}
