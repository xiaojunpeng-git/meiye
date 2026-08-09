<?php

$backendRoot = getenv('CUSTOMER_CARE_BACKEND_ROOT');
$backendRoot = is_string($backendRoot) && trim($backendRoot) !== ''
    ? rtrim($backendRoot, '/')
    : dirname(__DIR__, 3) . '/后端代码';
$source = $backendRoot . '/app/services/customer/care';
foreach ([
    'CustomerCareErrorCode.php',
    'CustomerCareDomainException.php',
    'CustomerCareCodeRegistry.php',
    'CustomerCareTaskState.php',
    'CustomerCareRecordState.php',
    'CustomerCareClock.php',
    'CustomerCareRepository.php',
    'CustomerCareCommandContract.php',
    'CustomerCareCommandService.php',
] as $file) {
    require_once $source . '/' . $file;
}

use app\services\customer\care\CustomerCareClock;
use app\services\customer\care\CustomerCareCommandService;
use app\services\customer\care\CustomerCareDomainException;
use app\services\customer\care\CustomerCareErrorCode;
use app\services\customer\care\CustomerCareRecordState;
use app\services\customer\care\CustomerCareRepository;
use app\services\customer\care\CustomerCareTaskState;

$passed = 0;
$failed = 0;

function careOk(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail !== '' ? " -> {$detail}" : '') . "\n";
}

function careRejects(string $name, string $code, callable $callable): void
{
    try {
        $callable();
    } catch (CustomerCareDomainException $exception) {
        careOk(
            $name,
            $exception->getErrorCode() === $code,
            'expected=' . $code . ' actual=' . $exception->getErrorCode()
        );
        return;
    } catch (\Throwable $throwable) {
        careOk($name, false, get_class($throwable) . ': ' . $throwable->getMessage());
        return;
    }
    careOk($name, false, 'no exception');
}

final class FixedCustomerCareClock implements CustomerCareClock
{
    /** @var int */
    private $now;

    public function __construct(int $now)
    {
        $this->now = $now;
    }

    public function now(): int
    {
        return $this->now;
    }

    public function businessDate(int $timestamp, string $timezone): string
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone($timezone))
            ->format('Y-m-d');
    }
}

final class InMemoryCustomerCareRepository implements CustomerCareRepository
{
    /** @var array<int,array> */
    public $tasks = [];
    /** @var array<int,array> */
    public $records = [];
    /** @var array<int,array> */
    public $operations = [];
    /** @var array<string,array> */
    public $assignments = [];
    /** @var array<int,array> */
    public $receiptClaimLog = [];
    /** @var array<int,int> */
    public $receiptCompletionLog = [];
    /** @var array<string,int> */
    public $documentSequences = [];
    /** @var int */
    private $taskSequence = 0;
    /** @var int */
    private $recordSequence = 0;
    /** @var int */
    private $operationSequence = 0;

    public function seedAssignment(
        string $tenantId,
        int $actualStoreId,
        int $staffId,
        int $employeeId,
        string $staffName,
        int $active = 1,
        ?int $lookupStoreId = null
    ): void {
        $lookupStoreId = $lookupStoreId === null ? $actualStoreId : $lookupStoreId;
        $this->assignments[$this->assignmentKey($tenantId, $lookupStoreId, $staffId)] = [
            'store_id' => $actualStoreId,
            'staff_id' => $staffId,
            'employee_id' => $employeeId,
            'staff_name' => $staffName,
            'is_active' => $active,
        ];
    }

    public function transaction(callable $callback)
    {
        $snapshot = serialize([
            $this->tasks,
            $this->records,
            $this->operations,
            $this->documentSequences,
            $this->taskSequence,
            $this->recordSequence,
            $this->operationSequence,
        ]);
        try {
            return $callback();
        } catch (\Throwable $throwable) {
            [
                $this->tasks,
                $this->records,
                $this->operations,
                $this->documentSequences,
                $this->taskSequence,
                $this->recordSequence,
                $this->operationSequence,
            ] = unserialize($snapshot);
            throw $throwable;
        }
    }

    public function claimCommandReceipt(
        string $tenantId,
        string $idempotencyKey,
        string $operationType,
        string $requestFingerprint,
        int $operationStoreId,
        int $actorStaffId,
        array $resourceContext
    ): ?array
    {
        $this->receiptClaimLog[] = [
            'tenantId' => $tenantId,
            'idempotencyKey' => $idempotencyKey,
            'operationType' => $operationType,
            'requestFingerprint' => $requestFingerprint,
            'operationStoreId' => $operationStoreId,
            'actorStaffId' => $actorStaffId,
            'resourceContext' => $resourceContext,
        ];
        foreach ($this->operations as $row) {
            if ($row['tenant_id'] === $tenantId
                && $row['command_idempotency_key'] === $idempotencyKey) {
                return $row;
            }
        }
        return null;
    }

    public function completeCommandReceipt(array $operation): void
    {
        $this->receiptCompletionLog[] = (int)($operation['id'] ?? 0);
    }

    public function findTaskByNaturalKey(string $tenantId, string $taskKey): ?array
    {
        foreach ($this->tasks as $row) {
            if ($row['tenant_id'] === $tenantId && $row['task_key'] === $taskKey) {
                return $row;
            }
        }
        return null;
    }

    public function findRecordByNaturalKey(string $tenantId, string $recordKey): ?array
    {
        foreach ($this->records as $row) {
            if ($row['tenant_id'] === $tenantId && $row['record_key'] === $recordKey) {
                return $row;
            }
        }
        return null;
    }

    public function lockTask(string $tenantId, int $taskId): ?array
    {
        $row = $this->tasks[$taskId] ?? null;
        return $row !== null && $row['tenant_id'] === $tenantId ? $row : null;
    }

    public function lockRecord(string $tenantId, int $recordId): ?array
    {
        $row = $this->records[$recordId] ?? null;
        return $row !== null && $row['tenant_id'] === $tenantId ? $row : null;
    }

    public function lockActiveAssignment(string $tenantId, int $businessStoreId, int $staffId): ?array
    {
        return $this->assignments[$this->assignmentKey($tenantId, $businessStoreId, $staffId)] ?? null;
    }

    public function reserveDocumentSequence(
        string $tenantId,
        string $businessDate,
        string $documentType
    ): int {
        $key = $tenantId . '|' . $businessDate . '|' . $documentType;
        $current = (int)($this->documentSequences[$key] ?? 0);
        if ($current >= 9999) {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::REPOSITORY_CONFLICT,
                'document sequence exhausted'
            );
        }
        $this->documentSequences[$key] = $current + 1;
        return $this->documentSequences[$key];
    }

    public function insertTask(array $row): array
    {
        foreach ($this->tasks as $existing) {
            if ($existing['tenant_id'] === $row['tenant_id']
                && ($existing['task_key'] === $row['task_key']
                    || $existing['task_no'] === $row['task_no']
                    || $existing['create_idempotency_key'] === $row['create_idempotency_key'])) {
                throw new CustomerCareDomainException(
                    CustomerCareErrorCode::NATURAL_KEY_CONFLICT,
                    'duplicate task'
                );
            }
        }
        $row['id'] = ++$this->taskSequence;
        $this->tasks[$row['id']] = $row;
        return $row;
    }

    public function updateTaskState(
        string $tenantId,
        int $taskId,
        int $expectedVersion,
        string $expectedStatus,
        string $nextStatus,
        array $fields
    ): bool {
        $row = $this->tasks[$taskId] ?? null;
        if ($row === null || $row['tenant_id'] !== $tenantId
            || $row['version'] !== $expectedVersion || $row['status'] !== $expectedStatus) {
            return false;
        }
        $fields['status'] = $nextStatus;
        $this->tasks[$taskId] = array_merge($row, $fields);
        return true;
    }

    public function updateTaskVisibility(
        string $tenantId,
        int $taskId,
        int $expectedVersion,
        int $expectedVisibility,
        array $fields
    ): bool {
        $row = $this->tasks[$taskId] ?? null;
        if ($row === null || $row['tenant_id'] !== $tenantId
            || $row['version'] !== $expectedVersion || $row['is_visible'] !== $expectedVisibility) {
            return false;
        }
        $this->tasks[$taskId] = array_merge($row, $fields);
        return true;
    }

    public function updateTaskOwner(
        string $tenantId,
        int $taskId,
        int $expectedVersion,
        int $expectedOwnerStaffId,
        array $fields
    ): bool {
        $row = $this->tasks[$taskId] ?? null;
        if ($row === null || $row['tenant_id'] !== $tenantId
            || $row['version'] !== $expectedVersion
            || $row['owner_staff_id'] !== $expectedOwnerStaffId) {
            return false;
        }
        $this->tasks[$taskId] = array_merge($row, $fields);
        return true;
    }

    public function bumpTaskVersion(string $tenantId, int $taskId, int $expectedVersion, array $fields): bool
    {
        $row = $this->tasks[$taskId] ?? null;
        if ($row === null || $row['tenant_id'] !== $tenantId || $row['version'] !== $expectedVersion) {
            return false;
        }
        $this->tasks[$taskId] = array_merge($row, $fields);
        return true;
    }

    public function insertRecord(array $row): array
    {
        foreach ($this->records as $existing) {
            if ($existing['tenant_id'] === $row['tenant_id']
                && ($existing['record_key'] === $row['record_key']
                    || $existing['record_no'] === $row['record_no']
                    || $existing['command_idempotency_key'] === $row['command_idempotency_key']
                    || ($existing['task_id'] !== null
                        && $row['task_id'] !== null
                        && $existing['task_id'] === $row['task_id']))) {
                throw new CustomerCareDomainException(
                    CustomerCareErrorCode::NATURAL_KEY_CONFLICT,
                    'duplicate record'
                );
            }
        }
        $row['id'] = ++$this->recordSequence;
        $this->records[$row['id']] = $row;
        return $row;
    }

    public function voidRecordStatus(
        string $tenantId,
        int $recordId,
        int $expectedVersion,
        string $expectedStatus,
        array $fields
    ): bool {
        $row = $this->records[$recordId] ?? null;
        if ($row === null || $row['tenant_id'] !== $tenantId
            || $row['version'] !== $expectedVersion || $row['status'] !== $expectedStatus) {
            return false;
        }
        $this->records[$recordId] = array_merge($row, $fields);
        return true;
    }

    public function insertOperation(array $row): array
    {
        foreach ($this->operations as $existing) {
            if ($existing['tenant_id'] === $row['tenant_id']
                && ($existing['operation_key'] === $row['operation_key']
                    || $existing['command_idempotency_key'] === $row['command_idempotency_key'])) {
                throw new CustomerCareDomainException(
                    CustomerCareErrorCode::IDEMPOTENCY_CONFLICT,
                    'duplicate operation'
                );
            }
        }
        $row['id'] = ++$this->operationSequence;
        $this->operations[$row['id']] = $row;
        return $row;
    }

    private function assignmentKey(string $tenantId, int $storeId, int $staffId): string
    {
        return $tenantId . '|' . $storeId . '|' . $staffId;
    }
}

function careUuid(int $sequence): string
{
    return '00000000-0000-4000-8000-' . str_pad((string)$sequence, 12, '0', STR_PAD_LEFT);
}

function careIdem(string $prefix, int $sequence): string
{
    return $prefix . '-' . careUuid($sequence);
}

function careActor(int $staffId = 101, bool $canReassign = true, array $stores = [8]): array
{
    return [
        'tenantId' => 'TENANT_1',
        'staffId' => $staffId,
        'employeeId' => $staffId + 1000,
        'staffName' => '员工' . $staffId,
        'operationStoreId' => 8,
        'operationStoreName' => '八号门店',
        'operationOrganizationId' => 'ORG_8',
        'operationOrganizationPath' => '/ROOT/ORG_8/',
        'operationOrganizationName' => '八号组织',
        'allowedBusinessStoreIds' => $stores,
        'canReassign' => $canReassign,
    ];
}

function careCreateCommand(int $sequence, int $ownerStaffId = 101): array
{
    return [
        'idempotencyKey' => careIdem('CARE_CREATE_TASK', $sequence),
        'taskKey' => 'CARE-TASK-' . str_pad((string)$sequence, 6, '0', STR_PAD_LEFT),
        'businessStoreId' => 8,
        'businessStoreName' => '八号门店',
        'businessOrganizationId' => 'ORG_8',
        'businessOrganizationPath' => '/ROOT/ORG_8/',
        'businessOrganizationName' => '八号组织',
        'memberId' => 500 + $sequence,
        'memberName' => '会员' . $sequence,
        'ownerStaffId' => $ownerStaffId,
        'taskType' => 'DAILY_FOLLOWUP',
        'sourceType' => 'MANUAL',
        'sourceId' => 'SOURCE-' . $sequence,
        'title' => '客情任务' . $sequence,
        'note' => '测试备注',
        'plannedAt' => 1785270000 + $sequence,
        'plannedTimezone' => 'Asia/Shanghai',
    ];
}

function careStandaloneRecordCommand(int $sequence, int $followedAt): array
{
    return [
        'idempotencyKey' => careIdem('CARE_CREATE_RECORD', $sequence),
        'recordKey' => 'CARE-DAILY-RECORD-' . str_pad((string)$sequence, 6, '0', STR_PAD_LEFT),
        'businessStoreId' => 8,
        'businessStoreName' => '八号门店',
        'businessOrganizationId' => 'ORG_8',
        'businessOrganizationPath' => '/ROOT/ORG_8/',
        'businessOrganizationName' => '八号组织',
        'businessTimezone' => 'Asia/Shanghai',
        'memberId' => 700 + $sequence,
        'memberName' => '日常会员' . $sequence,
        'recordType' => 'DAILY_FOLLOWUP',
        'followedAt' => $followedAt,
        'followupMethod' => 'WECHAT',
        'resultCode' => 'SATISFIED',
        'summary' => '日常客情跟进',
        'detail' => '顾客反馈已记录',
        'relatedBusinessType' => 'ORDER',
        'relatedBusinessId' => 'ORDER-' . $sequence,
        'relatedBusinessLabel' => '护理订单' . $sequence,
    ];
}

echo "== customer care core contract ==\n";

careOk(
    'task status has exactly four product states',
    CustomerCareTaskState::all() === ['UNSTARTED', 'IN_PROGRESS', 'COMPLETED', 'VOIDED']
);
careOk(
    'record status is append then void only',
    CustomerCareRecordState::all() === ['NORMAL', 'VOIDED']
);
careOk(
    'overdue uses planned instant',
    !CustomerCareTaskState::isOverdue(CustomerCareTaskState::UNSTARTED, 1000, 1000)
        && CustomerCareTaskState::isOverdue(CustomerCareTaskState::UNSTARTED, 999, 1000)
        && !CustomerCareTaskState::isOverdue(CustomerCareTaskState::COMPLETED, 999, 1000)
);
careRejects('unstarted task cannot be voided', CustomerCareErrorCode::INVALID_TASK_TRANSITION, static function (): void {
    CustomerCareTaskState::assertCanVoid(CustomerCareTaskState::UNSTARTED);
});
careRejects('in-progress task cannot be deleted', CustomerCareErrorCode::INVALID_TASK_TRANSITION, static function (): void {
    CustomerCareTaskState::assertCanDelete(CustomerCareTaskState::IN_PROGRESS);
});

$repository = new InMemoryCustomerCareRepository();
foreach ([101, 102, 900] as $staffId) {
    $repository->seedAssignment('TENANT_1', 8, $staffId, $staffId + 1000, '员工' . $staffId);
}
$repository->seedAssignment('TENANT_1', 9, 103, 1103, '跨店员工103', 1, 8);
$repository->seedAssignment('TENANT_1', 8, 104, 1104, '停用员工104', 0);
$service = new CustomerCareCommandService($repository, new FixedCustomerCareClock(1785286800));

$createOne = careCreateCommand(1);
$created = $service->createTask(careActor(), $createOne);
careOk('create task starts unstarted version one',
    $created['taskStatus'] === 'UNSTARTED' && $created['taskVersion'] === 1 && $created['isVisible']);
careOk('create task appends one operation', count($repository->tasks) === 1 && count($repository->operations) === 1);
careOk('new task receives an immutable business document number',
    ($repository->tasks[1]['task_no'] ?? '') === 'GJ2607290001');

$replayedCreate = $service->createTask(careActor(), $createOne);
careOk('same create idempotency replays stable receipt',
    $replayedCreate['replayed'] && $replayedCreate['operationId'] === $created['operationId']);
careOk('create replay does not duplicate rows', count($repository->tasks) === 1 && count($repository->operations) === 1);
careRejects('replay rechecks current business-store scope', CustomerCareErrorCode::BUSINESS_STORE_FORBIDDEN,
    static function () use ($service, $createOne): void {
        $service->createTask(careActor(101, true, [9]), $createOne);
    });

$changedCreate = $createOne;
$changedCreate['title'] = '不同标题';
careRejects('same idempotency with changed payload conflicts', CustomerCareErrorCode::IDEMPOTENCY_CONFLICT,
    static function () use ($service, $changedCreate): void {
        $service->createTask(careActor(), $changedCreate);
    });

$duplicateNatural = $createOne;
$duplicateNatural['idempotencyKey'] = careIdem('CARE_CREATE_TASK', 2);
careRejects('different idempotency cannot duplicate task natural key', CustomerCareErrorCode::NATURAL_KEY_CONFLICT,
    static function () use ($service, $duplicateNatural): void {
        $service->createTask(careActor(), $duplicateNatural);
    });

$otherOwnerCreate = careCreateCommand(3, 102);
careRejects('ordinary employee cannot create a task for another owner', CustomerCareErrorCode::REASSIGN_PERMISSION_REQUIRED,
    static function () use ($service, $otherOwnerCreate): void {
        $service->createTask(careActor(101, false), $otherOwnerCreate);
    });

careRejects('zero expectedVersion is contract error', CustomerCareErrorCode::INVALID_ARGUMENT,
    static function () use ($service): void {
        $service->startTask(careActor(), [
            'idempotencyKey' => careIdem('CARE_START_TASK', 10),
            'taskId' => 1,
            'expectedVersion' => 0,
        ]);
    });

careRejects('ordinary non-owner cannot start task', CustomerCareErrorCode::OWNER_REQUIRED,
    static function () use ($service): void {
        $service->startTask(careActor(102, false), [
            'idempotencyKey' => careIdem('CARE_START_TASK', 11),
            'taskId' => 1,
            'expectedVersion' => 1,
        ]);
    });

$startOne = [
    'idempotencyKey' => careIdem('CARE_START_TASK', 12),
    'taskId' => 1,
    'expectedVersion' => 1,
];
$started = $service->startTask(careActor(), $startOne);
careOk('owner starts task with legal state and version',
    $started['taskStatus'] === 'IN_PROGRESS' && $started['taskVersion'] === 2);
$startReplay = $service->startTask(careActor(), $startOne);
careOk('start replay does not advance version twice',
    $startReplay['replayed'] && $repository->tasks[1]['version'] === 2);

careRejects('stale task version conflicts', CustomerCareErrorCode::VERSION_CONFLICT,
    static function () use ($service): void {
        $service->completeTask(careActor(), [
            'idempotencyKey' => careIdem('CARE_COMPLETE_TASK', 13),
            'taskId' => 1,
            'expectedVersion' => 1,
            'recordKey' => 'CARE-RECORD-000013',
            'recordType' => 'FOLLOWUP',
            'followedAt' => 1785283200,
            'followupMethod' => 'PHONE',
            'resultCode' => 'SATISFIED',
            'summary' => '已完成回访',
            'detail' => '顾客反馈良好',
        ]);
    });

$completeOne = [
    'idempotencyKey' => careIdem('CARE_COMPLETE_TASK', 14),
    'taskId' => 1,
    'expectedVersion' => 2,
    'recordKey' => 'CARE-RECORD-000014',
    'recordType' => 'FOLLOWUP',
    'followedAt' => 1785283200,
    'followupMethod' => 'PHONE',
    'resultCode' => 'SATISFIED',
    'summary' => '已完成回访',
    'detail' => '顾客反馈良好',
    'relatedBusinessType' => 'ORDER',
    'relatedBusinessId' => 'SO-14',
    'relatedBusinessLabel' => '护理订单十四',
];
$completed = $service->completeTask(careActor(), $completeOne);
careOk('completion and immutable record commit together',
    $completed['taskStatus'] === 'COMPLETED'
        && $completed['taskVersion'] === 3
        && $completed['recordStatus'] === 'NORMAL'
        && count($repository->records) === 1);
careOk('formal record uses all four time meanings',
    $repository->records[1]['business_date'] === '2026-07-29'
        && $repository->records[1]['followed_at'] === 1785283200
        && $repository->records[1]['occurred_at'] === 1785286800
        && $repository->records[1]['settled_at'] === 1785286800
        && $repository->records[1]['recorded_at'] === 1785286800);
careOk('new record receives an immutable business document number',
    ($repository->records[1]['record_no'] ?? '') === 'KQ2607290001');
careOk('completion persists record type actual follower and related business',
    $repository->records[1]['record_type'] === 'FOLLOWUP'
        && $repository->records[1]['follower_staff_id'] === 101
        && $repository->records[1]['follower_employee_id'] === 1101
        && $repository->records[1]['follower_name_snapshot'] === '员工101'
        && $repository->records[1]['related_business_type'] === 'ORDER'
        && $repository->records[1]['related_business_id'] === 'SO-14'
        && $repository->records[1]['related_business_label_snapshot'] === '护理订单十四');
careOk('completion without a next task freezes an explicit empty linkage',
    $completed['nextTaskId'] === 0
        && $completed['nextTaskVersion'] === 0
        && $completed['nextTaskStatus'] === ''
        && $repository->records[1]['requires_next_followup'] === 0
        && $repository->records[1]['next_task_id'] === null
        && $repository->records[1]['next_planned_at'] === 0
        && $repository->records[1]['next_owner_staff_id'] === 0);

$recordContent = [
    $repository->records[1]['followup_method'],
    $repository->records[1]['result_code'],
    $repository->records[1]['summary'],
    $repository->records[1]['detail'],
    $repository->records[1]['record_type'],
    $repository->records[1]['followed_at'],
    $repository->records[1]['follower_staff_id'],
    $repository->records[1]['follower_employee_id'],
    $repository->records[1]['follower_name_snapshot'],
    $repository->records[1]['related_business_type'],
    $repository->records[1]['related_business_id'],
    $repository->records[1]['related_business_label_snapshot'],
    $repository->records[1]['occurred_at'],
    $repository->records[1]['settled_at'],
    $repository->records[1]['recorded_at'],
];
careRejects('only record creator may void formal record', CustomerCareErrorCode::OWNER_REQUIRED,
    static function () use ($service): void {
        $service->voidRecord(careActor(102), [
            'idempotencyKey' => careIdem('CARE_VOID_RECORD', 15),
            'taskId' => 1,
            'expectedVersion' => 3,
            'recordId' => 1,
            'expectedRecordVersion' => 1,
            'reason' => '错误记录',
        ]);
    });

$voidRecordCommand = [
    'idempotencyKey' => careIdem('CARE_VOID_RECORD', 16),
    'taskId' => 1,
    'expectedVersion' => 3,
    'recordId' => 1,
    'expectedRecordVersion' => 1,
    'reason' => '记录内容有误，保留原文并作废',
];
$voidedRecord = $service->voidRecord(careActor(), $voidRecordCommand);
careOk('record void preserves task terminal state and bumps versions',
    $voidedRecord['taskStatus'] === 'COMPLETED'
        && $voidedRecord['taskVersion'] === 4
        && $voidedRecord['recordStatus'] === 'VOIDED'
        && $voidedRecord['recordVersion'] === 2);
careOk('record content and original times remain immutable after void', $recordContent === [
    $repository->records[1]['followup_method'],
    $repository->records[1]['result_code'],
    $repository->records[1]['summary'],
    $repository->records[1]['detail'],
    $repository->records[1]['record_type'],
    $repository->records[1]['followed_at'],
    $repository->records[1]['follower_staff_id'],
    $repository->records[1]['follower_employee_id'],
    $repository->records[1]['follower_name_snapshot'],
    $repository->records[1]['related_business_type'],
    $repository->records[1]['related_business_id'],
    $repository->records[1]['related_business_label_snapshot'],
    $repository->records[1]['occurred_at'],
    $repository->records[1]['settled_at'],
    $repository->records[1]['recorded_at'],
]);
$voidReplay = $service->voidRecord(careActor(), $voidRecordCommand);
careOk('void record replay returns first determined result',
    $voidReplay['replayed'] && $voidReplay['taskVersion'] === 4 && count($repository->operations) === 4);

careRejects('voided record cannot be voided again with new command', CustomerCareErrorCode::INVALID_RECORD_TRANSITION,
    static function () use ($service): void {
        $service->voidRecord(careActor(), [
            'idempotencyKey' => careIdem('CARE_VOID_RECORD', 17),
            'taskId' => 1,
            'expectedVersion' => 4,
            'recordId' => 1,
            'expectedRecordVersion' => 2,
            'reason' => '再次作废',
        ]);
    });

$createdTwo = $service->createTask(careActor(), careCreateCommand(20));
$deleted = $service->deleteTask(careActor(), [
    'idempotencyKey' => careIdem('CARE_DELETE_TASK', 21),
    'taskId' => $createdTwo['taskId'],
    'expectedVersion' => 1,
    'reason' => '计划取消',
]);
careOk('delete is visibility tombstone without fake status',
    $deleted['taskStatus'] === 'UNSTARTED'
        && !$deleted['isVisible']
        && $repository->tasks[$createdTwo['taskId']]['deleted_at'] === 1785286800);
careRejects('deleted task cannot be started', CustomerCareErrorCode::INVALID_TASK_TRANSITION,
    static function () use ($service, $createdTwo): void {
        $service->startTask(careActor(), [
            'idempotencyKey' => careIdem('CARE_START_TASK', 22),
            'taskId' => $createdTwo['taskId'],
            'expectedVersion' => 2,
        ]);
    });

$createdThree = $service->createTask(careActor(), careCreateCommand(30));
careRejects('reassign requires injected management permission', CustomerCareErrorCode::REASSIGN_PERMISSION_REQUIRED,
    static function () use ($service, $createdThree): void {
        $service->reassignTask(careActor(900, false), [
            'idempotencyKey' => careIdem('CARE_REASSIGN_TASK', 31),
            'taskId' => $createdThree['taskId'],
            'expectedVersion' => 1,
            'targetStaffId' => 102,
            'reason' => '工作量调整',
        ]);
    });
careRejects('cross-store reassignment is rejected', CustomerCareErrorCode::CROSS_STORE_REASSIGN_FORBIDDEN,
    static function () use ($service, $createdThree): void {
        $service->reassignTask(careActor(900), [
            'idempotencyKey' => careIdem('CARE_REASSIGN_TASK', 32),
            'taskId' => $createdThree['taskId'],
            'expectedVersion' => 1,
            'targetStaffId' => 103,
            'reason' => '错误跨店尝试',
        ]);
    });
careRejects('inactive target assignment is rejected', CustomerCareErrorCode::ASSIGNEE_INACTIVE,
    static function () use ($service, $createdThree): void {
        $service->reassignTask(careActor(900), [
            'idempotencyKey' => careIdem('CARE_REASSIGN_TASK', 33),
            'taskId' => $createdThree['taskId'],
            'expectedVersion' => 1,
            'targetStaffId' => 104,
            'reason' => '错误停用人员尝试',
        ]);
    });

$reassigned = $service->reassignTask(careActor(900), [
    'idempotencyKey' => careIdem('CARE_REASSIGN_TASK', 34),
    'taskId' => $createdThree['taskId'],
    'expectedVersion' => 1,
    'targetStaffId' => 102,
    'reason' => '工作量调整',
]);
careOk('reassign changes owner and version but not status',
    $reassigned['taskStatus'] === 'UNSTARTED'
        && $reassigned['taskVersion'] === 2
        && $reassigned['ownerStaffId'] === 102);
careRejects('old owner without management permission loses task actions after reassignment', CustomerCareErrorCode::OWNER_REQUIRED,
    static function () use ($service, $createdThree): void {
        $service->startTask(careActor(101, false), [
            'idempotencyKey' => careIdem('CARE_START_TASK', 35),
            'taskId' => $createdThree['taskId'],
            'expectedVersion' => 2,
        ]);
    });

$startedThree = $service->startTask(careActor(102), [
    'idempotencyKey' => careIdem('CARE_START_TASK', 36),
    'taskId' => $createdThree['taskId'],
    'expectedVersion' => 2,
]);
$voidedTask = $service->voidTask(careActor(102), [
    'idempotencyKey' => careIdem('CARE_VOID_TASK', 37),
    'taskId' => $createdThree['taskId'],
    'expectedVersion' => $startedThree['taskVersion'],
    'reason' => '会员明确拒绝后续跟进',
]);
careOk('current owner may void in-progress task only',
    $voidedTask['taskStatus'] === 'VOIDED' && $voidedTask['taskVersion'] === 4);
careRejects('terminal task cannot restart', CustomerCareErrorCode::INVALID_TASK_TRANSITION,
    static function () use ($service, $createdThree): void {
        $service->startTask(careActor(102), [
            'idempotencyKey' => careIdem('CARE_START_TASK', 38),
            'taskId' => $createdThree['taskId'],
            'expectedVersion' => 4,
        ]);
    });

$standaloneOneCommand = careStandaloneRecordCommand(50, 1785200400);
$standaloneOne = $service->createRecord(careActor(102), $standaloneOneCommand);
careOk('standalone record has no fake task and returns operation receipt',
    $standaloneOne['taskId'] === 0
        && $standaloneOne['taskStatus'] === ''
        && $standaloneOne['recordStatus'] === 'NORMAL'
        && $repository->records[2]['task_id'] === null);
careOk('standalone business date follows followedAt while success times use now',
    $repository->records[2]['business_date'] === '2026-07-28'
        && $repository->records[2]['followed_at'] === 1785200400
        && $repository->records[2]['occurred_at'] === 1785286800
        && $repository->records[2]['settled_at'] === 1785286800
        && $repository->records[2]['recorded_at'] === 1785286800);
careOk('standalone record persists actual follower and related business',
    $repository->records[2]['record_type'] === 'DAILY_FOLLOWUP'
        && $repository->records[2]['follower_staff_id'] === 102
        && $repository->records[2]['follower_employee_id'] === 1102
        && $repository->records[2]['follower_name_snapshot'] === '员工102'
        && $repository->records[2]['owner_staff_id'] === 0
        && $repository->records[2]['related_business_id'] === 'ORDER-50');
$standaloneReplay = $service->createRecord(careActor(102), $standaloneOneCommand);
careOk('standalone record idempotency replay does not duplicate',
    $standaloneReplay['replayed']
        && $standaloneReplay['recordId'] === $standaloneOne['recordId']
        && count($repository->records) === 2);

$standaloneTwoCommand = careStandaloneRecordCommand(51, 1785286800);
$standaloneTwoCommand['relatedBusinessType'] = '';
$standaloneTwoCommand['relatedBusinessId'] = '';
$standaloneTwoCommand['relatedBusinessLabel'] = '';
$service->createRecord(careActor(), $standaloneTwoCommand);
$standaloneRows = array_filter($repository->records, static function (array $row): bool {
    return $row['task_id'] === null && $row['tenant_id'] === 'TENANT_1';
});
careOk('same tenant supports multiple standalone records', count($standaloneRows) === 2);
$voidStandalone = $service->voidRecord(careActor(), [
    'idempotencyKey' => careIdem('CARE_VOID_RECORD', 55),
    'recordId' => 3,
    'expectedRecordVersion' => 1,
    'reason' => '独立客情记录录入错误',
]);
careOk('standalone record can be voided without fake task zero-version context',
    $voidStandalone['taskId'] === 0
        && $voidStandalone['taskVersion'] === 0
        && $voidStandalone['recordStatus'] === 'VOIDED'
        && $repository->records[3]['status'] === 'VOIDED');

$futureRecord = careStandaloneRecordCommand(52, 1785286801);
careRejects('future followedAt is rejected', CustomerCareErrorCode::INVALID_ARGUMENT,
    static function () use ($service, $futureRecord): void {
        $service->createRecord(careActor(), $futureRecord);
    });
$oldRecord = careStandaloneRecordCommand(
    53,
    1785286800 - \app\services\customer\care\CustomerCareCommandContract::MAX_FOLLOWED_AT_AGE_SECONDS - 1
);
careRejects('followedAt older than technical history limit is rejected', CustomerCareErrorCode::INVALID_ARGUMENT,
    static function () use ($service, $oldRecord): void {
        $service->createRecord(careActor(), $oldRecord);
    });

$unknownCode = careStandaloneRecordCommand(56, 1785286800);
$unknownCode['resultCode'] = 'UNKNOWN_RESULT';
careRejects('unknown business code is rejected by backend registry', CustomerCareErrorCode::INVALID_ARGUMENT,
    static function () use ($service, $unknownCode): void {
        $service->createRecord(careActor(), $unknownCode);
    });

$duplicateTaskRecord = $repository->records[1];
$duplicateTaskRecord['record_key'] = 'CARE-RECORD-DUPLICATE-TASK';
$duplicateTaskRecord['command_idempotency_key'] = careIdem('CARE_COMPLETE_TASK', 54);
careRejects('same task still permits at most one formal record', CustomerCareErrorCode::NATURAL_KEY_CONFLICT,
    static function () use ($repository, $duplicateTaskRecord): void {
        $repository->insertRecord($duplicateTaskRecord);
    });

$repositoryMethods = get_class_methods(CustomerCareRepository::class);
careOk('repository exposes no formal record content overwrite port',
    !in_array('updateRecord', $repositoryMethods, true)
        && !in_array('updateRecordContent', $repositoryMethods, true));

$nextSource = $service->createTask(careActor(), careCreateCommand(60));
$nextSourceStarted = $service->startTask(careActor(), [
    'idempotencyKey' => careIdem('CARE_START_TASK', 61),
    'taskId' => $nextSource['taskId'],
    'expectedVersion' => $nextSource['taskVersion'],
]);
$nextCompletion = [
    'idempotencyKey' => careIdem('CARE_COMPLETE_TASK', 65),
    'taskId' => $nextSource['taskId'],
    'expectedVersion' => $nextSourceStarted['taskVersion'],
    'recordKey' => 'CARE-RECORD-WITH-NEXT-000065',
    'recordType' => 'DAILY_FOLLOWUP',
    'followedAt' => 1785283200,
    'followupMethod' => 'WECHAT',
    'resultCode' => 'FOLLOW_UP',
    'summary' => '客户需要后续继续跟进',
    'detail' => '已约定下一次联系时间',
    'createNextTask' => true,
    'nextPlannedAt' => 1785373200,
    'nextOwnerId' => 102,
];
$appointmentSource = $service->createTask(careActor(), careCreateCommand(66));
$appointmentStarted = $service->startTask(careActor(), [
    'idempotencyKey' => careIdem('CARE_START_TASK', 67),
    'taskId' => $appointmentSource['taskId'],
    'expectedVersion' => $appointmentSource['taskVersion'],
]);
$appointmentCompletion = $nextCompletion;
$appointmentCompletion['idempotencyKey'] = careIdem('CARE_COMPLETE_TASK', 68);
$appointmentCompletion['taskId'] = $appointmentSource['taskId'];
$appointmentCompletion['expectedVersion'] = $appointmentStarted['taskVersion'];
$appointmentCompletion['recordKey'] = 'CARE-RECORD-APPOINTMENT-000062';
$appointmentCompletion['resultCode'] = 'APPOINTMENT_SUCCESS';
$appointmentCompletion['createNextTask'] = false;
$appointmentCompletion['nextPlannedAt'] = '';
$appointmentCompletion['nextOwnerId'] = '';
$appointmentRecordCount = count($repository->records);
$appointmentResult = $service->completeTask(careActor(), $appointmentCompletion);
careOk('appointment success completes as a communication result without reservation side effects',
    $appointmentResult['taskStatus'] === CustomerCareTaskState::COMPLETED
        && $repository->tasks[$appointmentSource['taskId']]['status'] === CustomerCareTaskState::COMPLETED
        && $repository->records[(int)$appointmentResult['recordId']]['result_code'] === 'APPOINTMENT_SUCCESS'
        && $repository->records[(int)$appointmentResult['recordId']]['related_business_type'] !== 'RESERVATION'
        && count($repository->records) === $appointmentRecordCount + 1);

$pastNextCompletion = $nextCompletion;
$pastNextCompletion['idempotencyKey'] = careIdem('CARE_COMPLETE_TASK', 63);
$pastNextCompletion['recordKey'] = 'CARE-RECORD-PAST-NEXT-000063';
$pastNextCompletion['nextPlannedAt'] = 1785286800;
careRejects('next follow-up time must be in the future', CustomerCareErrorCode::INVALID_ARGUMENT,
    static function () use ($service, $pastNextCompletion): void {
        $service->completeTask(careActor(), $pastNextCompletion);
    });

$inactiveNextCompletion = $nextCompletion;
$inactiveNextCompletion['idempotencyKey'] = careIdem('CARE_COMPLETE_TASK', 64);
$inactiveNextCompletion['recordKey'] = 'CARE-RECORD-INACTIVE-NEXT-000064';
$inactiveNextCompletion['nextOwnerId'] = 104;
$taskCountBeforeRejectedNext = count($repository->tasks);
$recordCountBeforeRejectedNext = count($repository->records);
careRejects('inactive next-task owner rejects the whole completion', CustomerCareErrorCode::ASSIGNEE_INACTIVE,
    static function () use ($service, $inactiveNextCompletion): void {
        $service->completeTask(careActor(), $inactiveNextCompletion);
    });
careOk('rejected next task leaves current task record and task counts unchanged',
    $repository->tasks[$nextSource['taskId']]['status'] === 'IN_PROGRESS'
        && $repository->tasks[$nextSource['taskId']]['version'] === 2
        && count($repository->tasks) === $taskCountBeforeRejectedNext
        && count($repository->records) === $recordCountBeforeRejectedNext);

$completedWithNext = $service->completeTask(careActor(), $nextCompletion);
$nextTask = $repository->tasks[$completedWithNext['nextTaskId']] ?? null;
$nextRecord = $repository->records[$completedWithNext['recordId']] ?? null;
careOk('completion creates the next task in the same determined result',
    $completedWithNext['taskStatus'] === 'COMPLETED'
        && $completedWithNext['nextTaskId'] > 0
        && $completedWithNext['nextTaskVersion'] === 1
        && $completedWithNext['nextTaskStatus'] === 'UNSTARTED'
        && is_array($nextTask)
        && $nextTask['source_type'] === 'PREVIOUS_FOLLOWUP'
        && $nextTask['source_id'] === $nextCompletion['recordKey']
        && $nextTask['owner_staff_id'] === 102
        && $nextTask['owner_employee_id'] === 1102
        && $nextTask['planned_at'] === 1785373200);
careOk('generated next task has the next immutable task number',
    is_array($nextTask) && preg_match('/^GJ260729[0-9]{4}$/', (string)$nextTask['task_no']) === 1);
careOk('formal record freezes next task time and owner snapshots',
    is_array($nextRecord)
        && $nextRecord['requires_next_followup'] === 1
        && $nextRecord['next_task_id'] === $completedWithNext['nextTaskId']
        && $nextRecord['next_planned_at'] === 1785373200
        && $nextRecord['next_owner_staff_id'] === 102
        && $nextRecord['next_owner_employee_id'] === 1102
        && $nextRecord['next_owner_name_snapshot'] === '员工102');
$completedWithNextReplay = $service->completeTask(careActor(), $nextCompletion);
careOk('completion replay returns the same next task without duplication',
    $completedWithNextReplay['replayed']
        && $completedWithNextReplay['nextTaskId'] === $completedWithNext['nextTaskId']
        && $completedWithNextReplay['nextTaskVersion'] === 1
        && count($repository->tasks) === 6
        && count($repository->records) === 5
        && count($repository->operations) === 19);

careOk('all successful commands append immutable operations', count($repository->operations) === 19);
careOk('every successful operation completes the same transaction command receipt',
    $repository->receiptCompletionLog === array_column($repository->operations, 'id'));

$managerTask = $service->createTask(careActor(), careCreateCommand(111));
$managerStarted = $service->startTask(careActor(102), [
    'idempotencyKey' => careIdem('CARE_START_TASK', 111),
    'taskId' => $managerTask['taskId'],
    'expectedVersion' => $managerTask['taskVersion'],
]);
$managerVoided = $service->voidTask(careActor(102), [
    'idempotencyKey' => careIdem('CARE_VOID_TASK', 111),
    'taskId' => $managerTask['taskId'],
    'expectedVersion' => $managerStarted['taskVersion'],
    'reason' => '管理员代处理后作废测试任务',
]);
$operations = array_values($repository->operations);
$lastOperation = $operations[count($operations) - 1] ?? [];
careOk('manager can execute another employee task while owner remains unchanged and audit records the manager',
    $managerVoided['taskStatus'] === 'VOIDED'
        && $managerVoided['ownerStaffId'] === 101
        && (int)($lastOperation['actor_staff_id'] ?? 0) === 102);

echo "ASSERT_PASSED={$passed}\n";
echo "ASSERT_FAILED={$failed}\n";
if ($failed > 0) {
    exit(1);
}
echo "CUSTOMER_CARE_CORE_CONTRACT=PASS\n";
