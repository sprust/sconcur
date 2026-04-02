<?php

declare(strict_types=1);

namespace SConcur\Features\Mysql\Connection;

use JsonException;
use RuntimeException;
use SConcur\Dto\TaskResultDto;
use SConcur\Exceptions\TransactionAlreadyBeganException;
use SConcur\Exceptions\TransactionIsNotStartedException;
use SConcur\Features\FeatureExecutor;
use SConcur\Features\MethodEnum;
use SConcur\Features\Mysql\CommandEnum;
use SConcur\Features\Mysql\Results\ExecResult;
use SConcur\Features\Mysql\Results\QueryResult;
use SConcur\Features\Mysql\TransactionIsolationEnum;

class Client
{
    protected ?Transaction $transaction = null;

    public function __construct(
        public string $dsn,
        public int $timeoutMs = 0,
    ) {
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public function query(string $sql, array $bindings = [], ?Transaction $transaction = null): QueryResult
    {
        $taskResult = $this->execCommand(
            command: CommandEnum::Query,
            sql: $sql,
            bindings: $bindings,
            txKey: $this->getTransactionKey(),
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
    public function exec(string $sql, array $bindings = []): ExecResult
    {
        $taskResult = $this->execCommand(
            command: CommandEnum::Exec,
            sql: $sql,
            bindings: $bindings,
            txKey: $this->getTransactionKey(),
            isolation: null,
        );

        $data = $this->decodeJsonPayload($taskResult->payload);

        return new ExecResult(
            rowsAffected: (int) ($data['rowsAffected'] ?? 0),
            lastInsertId: (int) ($data['lastInsertId'] ?? 0),
        );
    }

    public function beginTransaction(?TransactionIsolationEnum $isolation = null): static
    {
        $this->checkTransactionBegin();

        $this->transaction = new Transaction();

        $taskResult = $this->execCommand(
            command: CommandEnum::Begin,
            sql: '',
            bindings: [],
            txKey: null,
            isolation: $isolation ?: TransactionIsolationEnum::Default,
        );

        $this->transaction->setKey(
            key: $taskResult->payload
        );

        return $this;
    }

    public function commit(): void
    {
        $this->checkTransactionCommitRollback();

        $this->execCommand(
            command: CommandEnum::Commit,
            sql: '',
            bindings: [],
            txKey: $this->getTransactionKey(),
            isolation: null,
        );

        $this->resetTransaction();
    }

    public function rollback(): void
    {
        $this->checkTransactionCommitRollback();

        $this->execCommand(
            command: CommandEnum::Rollback,
            sql: '',
            bindings: [],
            txKey: $this->getTransactionKey(),
            isolation: null,
        );

        $this->resetTransaction();
    }

    protected function checkTransactionBegin(): void
    {
        if ($this->transaction !== null) {
            throw new TransactionAlreadyBeganException();
        }
    }

    protected function checkTransactionCommitRollback(): void
    {
        if ($this->transaction === null) {
            throw new TransactionIsNotStartedException();
        }
    }

    protected function getTransactionKey(): ?string
    {
        if ($this->transaction === null) {
            return null;
        }

        if ($key = $this->transaction->getKey()) {
            return $key;
        }

        throw new TransactionIsNotStartedException();
    }

    protected function resetTransaction(): void
    {
        $this->transaction = null;
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
