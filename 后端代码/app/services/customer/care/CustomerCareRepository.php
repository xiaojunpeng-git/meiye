<?php

namespace app\services\customer\care;

/**
 * 客情领域持久化端口。
 *
 * 生产实现必须把 transaction 回调放进同一数据库事务，先原子占位并锁定命令
 * 回执，再按 task -> record 的稳定顺序加行锁。接口刻意只暴露追加记录和受限
 * CAS，避免覆盖正式记录内容。
 */
interface CustomerCareRepository
{
    /** @return mixed */
    public function transaction(callable $callback);

    /**
     * 原子创建或锁定命令回执。不存在时必须在当前事务占位，已成功时返回对应
     * 客情 operation；同键并发必须在这里串行，不能只查询一个可能不存在的行。
     */
    public function claimCommandReceipt(
        string $tenantId,
        string $idempotencyKey,
        string $operationType,
        string $requestFingerprint,
        int $operationStoreId,
        int $actorStaffId,
        array $resourceContext
    ): ?array;

    /** 成功 operation 插入后，在同一事务把共享命令回执完成为第一次确定结果。 */
    public function completeCommandReceipt(array $operation): void;

    public function findTaskByNaturalKey(string $tenantId, string $taskKey): ?array;

    public function findRecordByNaturalKey(string $tenantId, string $recordKey): ?array;

    public function lockTask(string $tenantId, int $taskId): ?array;

    public function lockRecord(string $tenantId, int $recordId): ?array;

    public function lockActiveAssignment(string $tenantId, int $businessStoreId, int $staffId): ?array;

    /**
     * Atomically reserves the next immutable serial for one tenant, business date and document type.
     * This must execute inside the caller's command transaction.
     */
    public function reserveDocumentSequence(
        string $tenantId,
        string $businessDate,
        string $documentType
    ): int;

    public function insertTask(array $row): array;

    public function updateTaskState(
        string $tenantId,
        int $taskId,
        int $expectedVersion,
        string $expectedStatus,
        string $nextStatus,
        array $fields
    ): bool;

    public function updateTaskVisibility(
        string $tenantId,
        int $taskId,
        int $expectedVersion,
        int $expectedVisibility,
        array $fields
    ): bool;

    public function updateTaskOwner(
        string $tenantId,
        int $taskId,
        int $expectedVersion,
        int $expectedOwnerStaffId,
        array $fields
    ): bool;

    public function bumpTaskVersion(string $tenantId, int $taskId, int $expectedVersion, array $fields): bool;

    public function insertRecord(array $row): array;

    public function voidRecordStatus(
        string $tenantId,
        int $recordId,
        int $expectedVersion,
        string $expectedStatus,
        array $fields
    ): bool;

    public function insertOperation(array $row): array;
}
