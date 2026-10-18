<?php

namespace SConcur\Extension;

function ping(string $str): string
{
}

function push(string $fk, string $mt, string $tk, array $pl): string
{
}

function next(string $fk, string $tk): string
{
}

function wait(string $fk): \SConcur\Dto\TaskResultDto
{
}

function waitAny(): \SConcur\Dto\TaskResultDto
{
}

function waitAnyTimeout(int $timeoutMs): ?\SConcur\Dto\TaskResultDto
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

function amqpStopConsuming(string $fk): void
{
}

function amqpReopenConsumer(string $fk, string $chid): void
{
}

function destroy(): void
{
}

function version(): string
{
}

function waitAnyBatch(int $maxResults): array
{
}

function waitAnyTimeoutBatch(int $timeoutMs, int $maxResults): ?array
{
}

function armPreemption(int $quantumMs, callable $callback): void
{
}

function disarmPreemption(): void
{
}
