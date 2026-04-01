<?php

declare(strict_types=1);

namespace SConcur\Features\Mysql\Connection;

use JsonException;
use RuntimeException;
use SConcur\Dto\TaskResultDto;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\MethodEnum;
use SConcur\Features\Mysql\CommandEnum;
use SConcur\Features\Mysql\Results\ExecResult;
use SConcur\Features\Mysql\Results\QueryResult;
use SConcur\Features\Mysql\TransactionIsolationEnum;

readonly class Client
{
    public function __construct(
        public string $dsn,
        public int $timeoutMs = 0,
    ) {
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public function query(string $sql, array $bindings = []): QueryResult
    {
        return $this->queryInternal(
            sql: $sql,
            bindings: $bindings,
            txKey: null,
        );
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public function exec(string $sql, array $bindings = []): ExecResult
    {
        return $this->execInternal(
            sql: $sql,
            bindings: $bindings,
            txKey: null,
        );
    }

    public function beginTransaction(?TransactionIsolationEnum $isolation = null): Transaction
    {
        $taskResult = $this->execCommand(
            command: CommandEnum::Begin,
            sql: '',
            bindings: [],
            txKey: null,
            isolation: $isolation ?? TransactionIsolationEnum::Default,
        );

        return new Transaction(
            client: $this,
            txKey: $taskResult->payload,
        );
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public function queryInternal(string $sql, array $bindings, ?string $txKey): QueryResult
    {
        $taskResult = $this->execCommand(
            command: CommandEnum::Query,
            sql: $sql,
            bindings: $bindings,
            txKey: $txKey,
            isolation: null,
        );

        $data = $this->decodeJsonPayload($taskResult->payload);

        return new QueryResult(
            columns: $data['cols'] ?? [],
            rows: $data['rows'] ?? [],
        );
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public function execInternal(string $sql, array $bindings, ?string $txKey): ExecResult
    {
        $taskResult = $this->execCommand(
            command: CommandEnum::Exec,
            sql: $sql,
            bindings: $bindings,
            txKey: $txKey,
            isolation: null,
        );

        $data = $this->decodeJsonPayload($taskResult->payload);

        return new ExecResult(
            rowsAffected: (int) ($data['rowsAffected'] ?? 0),
            lastInsertId: (int) ($data['lastInsertId'] ?? 0),
        );
    }

    public function commit(string $txKey): void
    {
        $this->execCommand(
            command: CommandEnum::Commit,
            sql: '',
            bindings: [],
            txKey: $txKey,
            isolation: null,
        );
    }

    public function rollback(string $txKey): void
    {
        $this->execCommand(
            command: CommandEnum::Rollback,
            sql: '',
            bindings: [],
            txKey: $txKey,
            isolation: null,
        );
    }

    /**
     * @param array<int, mixed> $bindings
     */
    protected function execCommand(
        CommandEnum $command,
        string $sql,
        array $bindings,
        ?string $txKey,
        ?TransactionIsolationEnum $isolation,
    ): TaskResultDto {
        return FeatureExecutor::exec(
            method: MethodEnum::Mysql,
            payload: $this->serializePayload(
                command: $command,
                sql: $sql,
                bindings: $bindings,
                txKey: $txKey,
                isolation: $isolation,
            ),
        );
    }

    /**
     * @param array<int, mixed> $bindings
     */
    protected function serializePayload(
        CommandEnum $command,
        string $sql,
        array $bindings,
        ?string $txKey,
        ?TransactionIsolationEnum $isolation,
    ): string {
        try {
            return json_encode(
                [
                    'dsn' => $this->dsn,
                    'sql' => $sql,
                    'bd'  => $this->encodeBindings($bindings),
                    'cm'  => $command->value,
                    'tx'  => $txKey ?: '',
                    'iso' => $isolation?->value ?: TransactionIsolationEnum::Default->value,
                    'to'  => $this->timeoutMs,
                ],
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                message: 'Failed to encode payload JSON: ' . $exception->getMessage(),
                previous: $exception
            );
        }
    }

    /**
     * @param array<int, mixed> $bindings
     */
    protected function encodeBindings(array $bindings): string
    {
        try {
            return json_encode($bindings, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                message: 'Failed to encode bindings JSON: ' . $exception->getMessage(),
                previous: $exception
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeJsonPayload(string $payload): array
    {
        try {
            $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                message: 'Failed to decode result JSON: ' . $exception->getMessage(),
                previous: $exception
            );
        }

        if (!is_array($data)) {
            throw new RuntimeException(
                message: 'Failed to decode result JSON: expected array payload'
            );
        }

        return $data;
    }
}
