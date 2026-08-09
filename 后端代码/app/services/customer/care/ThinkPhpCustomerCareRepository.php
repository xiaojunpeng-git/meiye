<?php

namespace app\services\customer\care;

use think\facade\Db;

/**
 * ThinkPHP/MySQL 5.6 客情仓储。
 *
 * 命令回执复用已部署的 cashier_v3_command_receipt。占位、领域写入、operation
 * 和回执完成全部处于 CustomerCareCommandService 发起的同一数据库事务。
 */
final class ThinkPhpCustomerCareRepository implements CustomerCareRepository
{
    private const RECEIPT_TABLE = 'cashier_v3_command_receipt';
    private const TASK_TABLE = 'customer_care_task';
    private const RECORD_TABLE = 'customer_care_record';
    private const OPERATION_TABLE = 'customer_care_operation';
    private const DOCUMENT_SEQUENCE_TABLE = 'customer_care_document_sequence';
    private const RECEIPT_SUCCEEDED = 1;

    /** @return mixed */
    public function transaction(callable $callback)
    {
        return Db::transaction($callback);
    }

    public function claimCommandReceipt(
        string $tenantId,
        string $idempotencyKey,
        string $operationType,
        string $requestFingerprint,
        int $operationStoreId,
        int $actorStaffId,
        array $resourceContext
    ): ?array {
        $receiptKey = self::receiptKey($tenantId, $idempotencyKey);
        $action = self::receiptAction($operationType);
        $now = time();
        $contextJson = self::encodeJson($resourceContext);
        $contextHash = hash('sha256', $contextJson);
        $placeholder = [
            'idempotency_key' => $receiptKey,
            'action' => $action,
            'store_id' => $operationStoreId,
            'operator_id' => $actorStaffId,
            'state_context_id' => self::receiptStateContextId($tenantId),
            'request_hash' => $requestFingerprint,
            'contexts_hash' => $contextHash,
            'contexts_json' => $contextJson,
            'status' => 0,
            'result_code' => '',
            'result_message' => '',
            'result_json' => '',
            'business_no' => '',
            'operator_ip' => '',
            'add_time' => $now,
            'finish_time' => 0,
        ];

        try {
            $inserted = Db::name(self::RECEIPT_TABLE)->insert($placeholder);
            if ((int)$inserted !== 1) {
                throw $this->repositoryConflict('commandReceipt', '命令回执占位写入失败。');
            }
            return null;
        } catch (CustomerCareDomainException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            if (!$this->isDuplicateKey($exception)) {
                throw $exception;
            }
            // 同键 INSERT 会等待首事务提交或回滚。首事务成功时这里命中唯一键，
            // 再读取已经提交且不可变的成功回执；首事务回滚时本次 INSERT 会直接成为新占位。
            // 这里不能再把重复键语句留下的共享锁升级为 FOR UPDATE，多名等待者会互相死锁。
            $receipt = $this->row(Db::name(self::RECEIPT_TABLE)
                ->where('idempotency_key', $receiptKey)
                ->find());
            if ($receipt === null) {
                throw $exception;
            }
            return $this->operationFromReceipt(
                $receipt,
                $tenantId,
                $idempotencyKey,
                $action,
                $requestFingerprint,
                $operationStoreId,
                $actorStaffId,
                $contextHash
            );
        }
    }

    public function completeCommandReceipt(array $operation): void
    {
        $tenantId = (string)($operation['tenant_id'] ?? '');
        $idempotencyKey = (string)($operation['command_idempotency_key'] ?? '');
        $operationType = (string)($operation['operation_type'] ?? '');
        $fingerprint = (string)($operation['request_fingerprint'] ?? '');
        $operationKey = (string)($operation['operation_key'] ?? '');
        if ($tenantId === '' || $idempotencyKey === '' || $operationType === ''
            || $fingerprint === '' || $operationKey === '' || (int)($operation['id'] ?? 0) <= 0) {
            throw $this->repositoryConflict('commandReceipt', '命令回执完成参数不完整。');
        }
        $resultJson = self::encodeJson($operation);
        $affected = Db::name(self::RECEIPT_TABLE)
            ->where('idempotency_key', self::receiptKey($tenantId, $idempotencyKey))
            ->where('action', self::receiptAction($operationType))
            ->where('request_hash', $fingerprint)
            ->where('store_id', (int)($operation['operation_store_id'] ?? 0))
            ->where('operator_id', (int)($operation['actor_staff_id'] ?? 0))
            ->where('status', 0)
            ->update([
                'status' => self::RECEIPT_SUCCEEDED,
                'result_code' => 'CARE_SUCCEEDED',
                'result_message' => '客情命令执行成功。',
                'result_json' => $resultJson,
                'business_no' => $operationKey,
                'finish_time' => time(),
            ]);
        if ((int)$affected !== 1) {
            throw $this->repositoryConflict('commandReceipt', '命令回执完成写入失败。');
        }
    }

    public function findTaskByNaturalKey(string $tenantId, string $taskKey): ?array
    {
        return $this->row(Db::name(self::TASK_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('task_key', $taskKey)
            ->find());
    }

    public function findRecordByNaturalKey(string $tenantId, string $recordKey): ?array
    {
        return $this->row(Db::name(self::RECORD_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('record_key', $recordKey)
            ->find());
    }

    public function lockTask(string $tenantId, int $taskId): ?array
    {
        return $this->row(Db::name(self::TASK_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('id', $taskId)
            ->lock(true)
            ->find());
    }

    public function lockRecord(string $tenantId, int $recordId): ?array
    {
        return $this->row(Db::name(self::RECORD_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('id', $recordId)
            ->lock(true)
            ->find());
    }

    public function lockActiveAssignment(string $tenantId, int $businessStoreId, int $staffId): ?array
    {
        $staff = $this->row(Db::name('system_store_staff')
            ->where('id', $staffId)
            ->where('store_id', $businessStoreId)
            ->where('status', 1)
            ->where('is_del', 0)
            ->lock(true)
            ->find());
        if ($staff === null || (int)($staff['employee_id'] ?? 0) <= 0) {
            return null;
        }
        $employee = $this->row(Db::name('employee')
            ->where('id', (int)$staff['employee_id'])
            ->where('status', 1)
            ->where('is_del', 0)
            ->lock(true)
            ->find());
        if ($employee === null) {
            return null;
        }
        return [
            'store_id' => (int)$staff['store_id'],
            'staff_id' => (int)$staff['id'],
            'employee_id' => (int)$staff['employee_id'],
            'staff_name' => trim((string)($staff['staff_name'] ?? '')),
            'is_active' => 1,
        ];
    }

    public function reserveDocumentSequence(
        string $tenantId,
        string $businessDate,
        string $documentType
    ): int {
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $businessDate)
            || !in_array($documentType, ['TASK', 'RECORD'], true)) {
            throw $this->repositoryConflict('documentSequence', '客情正式单号序号参数无效。');
        }

        $where = [
            ['tenant_id', '=', $tenantId],
            ['business_date', '=', $businessDate],
            ['document_type', '=', $documentType],
        ];
        try {
            Db::name(self::DOCUMENT_SEQUENCE_TABLE)->insert([
                'tenant_id' => $tenantId,
                'business_date' => $businessDate,
                'document_type' => $documentType,
                'current_value' => 0,
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        } catch (\Throwable $exception) {
            if (!$this->isDuplicateKey($exception)) {
                throw $exception;
            }
        }

        $row = $this->row(Db::name(self::DOCUMENT_SEQUENCE_TABLE)
            ->where($where)
            ->lock(true)
            ->find());
        if ($row === null) {
            throw $this->repositoryConflict('documentSequence', '客情正式单号序号锁定失败。');
        }
        $current = (int)($row['current_value'] ?? 0);
        if ($current < 0 || $current >= 9999) {
            throw $this->repositoryConflict('documentSequence', '当日客情正式单号已达到上限。');
        }
        $next = $current + 1;
        $affected = Db::name(self::DOCUMENT_SEQUENCE_TABLE)
            ->where($where)
            ->where('current_value', $current)
            ->update(['current_value' => $next, 'updated_at' => time()]);
        if ((int)$affected !== 1) {
            throw $this->repositoryConflict('documentSequence', '客情正式单号序号更新冲突。');
        }
        return $next;
    }

    public function insertTask(array $row): array
    {
        return $this->insertRow(self::TASK_TABLE, $row, 'task');
    }

    public function updateTaskState(
        string $tenantId,
        int $taskId,
        int $expectedVersion,
        string $expectedStatus,
        string $nextStatus,
        array $fields
    ): bool {
        $this->assertOnlyFields($fields, [
            'version','started_at','completed_at','voided_at','updated_at',
        ], 'taskState');
        $fields['status'] = $nextStatus;
        return (int)Db::name(self::TASK_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('id', $taskId)
            ->where('version', $expectedVersion)
            ->where('status', $expectedStatus)
            ->update($fields) === 1;
    }

    public function updateTaskVisibility(
        string $tenantId,
        int $taskId,
        int $expectedVersion,
        int $expectedVisibility,
        array $fields
    ): bool {
        $this->assertOnlyFields($fields, [
            'is_visible','deleted_at','version','updated_at',
        ], 'taskVisibility');
        return (int)Db::name(self::TASK_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('id', $taskId)
            ->where('version', $expectedVersion)
            ->where('is_visible', $expectedVisibility)
            ->update($fields) === 1;
    }

    public function updateTaskOwner(
        string $tenantId,
        int $taskId,
        int $expectedVersion,
        int $expectedOwnerStaffId,
        array $fields
    ): bool {
        $this->assertOnlyFields($fields, [
            'owner_staff_id','owner_employee_id','owner_name_snapshot','version','updated_at',
        ], 'taskOwner');
        return (int)Db::name(self::TASK_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('id', $taskId)
            ->where('version', $expectedVersion)
            ->where('owner_staff_id', $expectedOwnerStaffId)
            ->update($fields) === 1;
    }

    public function bumpTaskVersion(string $tenantId, int $taskId, int $expectedVersion, array $fields): bool
    {
        $this->assertOnlyFields($fields, ['version','updated_at'], 'taskVersion');
        return (int)Db::name(self::TASK_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('id', $taskId)
            ->where('version', $expectedVersion)
            ->update($fields) === 1;
    }

    public function insertRecord(array $row): array
    {
        return $this->insertRow(self::RECORD_TABLE, $row, 'record');
    }

    public function voidRecordStatus(
        string $tenantId,
        int $recordId,
        int $expectedVersion,
        string $expectedStatus,
        array $fields
    ): bool {
        $this->assertOnlyFields($fields, [
            'status','version','voided_at','voided_by_staff_id','void_reason','updated_at',
        ], 'recordStatus');
        return (int)Db::name(self::RECORD_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('id', $recordId)
            ->where('version', $expectedVersion)
            ->where('status', $expectedStatus)
            ->update($fields) === 1;
    }

    public function insertOperation(array $row): array
    {
        return $this->insertRow(self::OPERATION_TABLE, $row, 'operation');
    }

    private function insertRow(string $table, array $row, string $resource): array
    {
        try {
            $id = (int)Db::name($table)->insertGetId($row);
        } catch (\Throwable $exception) {
            if ($this->isDuplicateKey($exception)) {
                throw new CustomerCareDomainException(
                    $resource === 'operation'
                        ? CustomerCareErrorCode::IDEMPOTENCY_CONFLICT
                        : CustomerCareErrorCode::NATURAL_KEY_CONFLICT,
                    '客情业务唯一键已经存在。',
                    ['resource' => $resource]
                );
            }
            throw $exception;
        }
        if ($id <= 0) {
            throw $this->repositoryConflict($resource, '客情数据写入未返回有效标识。');
        }
        $row['id'] = $id;
        return $row;
    }

    private function operationFromReceipt(
        array $receipt,
        string $tenantId,
        string $idempotencyKey,
        string $action,
        string $requestFingerprint,
        int $operationStoreId,
        int $actorStaffId,
        string $contextHash
    ): array {
        if ((string)($receipt['action'] ?? '') !== $action
            || !hash_equals((string)($receipt['request_hash'] ?? ''), $requestFingerprint)
            || !hash_equals((string)($receipt['contexts_hash'] ?? ''), $contextHash)
            || (int)($receipt['store_id'] ?? 0) !== $operationStoreId
            || (int)($receipt['operator_id'] ?? 0) !== $actorStaffId) {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::IDEMPOTENCY_CONFLICT,
                '同一客情请求标识不能用于不同命令或操作上下文。'
            );
        }
        if ((int)($receipt['status'] ?? 0) !== self::RECEIPT_SUCCEEDED) {
            throw $this->repositoryConflict('commandReceipt', '命令回执处于未完成状态。');
        }
        $operation = json_decode((string)($receipt['result_json'] ?? ''), true);
        if (!is_array($operation) || (int)($operation['id'] ?? 0) <= 0) {
            throw $this->repositoryConflict('commandReceipt', '命令回执缺少确定的 operation。');
        }
        if ((string)($operation['tenant_id'] ?? '') !== $tenantId
            || (string)($operation['command_idempotency_key'] ?? '') !== $idempotencyKey
            || (string)($operation['operation_type'] ?? '') === ''
            || (string)($operation['request_fingerprint'] ?? '') === ''
            || (int)($operation['operation_store_id'] ?? 0) !== $operationStoreId
            || (int)($operation['actor_staff_id'] ?? 0) !== $actorStaffId
            || self::receiptAction((string)$operation['operation_type']) !== $action
            || !hash_equals((string)$operation['request_fingerprint'], $requestFingerprint)) {
            throw $this->repositoryConflict('commandReceipt', '命令回执 operation 与冻结上下文不一致。');
        }
        $persisted = $this->row(Db::name(self::OPERATION_TABLE)
            ->where('id', (int)$operation['id'])
            ->where('tenant_id', $tenantId)
            ->where('command_idempotency_key', $idempotencyKey)
            ->find());
        if ($persisted === null || !$this->operationSnapshotMatches($operation, $persisted)) {
            throw $this->repositoryConflict('commandReceipt', '命令回执与不可变 operation 不一致。');
        }
        return $persisted;
    }

    private function operationSnapshotMatches(array $receiptOperation, array $persisted): bool
    {
        foreach ([
            'operation_key','command_idempotency_key','request_fingerprint','tenant_id',
            'organization_id','organization_path','organization_name_snapshot',
            'business_store_name_snapshot','operation_store_name_snapshot',
            'operation_organization_id','operation_organization_path',
            'operation_organization_name_snapshot','member_name_snapshot','operation_type',
            'task_status_before','task_status_after','record_status_before','record_status_after',
            'owner_name_snapshot_before','owner_name_snapshot_after','actor_name_snapshot','reason',
        ] as $field) {
            if ((string)($receiptOperation[$field] ?? '') !== (string)($persisted[$field] ?? '')) {
                return false;
            }
        }
        foreach ([
            'id','business_store_id','operation_store_id','task_id','record_id',
            'next_task_id_after','next_task_version_after','member_id',
            'task_version_before','task_version_after','record_version_before','record_version_after',
            'owner_staff_id_before','owner_staff_id_after','owner_employee_id_before',
            'owner_employee_id_after','is_visible_before','is_visible_after','actor_staff_id',
            'actor_employee_id','occurred_at','recorded_at',
        ] as $field) {
            if ((int)($receiptOperation[$field] ?? 0) !== (int)($persisted[$field] ?? 0)) {
                return false;
            }
        }
        return true;
    }

    private static function receiptKey(string $tenantId, string $idempotencyKey): string
    {
        return 'CARE_RECEIPT-' . self::uuidFromHash(hash(
            'sha256',
            $tenantId . "\0" . $idempotencyKey
        ));
    }

    private static function receiptStateContextId(string $tenantId): string
    {
        return 'CARE_CONTEXT-' . self::uuidFromHash(hash('sha256', $tenantId));
    }

    private static function uuidFromHash(string $hash): string
    {
        $hex = strtolower(substr($hash, 0, 32));
        $hex[12] = '4';
        $hex[16] = '8';
        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    private static function receiptAction(string $operationType): string
    {
        return 'CARE_' . $operationType;
    }

    private static function encodeJson(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::REPOSITORY_CONFLICT,
                '客情命令回执无法序列化。'
            );
        }
        return $json;
    }

    private function row($value): ?array
    {
        if ($value === null || $value === false || $value === []) {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value) && method_exists($value, 'toArray')) {
            $row = $value->toArray();
            return is_array($row) ? $row : null;
        }
        return null;
    }

    private function assertOnlyFields(array $fields, array $allowed, string $resource): void
    {
        $unexpected = array_values(array_diff(array_keys($fields), $allowed));
        if ($unexpected !== []) {
            throw $this->repositoryConflict($resource, '客情受限更新包含未授权字段。');
        }
    }

    private function isDuplicateKey(\Throwable $exception): bool
    {
        $cursor = $exception;
        do {
            if ((int)$cursor->getCode() === 1062) {
                return true;
            }
            if (method_exists($cursor, 'getData')) {
                $data = $cursor->getData();
                $driver = is_array($data)
                    ? ($data['PDO Error Info']['Driver Error Code'] ?? null)
                    : null;
                if ((int)$driver === 1062) {
                    return true;
                }
            }
            $message = $cursor->getMessage();
            if (preg_match('/(?:^|[^0-9])1062(?:[^0-9]|$).*Duplicate entry/i', $message)
                || preg_match('/Duplicate entry.*for key/i', $message)) {
                return true;
            }
            $cursor = $cursor->getPrevious();
        } while ($cursor instanceof \Throwable);
        return false;
    }

    private function repositoryConflict(string $resource, string $message): CustomerCareDomainException
    {
        return new CustomerCareDomainException(
            CustomerCareErrorCode::REPOSITORY_CONFLICT,
            $message,
            ['resource' => $resource]
        );
    }
}
