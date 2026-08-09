<?php

namespace app\services\customer\care;

/**
 * 客情任务领域内核。
 *
 * 本服务不注册路由、不调用共享 Gateway、不写统一事件/事实。共享接入层后续必须
 * 在同一事务边界内适配本仓储端口，并继续负责真实登录权限与数据范围注入。
 */
final class CustomerCareCommandService
{
    public const CREATE_TASK = 'CREATE_TASK';
    public const CREATE_RECORD = 'CREATE_RECORD';
    public const START_TASK = 'START_TASK';
    public const COMPLETE_TASK = 'COMPLETE_TASK';
    public const DELETE_TASK = 'DELETE_TASK';
    public const VOID_TASK = 'VOID_TASK';
    public const REASSIGN_TASK = 'REASSIGN_TASK';
    public const VOID_RECORD = 'VOID_RECORD';

    /** @var CustomerCareRepository */
    private $repository;

    /** @var CustomerCareClock */
    private $clock;

    public function __construct(CustomerCareRepository $repository, CustomerCareClock $clock)
    {
        $this->repository = $repository;
        $this->clock = $clock;
    }

    public function createTask(array $actorInput, array $commandInput): array
    {
        $actor = CustomerCareCommandContract::normalizeActor($actorInput);
        $command = CustomerCareCommandContract::normalizeCreateTask($commandInput);
        $fingerprint = CustomerCareCommandContract::fingerprint(self::CREATE_TASK, $actor, $command);

        return $this->repository->transaction(function () use ($actor, $command, $fingerprint): array {
            $replayed = $this->claimAndReplay(self::CREATE_TASK, $actor, $command, $fingerprint);
            if ($replayed !== null) {
                return $replayed;
            }
            $this->assertBusinessStoreAllowed($actor, $command['businessStoreId']);
            if ($command['ownerStaffId'] !== $actor['staffId'] && $actor['canReassign'] !== true) {
                throw new CustomerCareDomainException(
                    CustomerCareErrorCode::REASSIGN_PERMISSION_REQUIRED,
                    '给其他员工创建客情任务需要任务指派权限。'
                );
            }
            if ($this->repository->findTaskByNaturalKey($actor['tenantId'], $command['taskKey']) !== null) {
                throw $this->naturalKeyConflict('taskKey', $command['taskKey']);
            }

            $assignment = $this->activeAssignment(
                $actor['tenantId'],
                $command['businessStoreId'],
                $command['ownerStaffId'],
                false
            );
            $now = $this->clock->now();
            $task = $this->repository->insertTask([
                'task_key' => $command['taskKey'],
                'task_no' => $this->nextDocumentNo(
                    $actor['tenantId'],
                    $now,
                    $command['plannedTimezone'],
                    'TASK'
                ),
                'create_idempotency_key' => $command['idempotencyKey'],
                'create_request_fingerprint' => $fingerprint,
                'tenant_id' => $actor['tenantId'],
                'organization_id' => $command['businessOrganizationId'],
                'organization_path' => $command['businessOrganizationPath'],
                'organization_name_snapshot' => $command['businessOrganizationName'],
                'business_store_id' => $command['businessStoreId'],
                'business_store_name_snapshot' => $command['businessStoreName'],
                'member_id' => $command['memberId'],
                'member_name_snapshot' => $command['memberName'],
                'owner_staff_id' => $assignment['staff_id'],
                'owner_employee_id' => $assignment['employee_id'],
                'owner_name_snapshot' => $assignment['staff_name'],
                'task_type' => $command['taskType'],
                'source_type' => $command['sourceType'],
                'source_id' => $command['sourceId'],
                'title' => $command['title'],
                'note' => $command['note'],
                'status' => CustomerCareTaskState::UNSTARTED,
                'is_visible' => 1,
                'version' => 1,
                'planned_at' => $command['plannedAt'],
                'planned_timezone' => $command['plannedTimezone'],
                'started_at' => 0,
                'completed_at' => 0,
                'voided_at' => 0,
                'deleted_at' => 0,
                'created_by_staff_id' => $actor['staffId'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->assertPersistedIdentity($task, 'task');

            $operation = $this->insertOperation($this->operationRow(
                $actor,
                $task,
                $command,
                $fingerprint,
                self::CREATE_TASK,
                '',
                CustomerCareTaskState::UNSTARTED,
                0,
                1,
                '',
                '',
                0,
                0,
                0,
                0,
                0,
                (int)$task['owner_staff_id'],
                0,
                (int)$task['owner_employee_id'],
                0,
                1,
                null,
                '',
                $now
            ));
            return $this->resultFromOperation($operation, false);
        });
    }

    public function startTask(array $actorInput, array $commandInput): array
    {
        $actor = CustomerCareCommandContract::normalizeActor($actorInput);
        $command = CustomerCareCommandContract::normalizeStartTask($commandInput);
        $fingerprint = CustomerCareCommandContract::fingerprint(self::START_TASK, $actor, $command);

        return $this->repository->transaction(function () use ($actor, $command, $fingerprint): array {
            $replayed = $this->claimAndReplay(self::START_TASK, $actor, $command, $fingerprint);
            if ($replayed !== null) {
                return $replayed;
            }
            $task = $this->taskForOwnerCommand($actor, $command);
            CustomerCareTaskState::assertCanStart((string)$task['status']);
            $now = $this->clock->now();
            $nextVersion = (int)$task['version'] + 1;
            $updated = $this->repository->updateTaskState(
                $actor['tenantId'],
                (int)$task['id'],
                (int)$task['version'],
                (string)$task['status'],
                CustomerCareTaskState::IN_PROGRESS,
                ['version' => $nextVersion, 'started_at' => $now, 'updated_at' => $now]
            );
            $this->assertCas($updated, $task);

            $operation = $this->insertOperation($this->operationRow(
                $actor,
                $task,
                $command,
                $fingerprint,
                self::START_TASK,
                (string)$task['status'],
                CustomerCareTaskState::IN_PROGRESS,
                (int)$task['version'],
                $nextVersion,
                '',
                '',
                0,
                0,
                0,
                0,
                (int)$task['owner_staff_id'],
                (int)$task['owner_staff_id'],
                (int)$task['owner_employee_id'],
                (int)$task['owner_employee_id'],
                1,
                1,
                null,
                '',
                $now
            ));
            return $this->resultFromOperation($operation, false);
        });
    }

    public function createRecord(array $actorInput, array $commandInput): array
    {
        $actor = CustomerCareCommandContract::normalizeActor($actorInput);
        $command = CustomerCareCommandContract::normalizeCreateRecord($commandInput);
        $fingerprint = CustomerCareCommandContract::fingerprint(self::CREATE_RECORD, $actor, $command);

        return $this->repository->transaction(function () use ($actor, $command, $fingerprint): array {
            $replayed = $this->claimAndReplay(self::CREATE_RECORD, $actor, $command, $fingerprint);
            if ($replayed !== null) {
                return $replayed;
            }
            $this->assertBusinessStoreAllowed($actor, $command['businessStoreId']);
            if ($this->repository->findRecordByNaturalKey(
                $actor['tenantId'],
                $command['recordKey']
            ) !== null) {
                throw $this->naturalKeyConflict('recordKey', $command['recordKey']);
            }
            $now = $this->clock->now();
            CustomerCareCommandContract::assertFollowedAt($command['followedAt'], $now);
            $record = $this->repository->insertRecord([
                'record_key' => $command['recordKey'],
                'record_no' => $this->nextDocumentNo(
                    $actor['tenantId'],
                    $now,
                    $command['businessTimezone'],
                    'RECORD'
                ),
                'command_idempotency_key' => $command['idempotencyKey'],
                'request_fingerprint' => $fingerprint,
                'tenant_id' => $actor['tenantId'],
                'organization_id' => $command['businessOrganizationId'],
                'organization_path' => $command['businessOrganizationPath'],
                'organization_name_snapshot' => $command['businessOrganizationName'],
                'business_store_id' => $command['businessStoreId'],
                'business_store_name_snapshot' => $command['businessStoreName'],
                'business_timezone_snapshot' => $command['businessTimezone'],
                'operation_store_id' => $actor['operationStoreId'],
                'operation_store_name_snapshot' => $actor['operationStoreName'],
                'operation_organization_id' => $actor['operationOrganizationId'],
                'operation_organization_path' => $actor['operationOrganizationPath'],
                'operation_organization_name_snapshot' => $actor['operationOrganizationName'],
                'task_id' => null,
                'member_id' => $command['memberId'],
                'member_name_snapshot' => $command['memberName'],
                'owner_staff_id' => 0,
                'owner_employee_id' => 0,
                'owner_name_snapshot' => '',
                'record_type' => $command['recordType'],
                'followup_method' => $command['followupMethod'],
                'result_code' => $command['resultCode'],
                'summary' => $command['summary'],
                'detail' => $command['detail'],
                'followed_at' => $command['followedAt'],
                'follower_staff_id' => $actor['staffId'],
                'follower_employee_id' => $actor['employeeId'],
                'follower_name_snapshot' => $actor['staffName'],
                'related_business_type' => $command['relatedBusinessType'],
                'related_business_id' => $command['relatedBusinessId'],
                'related_business_label_snapshot' => $command['relatedBusinessLabel'],
                'requires_next_followup' => 0,
                'next_task_id' => null,
                'next_planned_at' => 0,
                'next_owner_staff_id' => 0,
                'next_owner_employee_id' => 0,
                'next_owner_name_snapshot' => '',
                'status' => CustomerCareRecordState::NORMAL,
                'version' => 1,
                'business_date' => $this->clock->businessDate(
                    $command['followedAt'],
                    $command['businessTimezone']
                ),
                'occurred_at' => $now,
                'settled_at' => $now,
                'recorded_at' => $now,
                'voided_at' => 0,
                'voided_by_staff_id' => 0,
                'void_reason' => '',
                'created_by_staff_id' => $actor['staffId'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->assertPersistedIdentity($record, 'record');

            $subject = [
                'id' => 0,
                'organization_id' => $command['businessOrganizationId'],
                'organization_path' => $command['businessOrganizationPath'],
                'organization_name_snapshot' => $command['businessOrganizationName'],
                'business_store_id' => $command['businessStoreId'],
                'business_store_name_snapshot' => $command['businessStoreName'],
                'member_id' => $command['memberId'],
                'member_name_snapshot' => $command['memberName'],
                'owner_staff_id' => 0,
                'owner_employee_id' => 0,
                'owner_name_snapshot' => '',
                'is_visible' => 0,
            ];
            $operation = $this->insertOperation($this->operationRow(
                $actor,
                $subject,
                $command,
                $fingerprint,
                self::CREATE_RECORD,
                '',
                '',
                0,
                0,
                '',
                CustomerCareRecordState::NORMAL,
                0,
                1,
                0,
                (int)$record['id'],
                0,
                0,
                0,
                0,
                0,
                0,
                $record,
                '',
                $now
            ));
            return $this->resultFromOperation($operation, false);
        });
    }

    public function completeTask(array $actorInput, array $commandInput): array
    {
        $actor = CustomerCareCommandContract::normalizeActor($actorInput);
        $command = CustomerCareCommandContract::normalizeCompleteTask($commandInput);
        $fingerprint = CustomerCareCommandContract::fingerprint(self::COMPLETE_TASK, $actor, $command);

        return $this->repository->transaction(function () use ($actor, $command, $fingerprint): array {
            $replayed = $this->claimAndReplay(self::COMPLETE_TASK, $actor, $command, $fingerprint);
            if ($replayed !== null) {
                return $replayed;
            }
            $task = $this->taskForOwnerCommand($actor, $command);
            CustomerCareTaskState::assertCanComplete((string)$task['status']);
            if ($this->repository->findRecordByNaturalKey(
                $actor['tenantId'],
                $command['recordKey']
            ) !== null) {
                throw $this->naturalKeyConflict('recordKey', $command['recordKey']);
            }

            $now = $this->clock->now();
            CustomerCareCommandContract::assertFollowedAt($command['followedAt'], $now);
            $nextTaskIdentity = null;
            $nextAssignment = null;
            if ($command['createNextTask']) {
                CustomerCareCommandContract::assertNextPlannedAt($command['nextPlannedAt'], $now);
                $nextTaskIdentity = $this->nextTaskIdentity(
                    $actor['tenantId'],
                    $command['idempotencyKey']
                );
                if ($this->repository->findTaskByNaturalKey(
                    $actor['tenantId'],
                    $nextTaskIdentity['taskKey']
                ) !== null) {
                    throw $this->naturalKeyConflict('nextTaskKey', $nextTaskIdentity['taskKey']);
                }
                $nextAssignment = $this->activeAssignment(
                    $actor['tenantId'],
                    (int)$task['business_store_id'],
                    $command['nextOwnerId'],
                    false
                );
            }
            $nextVersion = (int)$task['version'] + 1;
            $updated = $this->repository->updateTaskState(
                $actor['tenantId'],
                (int)$task['id'],
                (int)$task['version'],
                (string)$task['status'],
                CustomerCareTaskState::COMPLETED,
                ['version' => $nextVersion, 'completed_at' => $now, 'updated_at' => $now]
            );
            $this->assertCas($updated, $task);

            $nextTask = null;
            if ($nextTaskIdentity !== null && $nextAssignment !== null) {
                $nextTask = $this->repository->insertTask([
                    'task_key' => $nextTaskIdentity['taskKey'],
                    'task_no' => $this->nextDocumentNo(
                        $actor['tenantId'],
                        $now,
                        (string)$task['planned_timezone'],
                        'TASK'
                    ),
                    'create_idempotency_key' => $nextTaskIdentity['idempotencyKey'],
                    'create_request_fingerprint' => $fingerprint,
                    'tenant_id' => $actor['tenantId'],
                    'organization_id' => (string)$task['organization_id'],
                    'organization_path' => (string)$task['organization_path'],
                    'organization_name_snapshot' => (string)$task['organization_name_snapshot'],
                    'business_store_id' => (int)$task['business_store_id'],
                    'business_store_name_snapshot' => (string)$task['business_store_name_snapshot'],
                    'member_id' => (int)$task['member_id'],
                    'member_name_snapshot' => (string)$task['member_name_snapshot'],
                    'owner_staff_id' => $nextAssignment['staff_id'],
                    'owner_employee_id' => $nextAssignment['employee_id'],
                    'owner_name_snapshot' => $nextAssignment['staff_name'],
                    'task_type' => (string)$task['task_type'],
                    'source_type' => 'PREVIOUS_FOLLOWUP',
                    'source_id' => $command['recordKey'],
                    'title' => (string)$task['title'],
                    'note' => '由上次跟进记录生成',
                    'status' => CustomerCareTaskState::UNSTARTED,
                    'is_visible' => 1,
                    'version' => 1,
                    'planned_at' => $command['nextPlannedAt'],
                    'planned_timezone' => (string)$task['planned_timezone'],
                    'started_at' => 0,
                    'completed_at' => 0,
                    'voided_at' => 0,
                    'deleted_at' => 0,
                    'created_by_staff_id' => $actor['staffId'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->assertPersistedIdentity($nextTask, 'nextTask');
            }

            $record = $this->repository->insertRecord([
                'record_key' => $command['recordKey'],
                'record_no' => $this->nextDocumentNo(
                    $actor['tenantId'],
                    $now,
                    (string)$task['planned_timezone'],
                    'RECORD'
                ),
                'command_idempotency_key' => $command['idempotencyKey'],
                'request_fingerprint' => $fingerprint,
                'tenant_id' => $actor['tenantId'],
                'organization_id' => (string)$task['organization_id'],
                'organization_path' => (string)$task['organization_path'],
                'organization_name_snapshot' => (string)$task['organization_name_snapshot'],
                'business_store_id' => (int)$task['business_store_id'],
                'business_store_name_snapshot' => (string)$task['business_store_name_snapshot'],
                'business_timezone_snapshot' => (string)$task['planned_timezone'],
                'operation_store_id' => $actor['operationStoreId'],
                'operation_store_name_snapshot' => $actor['operationStoreName'],
                'operation_organization_id' => $actor['operationOrganizationId'],
                'operation_organization_path' => $actor['operationOrganizationPath'],
                'operation_organization_name_snapshot' => $actor['operationOrganizationName'],
                'task_id' => (int)$task['id'],
                'member_id' => (int)$task['member_id'],
                'member_name_snapshot' => (string)$task['member_name_snapshot'],
                'owner_staff_id' => (int)$task['owner_staff_id'],
                'owner_employee_id' => (int)$task['owner_employee_id'],
                'owner_name_snapshot' => (string)$task['owner_name_snapshot'],
                'record_type' => $command['recordType'],
                'followup_method' => $command['followupMethod'],
                'result_code' => $command['resultCode'],
                'summary' => $command['summary'],
                'detail' => $command['detail'],
                'followed_at' => $command['followedAt'],
                'follower_staff_id' => $actor['staffId'],
                'follower_employee_id' => $actor['employeeId'],
                'follower_name_snapshot' => $actor['staffName'],
                'related_business_type' => $command['relatedBusinessType'],
                'related_business_id' => $command['relatedBusinessId'],
                'related_business_label_snapshot' => $command['relatedBusinessLabel'],
                'requires_next_followup' => $nextTask === null ? 0 : 1,
                'next_task_id' => $nextTask === null ? null : (int)$nextTask['id'],
                'next_planned_at' => $nextTask === null ? 0 : (int)$nextTask['planned_at'],
                'next_owner_staff_id' => $nextTask === null ? 0 : (int)$nextTask['owner_staff_id'],
                'next_owner_employee_id' => $nextTask === null ? 0 : (int)$nextTask['owner_employee_id'],
                'next_owner_name_snapshot' => $nextTask === null
                    ? ''
                    : (string)$nextTask['owner_name_snapshot'],
                'status' => CustomerCareRecordState::NORMAL,
                'version' => 1,
                'business_date' => $this->clock->businessDate(
                    $command['followedAt'],
                    (string)$task['planned_timezone']
                ),
                'occurred_at' => $now,
                'settled_at' => $now,
                'recorded_at' => $now,
                'voided_at' => 0,
                'voided_by_staff_id' => 0,
                'void_reason' => '',
                'created_by_staff_id' => $actor['staffId'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->assertPersistedIdentity($record, 'record');

            $operation = $this->insertOperation($this->operationRow(
                $actor,
                $task,
                $command,
                $fingerprint,
                self::COMPLETE_TASK,
                (string)$task['status'],
                CustomerCareTaskState::COMPLETED,
                (int)$task['version'],
                $nextVersion,
                '',
                CustomerCareRecordState::NORMAL,
                0,
                1,
                0,
                (int)$record['id'],
                (int)$task['owner_staff_id'],
                (int)$task['owner_staff_id'],
                (int)$task['owner_employee_id'],
                (int)$task['owner_employee_id'],
                1,
                1,
                $record,
                '',
                $now
            ));
            return $this->resultFromOperation($operation, false);
        });
    }

    public function deleteTask(array $actorInput, array $commandInput): array
    {
        $actor = CustomerCareCommandContract::normalizeActor($actorInput);
        $command = CustomerCareCommandContract::normalizeDeleteTask($commandInput);
        $fingerprint = CustomerCareCommandContract::fingerprint(self::DELETE_TASK, $actor, $command);

        return $this->repository->transaction(function () use ($actor, $command, $fingerprint): array {
            $replayed = $this->claimAndReplay(self::DELETE_TASK, $actor, $command, $fingerprint);
            if ($replayed !== null) {
                return $replayed;
            }
            $task = $this->taskForOwnerCommand($actor, $command);
            CustomerCareTaskState::assertCanDelete((string)$task['status']);
            $now = $this->clock->now();
            $nextVersion = (int)$task['version'] + 1;
            $updated = $this->repository->updateTaskVisibility(
                $actor['tenantId'],
                (int)$task['id'],
                (int)$task['version'],
                1,
                [
                    'is_visible' => 0,
                    'deleted_at' => $now,
                    'version' => $nextVersion,
                    'updated_at' => $now,
                ]
            );
            $this->assertCas($updated, $task);

            $operation = $this->insertOperation($this->operationRow(
                $actor,
                $task,
                $command,
                $fingerprint,
                self::DELETE_TASK,
                (string)$task['status'],
                (string)$task['status'],
                (int)$task['version'],
                $nextVersion,
                '',
                '',
                0,
                0,
                0,
                0,
                (int)$task['owner_staff_id'],
                (int)$task['owner_staff_id'],
                (int)$task['owner_employee_id'],
                (int)$task['owner_employee_id'],
                1,
                0,
                null,
                $command['reason'],
                $now
            ));
            return $this->resultFromOperation($operation, false);
        });
    }

    public function voidTask(array $actorInput, array $commandInput): array
    {
        $actor = CustomerCareCommandContract::normalizeActor($actorInput);
        $command = CustomerCareCommandContract::normalizeVoidTask($commandInput);
        $fingerprint = CustomerCareCommandContract::fingerprint(self::VOID_TASK, $actor, $command);

        return $this->repository->transaction(function () use ($actor, $command, $fingerprint): array {
            $replayed = $this->claimAndReplay(self::VOID_TASK, $actor, $command, $fingerprint);
            if ($replayed !== null) {
                return $replayed;
            }
            $task = $this->taskForOwnerCommand($actor, $command);
            CustomerCareTaskState::assertCanVoid((string)$task['status']);
            $now = $this->clock->now();
            $nextVersion = (int)$task['version'] + 1;
            $updated = $this->repository->updateTaskState(
                $actor['tenantId'],
                (int)$task['id'],
                (int)$task['version'],
                (string)$task['status'],
                CustomerCareTaskState::VOIDED,
                ['version' => $nextVersion, 'voided_at' => $now, 'updated_at' => $now]
            );
            $this->assertCas($updated, $task);

            $operation = $this->insertOperation($this->operationRow(
                $actor,
                $task,
                $command,
                $fingerprint,
                self::VOID_TASK,
                (string)$task['status'],
                CustomerCareTaskState::VOIDED,
                (int)$task['version'],
                $nextVersion,
                '',
                '',
                0,
                0,
                0,
                0,
                (int)$task['owner_staff_id'],
                (int)$task['owner_staff_id'],
                (int)$task['owner_employee_id'],
                (int)$task['owner_employee_id'],
                1,
                1,
                null,
                $command['reason'],
                $now
            ));
            return $this->resultFromOperation($operation, false);
        });
    }

    public function reassignTask(array $actorInput, array $commandInput): array
    {
        $actor = CustomerCareCommandContract::normalizeActor($actorInput);
        $command = CustomerCareCommandContract::normalizeReassignTask($commandInput);
        $fingerprint = CustomerCareCommandContract::fingerprint(self::REASSIGN_TASK, $actor, $command);

        return $this->repository->transaction(function () use ($actor, $command, $fingerprint): array {
            $replayed = $this->claimAndReplay(self::REASSIGN_TASK, $actor, $command, $fingerprint);
            if ($replayed !== null) {
                return $replayed;
            }
            if (!$actor['canReassign']) {
                throw new CustomerCareDomainException(
                    CustomerCareErrorCode::REASSIGN_PERMISSION_REQUIRED,
                    '当前账号没有客情任务转派权限。'
                );
            }
            $task = $this->taskForCommand($actor, $command);
            CustomerCareTaskState::assertCanReassign((string)$task['status']);
            if ((int)$task['owner_staff_id'] === $command['targetStaffId']) {
                throw new CustomerCareDomainException(
                    CustomerCareErrorCode::INVALID_ARGUMENT,
                    '客情任务已经由该员工负责。',
                    ['field' => 'targetStaffId']
                );
            }
            $assignment = $this->activeAssignment(
                $actor['tenantId'],
                (int)$task['business_store_id'],
                $command['targetStaffId'],
                true
            );
            $now = $this->clock->now();
            $nextVersion = (int)$task['version'] + 1;
            $updated = $this->repository->updateTaskOwner(
                $actor['tenantId'],
                (int)$task['id'],
                (int)$task['version'],
                (int)$task['owner_staff_id'],
                [
                    'owner_staff_id' => $assignment['staff_id'],
                    'owner_employee_id' => $assignment['employee_id'],
                    'owner_name_snapshot' => $assignment['staff_name'],
                    'version' => $nextVersion,
                    'updated_at' => $now,
                ]
            );
            $this->assertCas($updated, $task);

            $operationTask = $task;
            $operationTask['_owner_name_after'] = $assignment['staff_name'];
            $operation = $this->insertOperation($this->operationRow(
                $actor,
                $operationTask,
                $command,
                $fingerprint,
                self::REASSIGN_TASK,
                (string)$task['status'],
                (string)$task['status'],
                (int)$task['version'],
                $nextVersion,
                '',
                '',
                0,
                0,
                0,
                0,
                (int)$task['owner_staff_id'],
                (int)$assignment['staff_id'],
                (int)$task['owner_employee_id'],
                (int)$assignment['employee_id'],
                1,
                1,
                null,
                $command['reason'],
                $now
            ));
            return $this->resultFromOperation($operation, false);
        });
    }

    public function voidRecord(array $actorInput, array $commandInput): array
    {
        $actor = CustomerCareCommandContract::normalizeActor($actorInput);
        $command = CustomerCareCommandContract::normalizeVoidRecord($commandInput);
        $fingerprint = CustomerCareCommandContract::fingerprint(self::VOID_RECORD, $actor, $command);

        return $this->repository->transaction(function () use ($actor, $command, $fingerprint): array {
            $replayed = $this->claimAndReplay(self::VOID_RECORD, $actor, $command, $fingerprint);
            if ($replayed !== null) {
                return $replayed;
            }
            $task = null;
            if ($command['taskId'] !== null) {
                $task = $this->taskForCommand($actor, $command);
                if ((string)$task['status'] !== CustomerCareTaskState::COMPLETED) {
                    throw new CustomerCareDomainException(
                        CustomerCareErrorCode::INVALID_RECORD_TRANSITION,
                        '只有已完成任务的正式客情记录可以作废。',
                        ['taskStatus' => (string)$task['status']]
                    );
                }
            }
            $record = $this->repository->lockRecord($actor['tenantId'], $command['recordId']);
            if ($record === null) {
                throw new CustomerCareDomainException(
                    CustomerCareErrorCode::RECORD_NOT_FOUND,
                    '客情记录不存在。'
                );
            }
            $recordHasTask = array_key_exists('task_id', $record) && $record['task_id'] !== null;
            if (($task !== null && (!$recordHasTask || (int)$record['task_id'] !== (int)$task['id']))
                || ($task === null && $recordHasTask)) {
                throw new CustomerCareDomainException(
                    CustomerCareErrorCode::RECORD_NOT_FOUND,
                    '客情记录的任务关联与命令上下文不一致。'
                );
            }
            if ($task === null) {
                $this->assertBusinessStoreAllowed($actor, (int)($record['business_store_id'] ?? 0));
            }
            if ((int)($record['created_by_staff_id'] ?? 0) !== $actor['staffId']) {
                throw new CustomerCareDomainException(
                    CustomerCareErrorCode::OWNER_REQUIRED,
                    '只有客情记录创建人可以作废该记录。'
                );
            }
            $this->assertExpectedVersion(
                $command['expectedRecordVersion'],
                (int)($record['version'] ?? 0),
                'record'
            );
            CustomerCareRecordState::assertCanVoid((string)($record['status'] ?? ''));

            $now = $this->clock->now();
            $nextRecordVersion = (int)$record['version'] + 1;
            $recordUpdated = $this->repository->voidRecordStatus(
                $actor['tenantId'],
                (int)$record['id'],
                (int)$record['version'],
                CustomerCareRecordState::NORMAL,
                [
                    'status' => CustomerCareRecordState::VOIDED,
                    'version' => $nextRecordVersion,
                    'voided_at' => $now,
                    'voided_by_staff_id' => $actor['staffId'],
                    'void_reason' => $command['reason'],
                    'updated_at' => $now,
                ]
            );
            $this->assertRecordCas($recordUpdated, $record);

            $nextTaskVersion = 0;
            $operationSubject = $task;
            if ($task !== null) {
                $nextTaskVersion = (int)$task['version'] + 1;
                $taskUpdated = $this->repository->bumpTaskVersion(
                    $actor['tenantId'],
                    (int)$task['id'],
                    (int)$task['version'],
                    ['version' => $nextTaskVersion, 'updated_at' => $now]
                );
                $this->assertCas($taskUpdated, $task);
            } else {
                $operationSubject = [
                    'id' => 0,
                    'organization_id' => (string)$record['organization_id'],
                    'organization_path' => (string)$record['organization_path'],
                    'organization_name_snapshot' => (string)$record['organization_name_snapshot'],
                    'business_store_id' => (int)$record['business_store_id'],
                    'business_store_name_snapshot' => (string)$record['business_store_name_snapshot'],
                    'member_id' => (int)$record['member_id'],
                    'member_name_snapshot' => (string)$record['member_name_snapshot'],
                    'owner_staff_id' => 0,
                    'owner_employee_id' => 0,
                    'owner_name_snapshot' => '',
                    'is_visible' => 0,
                ];
            }

            $operation = $this->insertOperation($this->operationRow(
                $actor,
                $operationSubject,
                $command,
                $fingerprint,
                self::VOID_RECORD,
                $task === null ? '' : (string)$task['status'],
                $task === null ? '' : (string)$task['status'],
                $task === null ? 0 : (int)$task['version'],
                $nextTaskVersion,
                (string)$record['status'],
                CustomerCareRecordState::VOIDED,
                (int)$record['version'],
                $nextRecordVersion,
                (int)$record['id'],
                (int)$record['id'],
                $task === null ? 0 : (int)$task['owner_staff_id'],
                $task === null ? 0 : (int)$task['owner_staff_id'],
                $task === null ? 0 : (int)$task['owner_employee_id'],
                $task === null ? 0 : (int)$task['owner_employee_id'],
                $task === null ? 0 : (int)$task['is_visible'],
                $task === null ? 0 : (int)$task['is_visible'],
                $record,
                $command['reason'],
                $now
            ));
            return $this->resultFromOperation($operation, false);
        });
    }

    private function taskForOwnerCommand(array $actor, array $command): array
    {
        $task = $this->taskForCommand($actor, $command);
        if ((int)$task['owner_staff_id'] !== $actor['staffId'] && !$actor['canReassign']) {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::OWNER_REQUIRED,
                '只有当前负责人或门店管理员可以执行该客情任务操作。'
            );
        }
        return $task;
    }

    private function taskForCommand(array $actor, array $command): array
    {
        $task = $this->repository->lockTask($actor['tenantId'], $command['taskId']);
        if ($task === null) {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::TASK_NOT_FOUND,
                '客情任务不存在。'
            );
        }
        $this->assertBusinessStoreAllowed($actor, (int)($task['business_store_id'] ?? 0));
        $this->assertExpectedVersion(
            $command['expectedVersion'],
            (int)($task['version'] ?? 0),
            'task'
        );
        CustomerCareTaskState::assertKnown((string)($task['status'] ?? ''));
        $visibility = (int)($task['is_visible'] ?? -1);
        if ($visibility !== 1) {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::INVALID_TASK_TRANSITION,
                '已删除的客情任务不能继续操作。',
                ['isVisible' => $visibility]
            );
        }
        return $task;
    }

    private function activeAssignment(
        string $tenantId,
        int $businessStoreId,
        int $staffId,
        bool $isReassign
    ): array {
        $assignment = $this->repository->lockActiveAssignment($tenantId, $businessStoreId, $staffId);
        if ($assignment === null || (int)($assignment['is_active'] ?? 0) !== 1) {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::ASSIGNEE_INACTIVE,
                '目标负责人未在任务业务门店有效任职。',
                ['staffId' => $staffId, 'businessStoreId' => $businessStoreId]
            );
        }
        if ((int)($assignment['store_id'] ?? 0) !== $businessStoreId) {
            throw new CustomerCareDomainException(
                $isReassign
                    ? CustomerCareErrorCode::CROSS_STORE_REASSIGN_FORBIDDEN
                    : CustomerCareErrorCode::ASSIGNEE_INACTIVE,
                $isReassign ? '首期不允许跨店转派客情任务。' : '负责人不属于任务业务门店。',
                [
                    'staffId' => $staffId,
                    'businessStoreId' => $businessStoreId,
                    'assignmentStoreId' => (int)($assignment['store_id'] ?? 0),
                ]
            );
        }
        if ((int)($assignment['staff_id'] ?? 0) !== $staffId
            || (int)($assignment['employee_id'] ?? 0) <= 0
            || trim((string)($assignment['staff_name'] ?? '')) === '') {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::ASSIGNEE_INACTIVE,
                '目标负责人任职信息不完整。',
                ['staffId' => $staffId]
            );
        }
        return [
            'staff_id' => $staffId,
            'employee_id' => (int)$assignment['employee_id'],
            'staff_name' => trim((string)$assignment['staff_name']),
        ];
    }

    private function assertBusinessStoreAllowed(array $actor, int $businessStoreId): void
    {
        if ($businessStoreId <= 0
            || !in_array($businessStoreId, $actor['allowedBusinessStoreIds'], true)) {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::BUSINESS_STORE_FORBIDDEN,
                '当前账号无权操作该业务门店的客情任务。',
                ['businessStoreId' => $businessStoreId]
            );
        }
    }

    private function assertExpectedVersion(int $expected, int $actual, string $resource): void
    {
        if ($expected <= 0 || $actual <= 0 || $expected !== $actual) {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::VERSION_CONFLICT,
                '客情数据已变化，请刷新后重试。',
                ['resource' => $resource, 'expectedVersion' => $expected, 'actualVersion' => $actual]
            );
        }
    }

    private function claimAndReplay(
        string $operationType,
        array $actor,
        array $command,
        string $fingerprint
    ): ?array {
        $operation = $this->repository->claimCommandReceipt(
            $actor['tenantId'],
            $command['idempotencyKey'],
            $operationType,
            $fingerprint,
            $actor['operationStoreId'],
            $actor['staffId'],
            $this->receiptResourceContext($operationType, $actor, $command)
        );
        if ($operation === null) {
            return null;
        }
        if ((string)($operation['operation_type'] ?? '') !== $operationType
            || !hash_equals((string)($operation['request_fingerprint'] ?? ''), $fingerprint)) {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::IDEMPOTENCY_CONFLICT,
                '同一客情请求标识不能用于不同命令。',
                ['idempotencyKey' => $command['idempotencyKey']]
            );
        }
        $this->assertBusinessStoreAllowed($actor, (int)($operation['business_store_id'] ?? 0));
        return $this->resultFromOperation($operation, true);
    }

    private function receiptResourceContext(
        string $operationType,
        array $actor,
        array $command
    ): array {
        $context = [
            'domain' => 'customer-care',
            'tenantId' => $actor['tenantId'],
            'operationType' => $operationType,
            'operationStoreId' => $actor['operationStoreId'],
            'actorStaffId' => $actor['staffId'],
        ];
        foreach ([
            'businessStoreId', 'taskId', 'expectedVersion', 'recordId',
            'expectedRecordVersion', 'targetStaffId',
        ] as $field) {
            if (array_key_exists($field, $command) && $command[$field] !== null) {
                $context[$field] = $command[$field];
            }
        }
        if (($command['createNextTask'] ?? false) === true) {
            $context['nextOwnerId'] = $command['nextOwnerId'];
        }
        return $context;
    }

    private function operationRow(
        array $actor,
        array $task,
        array $command,
        string $fingerprint,
        string $operationType,
        string $taskStatusBefore,
        string $taskStatusAfter,
        int $taskVersionBefore,
        int $taskVersionAfter,
        string $recordStatusBefore,
        string $recordStatusAfter,
        int $recordVersionBefore,
        int $recordVersionAfter,
        int $recordIdBefore,
        int $recordIdAfter,
        int $ownerStaffBefore,
        int $ownerStaffAfter,
        int $ownerEmployeeBefore,
        int $ownerEmployeeAfter,
        int $visibleBefore,
        int $visibleAfter,
        ?array $record,
        string $reason,
        int $now
    ): array {
        $recordId = $recordIdAfter > 0 ? $recordIdAfter : $recordIdBefore;
        return [
            'operation_key' => 'OP-' . substr(hash('sha256', $command['idempotencyKey']), 0, 48),
            'command_idempotency_key' => $command['idempotencyKey'],
            'request_fingerprint' => $fingerprint,
            'tenant_id' => $actor['tenantId'],
            'organization_id' => (string)$task['organization_id'],
            'organization_path' => (string)$task['organization_path'],
            'organization_name_snapshot' => (string)$task['organization_name_snapshot'],
            'business_store_id' => (int)$task['business_store_id'],
            'business_store_name_snapshot' => (string)$task['business_store_name_snapshot'],
            'operation_store_id' => $actor['operationStoreId'],
            'operation_store_name_snapshot' => $actor['operationStoreName'],
            'operation_organization_id' => $actor['operationOrganizationId'],
            'operation_organization_path' => $actor['operationOrganizationPath'],
            'operation_organization_name_snapshot' => $actor['operationOrganizationName'],
            'task_id' => (int)$task['id'],
            'record_id' => $recordId,
            'next_task_id_after' => (int)($record['next_task_id'] ?? 0),
            'next_task_version_after' => (int)($record['next_task_id'] ?? 0) > 0 ? 1 : 0,
            'member_id' => (int)$task['member_id'],
            'member_name_snapshot' => (string)$task['member_name_snapshot'],
            'operation_type' => $operationType,
            'task_status_before' => $taskStatusBefore,
            'task_status_after' => $taskStatusAfter,
            'record_status_before' => $recordStatusBefore,
            'record_status_after' => $recordStatusAfter,
            'task_version_before' => $taskVersionBefore,
            'task_version_after' => $taskVersionAfter,
            'record_version_before' => $recordVersionBefore,
            'record_version_after' => $recordVersionAfter,
            'owner_staff_id_before' => $ownerStaffBefore,
            'owner_staff_id_after' => $ownerStaffAfter,
            'owner_employee_id_before' => $ownerEmployeeBefore,
            'owner_employee_id_after' => $ownerEmployeeAfter,
            'owner_name_snapshot_before' => $operationType === self::CREATE_TASK
                ? ''
                : (string)$task['owner_name_snapshot'],
            'owner_name_snapshot_after' => $operationType === self::REASSIGN_TASK
                ? (string)($task['_owner_name_after'] ?? '')
                : (string)$task['owner_name_snapshot'],
            'is_visible_before' => $visibleBefore,
            'is_visible_after' => $visibleAfter,
            'actor_staff_id' => $actor['staffId'],
            'actor_employee_id' => $actor['employeeId'],
            'actor_name_snapshot' => $actor['staffName'],
            'reason' => $reason,
            'occurred_at' => $now,
            'recorded_at' => $now,
        ];
    }

    private function insertOperation(array $row): array
    {
        $operation = $this->repository->insertOperation($row);
        $this->assertPersistedIdentity($operation, 'operation');
        $this->repository->completeCommandReceipt($operation);
        return $operation;
    }

    private function resultFromOperation(array $operation, bool $replayed): array
    {
        return [
            'operationId' => (int)($operation['id'] ?? 0),
            'operationKey' => (string)($operation['operation_key'] ?? ''),
            'operationType' => (string)($operation['operation_type'] ?? ''),
            'replayed' => $replayed,
            'taskId' => (int)($operation['task_id'] ?? 0),
            'taskStatus' => (string)($operation['task_status_after'] ?? ''),
            'taskVersion' => (int)($operation['task_version_after'] ?? 0),
            'isVisible' => (int)($operation['is_visible_after'] ?? 0) === 1,
            'ownerStaffId' => (int)($operation['owner_staff_id_after'] ?? 0),
            'ownerEmployeeId' => (int)($operation['owner_employee_id_after'] ?? 0),
            'recordId' => (int)($operation['record_id'] ?? 0),
            'recordStatus' => (string)($operation['record_status_after'] ?? ''),
            'recordVersion' => (int)($operation['record_version_after'] ?? 0),
            'nextTaskId' => (int)($operation['next_task_id_after'] ?? 0),
            'nextTaskVersion' => (int)($operation['next_task_version_after'] ?? 0),
            'nextTaskStatus' => (int)($operation['next_task_id_after'] ?? 0) > 0
                ? CustomerCareTaskState::UNSTARTED
                : '',
            'occurredAt' => (int)($operation['occurred_at'] ?? 0),
        ];
    }

    private function nextTaskIdentity(string $tenantId, string $completionIdempotencyKey): array
    {
        $hash = hash('sha256', $tenantId . "\0" . $completionIdempotencyKey . "\0NEXT_TASK");
        return [
            'taskKey' => 'CARE-NEXT-TASK-' . substr($hash, 0, 48),
            'idempotencyKey' => 'CARE_NEXT_TASK-' . substr($hash, 0, 64),
        ];
    }

    private function nextDocumentNo(
        string $tenantId,
        int $occurredAt,
        string $businessTimezone,
        string $documentType
    ): string {
        $prefixes = ['TASK' => 'GJ', 'RECORD' => 'KQ'];
        if (!isset($prefixes[$documentType])) {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::REPOSITORY_CONFLICT,
                '客情正式单号类型无效。'
            );
        }
        $businessDate = $this->clock->businessDate($occurredAt, $businessTimezone);
        $sequence = $this->repository->reserveDocumentSequence(
            $tenantId,
            $businessDate,
            $documentType
        );
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate);
        if ($date === false || $sequence < 1 || $sequence > 9999) {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::REPOSITORY_CONFLICT,
                '客情正式单号无法生成。'
            );
        }
        return $prefixes[$documentType] . $date->format('ymd') . str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);
    }

    private function assertPersistedIdentity(array $row, string $resource): void
    {
        if ((int)($row['id'] ?? 0) <= 0) {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::REPOSITORY_CONFLICT,
                '客情数据写入结果不完整。',
                ['resource' => $resource]
            );
        }
    }

    private function assertCas(bool $updated, array $task): void
    {
        if (!$updated) {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::VERSION_CONFLICT,
                '客情任务已被其他操作更新，请刷新后重试。',
                ['resource' => 'task', 'actualVersion' => (int)($task['version'] ?? 0)]
            );
        }
    }

    private function assertRecordCas(bool $updated, array $record): void
    {
        if (!$updated) {
            throw new CustomerCareDomainException(
                CustomerCareErrorCode::VERSION_CONFLICT,
                '客情记录已被其他操作更新，请刷新后重试。',
                ['resource' => 'record', 'actualVersion' => (int)($record['version'] ?? 0)]
            );
        }
    }

    private function naturalKeyConflict(string $field, string $value): CustomerCareDomainException
    {
        return new CustomerCareDomainException(
            CustomerCareErrorCode::NATURAL_KEY_CONFLICT,
            '客情业务自然键已经存在。',
            ['field' => $field, 'value' => $value]
        );
    }
}
