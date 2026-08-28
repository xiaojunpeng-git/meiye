<?php

namespace app\services\customer\care\query;

use app\services\customer\care\CustomerCareClock;
use app\services\customer\care\CustomerCareRecordState;
use app\services\customer\care\CustomerCareTaskState;

/** Assembles the single authoritative PC/mobile customer-care.v1 projection. */
final class CustomerCareWorkbenchQueryService
{
    /** @var CustomerCareQueryRepository */
    private $repository;

    /** @var CustomerCareClock */
    private $clock;

    /** @var CustomerCareCursorCodec */
    private $cursorCodec;

    public function __construct(
        CustomerCareQueryRepository $repository,
        CustomerCareClock $clock,
        CustomerCareCursorCodec $cursorCodec
    ) {
        $this->repository = $repository;
        $this->clock = $clock;
        $this->cursorCodec = $cursorCodec;
    }

    public function query(array $trustedContext, array $request): array
    {
        $scope = CustomerCareQueryScope::fromTrustedContext($trustedContext);
        return $this->queryForScope($scope, $request);
    }

    public function queryForScope(CustomerCareQueryScope $scope, array $request): array
    {
        $now = $this->clock->now();
        $normalized = CustomerCareProjectionContract::normalizeWorkbenchRequest($request, $scope);
        if (($normalized['taskQuery']['statusGroup'] ?? '') !== 'open') {
            $normalized['taskQuery'] = $this->withCurrentMonthRange($normalized['taskQuery'], 'plannedFrom', 'plannedTo', $now, $scope->businessTimezone());
        }
        $normalized['recordQuery'] = $this->withCurrentMonthRange($normalized['recordQuery'], 'followedFrom', 'followedTo', $now, $scope->businessTimezone());
        $dayEnd = CustomerCareProjectionContract::dayEnd($now, $scope->businessTimezone());

        $taskView = $this->taskView($scope, $normalized['taskQuery'], $now, $dayEnd);
        $customerView = $this->customerView($scope, $normalized['customerQuery'], $now);
        $recordView = $this->recordView($scope, $normalized['recordQuery']);
        $team = $this->repository->teamSummary($scope, $now, $dayEnd);
        $dataAsOf = CustomerCareProjectionContract::dateTime(
            $now,
            $scope->businessTimezone(),
            true
        );
        $statisticsDetail = $this->statisticsDetail(
            $scope,
            $normalized['statisticsQuery'],
            $now,
            $dayEnd,
            $dataAsOf
        );

        return [
            'contractVersion' => CustomerCareProjectionContract::CONTRACT_VERSION,
            'dataAsOf' => $dataAsOf,
            'businessTimezone' => $scope->businessTimezone(),
            'currentStore' => [
                'storeId' => (string)$scope->operationStoreId(),
                'name' => $scope->operationStoreName(),
            ],
            'currentEmployee' => [
                'employeeId' => (string)$scope->employeeId(),
                'staffId' => (string)$scope->staffId(),
                'name' => $scope->staffName(),
            ],
            'permissions' => $scope->permissionsDto(),
            'taskView' => $taskView,
            'customerView' => $customerView,
            'recordView' => $recordView,
            'statistics' => $this->statisticsDto($scope, $team, $dataAsOf, $statisticsDetail),
            'settings' => [
                'rules' => [],
                'exceptions' => [],
                'ruleCapability' => [
                    'enabled' => false,
                    'disabledReason' => '回访规则写入不属于当前客情查询与命令适配闭环。',
                ],
                'exceptionCapability' => [
                    'enabled' => false,
                    'disabledReason' => '自动任务异常处理不属于当前客情查询与命令适配闭环。',
                ],
            ],
            'preparations' => $this->preparations($scope),
            'streamCapabilities' => [
                'human' => ['enabled' => true, 'disabledReason' => ''],
                'appointment' => [
                    'enabled' => false,
                    'disabledReason' => '预约领域 C3 尚未接入，预约动态保持独立空流。',
                ],
            ],
        ];
    }

    public function taskDetail(array $trustedContext, int $taskId): ?array
    {
        $scope = CustomerCareQueryScope::fromTrustedContext($trustedContext);
        $task = $this->repository->authorizedTask($scope, $taskId);
        if ($task === null) {
            return null;
        }
        $memberId = (int)($task['member_id'] ?? 0);
        $now = $this->clock->now();
        $members = $this->repository->memberProfiles($scope, [$memberId]);
        $summaries = $this->repository->memberCareSummaries($scope, [$memberId], $now);
        $latest = $this->repository->latestRecordsByMember($scope, [$memberId]);
        $taskRecords = $this->repository->recordsByTask($scope, [$taskId]);
        $dayEnd = CustomerCareProjectionContract::dayEnd($now, $scope->businessTimezone());
        return $this->taskDto(
            $task,
            $scope,
            $members[$memberId] ?? [],
            $summaries[$memberId] ?? [],
            $taskRecords[$taskId] ?? ($latest[$memberId] ?? null),
            $now,
            $dayEnd
        );
    }

    private function taskView(
        CustomerCareQueryScope $scope,
        array $query,
        int $now,
        int $dayEnd
    ): array {
        $queryHash = CustomerCareProjectionContract::queryHash('tasks', $scope, $query);
        $after = $query['cursor'] === ''
            ? []
            : $this->cursorCodec->decode($query['cursor'], 'tasks', $queryHash);
        $page = $this->repository->taskPage($scope, $query, $after, $now, $dayEnd);
        $memberIds = array_map('intval', array_column($page['rows'], 'member_id'));
        $taskIds = array_map('intval', array_column($page['rows'], 'id'));
        $members = $this->repository->memberProfiles($scope, $memberIds);
        $summaries = $this->repository->memberCareSummaries($scope, $memberIds, $now);
        $latest = $this->repository->latestRecordsByMember($scope, $memberIds);
        $taskRecords = $this->repository->recordsByTask($scope, $taskIds);
        $records = [];
        foreach ($page['rows'] as $row) {
            $memberId = (int)$row['member_id'];
            $taskId = (int)$row['id'];
            $records[] = $this->taskDto(
                $row,
                $scope,
                $members[$memberId] ?? [],
                $summaries[$memberId] ?? [],
                $taskRecords[$taskId] ?? ($latest[$memberId] ?? null),
                $now,
                $dayEnd
            );
        }
        $nextCursor = '';
        if ($page['hasMore'] && $page['rows'] !== []) {
            $last = $page['rows'][count($page['rows']) - 1];
            $nextCursor = $this->cursorCodec->encode('tasks', $queryHash, [
                'plannedAt' => (int)$last['planned_at'],
                'id' => (int)$last['id'],
            ]);
        }
        return [
            'records' => $records,
            'total' => (int)$page['total'],
            'pageSize' => (int)$query['pageSize'],
            'paginationMode' => 'keyset',
            'paginationCursor' => $nextCursor,
            'hasMore' => (bool)$page['hasMore'],
            'bucketCounts' => $this->repository->taskBucketCounts($scope, $query, $now, $dayEnd),
            'statusOptions' => CustomerCareProjectionContract::statusOptions(),
            'appliedQuery' => [
                'scope' => $query['scope'],
                'bucket' => $query['bucket'],
                'keyword' => $query['keyword'],
                'status' => $query['status'],
				'statusGroup' => $query['statusGroup'],
				'memberId' => (int)($query['memberId'] ?? 0) > 0 ? (string)(int)$query['memberId'] : '',
                'plannedFrom' => $this->dateOnly(
                    (int)($query['plannedFrom'] ?? 0),
                    $scope->businessTimezone()
                ),
                'plannedTo' => $this->dateOnly(
                    (int)($query['plannedTo'] ?? 0),
                    $scope->businessTimezone()
                ),
            ],
        ];
    }

    private function customerView(
        CustomerCareQueryScope $scope,
        array $query,
        int $now
    ): array {
        $queryHash = CustomerCareProjectionContract::queryHash('customers', $scope, $query);
        $after = $query['cursor'] === ''
            ? []
            : $this->cursorCodec->decode($query['cursor'], 'customers', $queryHash);
        $page = $this->repository->customerMemberPage($scope, $query, $after);
        $profiles = $this->repository->memberProfiles($scope, $page['memberIds']);
        $summaries = $this->repository->memberCareSummaries($scope, $page['memberIds'], $now);
        $records = [];
        foreach ($page['memberIds'] as $memberId) {
            $profile = $profiles[$memberId] ?? [
                'memberId' => $memberId,
                'name' => '会员#' . $memberId,
                'phone' => '',
                'exclusiveServiceName' => '待分配',
            ];
            $summary = $summaries[$memberId] ?? [];
            $records[] = [
                'memberId' => (string)$memberId,
                'name' => (string)($profile['name'] ?? '未命名会员'),
                'phone' => (string)($profile['phone'] ?? ''),
                'storeName' => (string)($summary['storeName'] ?? ''),
                'exclusiveServiceName' => (string)($profile['exclusiveServiceName'] ?? '待分配'),
                'latestService' => (string)($summary['latestService'] ?? '暂无服务记录'),
                'latestServiceSource' => (string)($summary['latestServiceSource'] ?? 'unavailable'),
                'latestCare' => (string)($summary['latestCare'] ?? '暂无跟进记录'),
                'nextTask' => is_array($summary['nextTask'] ?? null)
                    ? $summary['nextTask']
                    : [
                        'taskId' => '',
                        'taskNo' => '',
                        'label' => '暂无待跟进任务',
                        'status' => '',
                        'statusLabel' => '',
                        'canOpen' => false,
                    ],
                'overdueCount' => (int)($summary['overdueCount'] ?? 0),
                'navigationTarget' => [
                    'targetCode' => 'member-detail',
                    'businessId' => (string)$memberId,
                ],
            ];
        }
        $nextCursor = '';
        if ($page['hasMore'] && $page['memberIds'] !== []) {
            $nextCursor = $this->cursorCodec->encode('customers', $queryHash, [
                'memberId' => (int)$page['memberIds'][count($page['memberIds']) - 1],
            ]);
        }
        return [
            'records' => $records,
            'total' => (int)$page['total'],
            'appliedQuery' => [
                'keyword' => (string)$query['keyword'],
                'memberId' => (int)($query['memberId'] ?? 0) > 0
                    ? (string)(int)$query['memberId']
                    : '',
            ],
            'pageSize' => (int)$query['pageSize'],
            'paginationMode' => 'keyset',
            'paginationCursor' => $nextCursor,
            'hasMore' => (bool)$page['hasMore'],
        ];
    }

    private function withCurrentMonthRange(
        array $query,
        string $fromKey,
        string $toKey,
        int $now,
        string $timezone
    ): array {
        $current = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone($timezone));
        if ((int)($query[$fromKey] ?? 0) <= 0) {
            $query[$fromKey] = $current->modify('first day of this month')->setTime(0, 0, 0)->getTimestamp();
        }
        if ((int)($query[$toKey] ?? 0) <= 0) {
            $query[$toKey] = $current->modify('last day of this month')->setTime(23, 59, 59)->getTimestamp();
        }
        if ((int)$query[$fromKey] > (int)$query[$toKey]) {
            throw new CustomerCareProjectionException(
                CustomerCareProjectionErrorCode::INVALID_QUERY,
                '客情查询参数无效。',
                ['field' => $fromKey]
            );
        }
        return $query;
    }

    private function dateOnly(int $timestamp, string $timezone): string
    {
        return $timestamp > 0
            ? (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d')
            : '';
    }

    private function recordView(CustomerCareQueryScope $scope, array $query): array
    {
        $queryHash = CustomerCareProjectionContract::queryHash('records', $scope, $query);
        $after = $query['cursor'] === ''
            ? []
            : $this->cursorCodec->decode($query['cursor'], 'records', $queryHash);
        $page = $this->repository->recordPage($scope, $query, $after);
        $records = [];
        foreach ($page['rows'] as $row) {
            $records[] = $this->recordDto($row, $scope);
        }
        $nextCursor = '';
        if ($page['hasMore'] && $page['rows'] !== []) {
            $last = $page['rows'][count($page['rows']) - 1];
            $nextCursor = $this->cursorCodec->encode('records', $queryHash, [
                'followedAt' => (int)$last['followed_at'],
                'id' => (int)$last['id'],
            ]);
        }
        return [
            'records' => $records,
            'total' => (int)$page['total'],
            'pageSize' => (int)$query['pageSize'],
            'paginationMode' => 'keyset',
            'paginationCursor' => $nextCursor,
            'hasMore' => (bool)$page['hasMore'],
            'stream' => $query['stream'],
            'appliedQuery' => [
                'keyword' => $query['keyword'],
                'dataScope' => $query['dataScope'],
				'memberId' => (int)($query['memberId'] ?? 0) > 0 ? (string)(int)$query['memberId'] : '',
                'followedFrom' => $this->dateOnly(
                    (int)($query['followedFrom'] ?? 0),
                    $scope->businessTimezone()
                ),
                'followedTo' => $this->dateOnly(
                    (int)($query['followedTo'] ?? 0),
                    $scope->businessTimezone()
                ),
            ],
            'streamCapability' => $query['stream'] === 'appointment'
                ? ['enabled' => false, 'disabledReason' => '预约领域 C3 尚未接入。']
                : ['enabled' => true, 'disabledReason' => ''],
        ];
    }

    private function taskDto(
        array $row,
        CustomerCareQueryScope $scope,
        array $profile,
        array $memberSummary,
        ?array $latestRecord,
        int $now,
        int $dayEnd
    ): array {
        $taskId = (int)$row['id'];
        $memberId = (int)$row['member_id'];
        $status = (string)$row['status'];
        $plannedAt = (int)$row['planned_at'];
        $ownerStaffId = (int)$row['owner_staff_id'];
        $memberName = (string)($profile['name'] ?? $row['member_name_snapshot'] ?? '未命名会员');
        $phone = (string)($profile['phone'] ?? '');
        $bucket = CustomerCareProjectionContract::bucket($status, $plannedAt, $now, $dayEnd);
        $related = $this->relatedBusinessDto($row);
        $dto = [
            'taskId' => (string)$taskId,
            'taskNo' => (string)($row['task_no'] ?? ''),
            'taskVersion' => (int)$row['version'],
            'member' => [
                'memberId' => (string)$memberId,
                'name' => $memberName,
                'phone' => $phone,
                'level' => (string)($profile['level'] ?? ''),
                'tags' => array_values($profile['tags'] ?? []),
            ],
            'plannedAt' => CustomerCareProjectionContract::dateTime(
                $plannedAt,
                $scope->businessTimezone()
            ),
            'plannedAtEpoch' => $plannedAt,
            'completedAt' => CustomerCareProjectionContract::dateTime(
                (int)($row['completed_at'] ?? 0),
                $scope->businessTimezone()
            ),
            'typeCode' => (string)$row['task_type'],
            'typeLabel' => CustomerCareProjectionContract::taskLabel((string)$row['task_type']),
            'sourceCode' => (string)$row['source_type'],
            'sourceLabel' => CustomerCareProjectionContract::sourceLabel((string)$row['source_type']),
            'status' => $status,
            'statusLabel' => CustomerCareProjectionContract::taskStatusLabel($status),
            'isOverdue' => $bucket === CustomerCareProjectionContract::BUCKET_OVERDUE,
            'bucket' => $bucket,
            'isDeleted' => (int)($row['is_visible'] ?? 0) !== 1,
            'listScopes' => $ownerStaffId === $scope->staffId()
                ? ($scope->canViewAllTasks() ? ['my', 'all'] : ['my'])
                : ($scope->canViewAllTasks() ? ['all'] : []),
            'owner' => [
                'staffId' => (string)$ownerStaffId,
                'employeeId' => (string)(int)$row['owner_employee_id'],
                'name' => (string)$row['owner_name_snapshot'],
            ],
            'store' => [
                'storeId' => (string)(int)$row['business_store_id'],
                'name' => (string)$row['business_store_name_snapshot'],
            ],
            'relatedBusiness' => $related,
            'recentServiceSummary' => (string)($memberSummary['latestService'] ?? '暂无服务记录'),
            'recentServiceSource' => (string)($memberSummary['latestServiceSource'] ?? 'unavailable'),
            'latestCare' => $latestRecord === null ? null : $this->latestCareDto($latestRecord, $scope),
            'availableActions' => $this->taskActions($row, $scope),
            'navigationActions' => [
                [
                    'code' => 'open-member-detail',
                    'label' => '会员详情',
                    'enabled' => true,
                    'targetCode' => 'member-detail',
                    'businessId' => (string)$memberId,
                ],
                [
                    'code' => 'call-member',
                    'label' => '拨号',
                    'enabled' => $phone !== '',
                    'disabledReason' => $phone === '' ? '会员没有可用手机号。' : '',
                    'targetCode' => 'phone-dial',
                    'businessId' => (string)$memberId,
                ],
                [
                    'code' => 'open-wechat',
                    'label' => '打开微信',
                    'enabled' => false,
                    'disabledReason' => '尚未冻结可追溯的微信客户联系能力。',
                    'targetCode' => 'wechat-contact',
                    'businessId' => (string)$memberId,
                ],
            ],
            'appointmentCapability' => [
                'enabled' => false,
                'disabledReason' => '预约领域 C3 尚未接入，不能创建、关联或打开预约。',
            ],
        ];
        if ($latestRecord !== null && (int)($latestRecord['task_id'] ?? 0) === $taskId) {
            $dto['actualFollower'] = [
                'staffId' => (string)(int)$latestRecord['follower_staff_id'],
                'employeeId' => (string)(int)$latestRecord['follower_employee_id'],
                'name' => (string)$latestRecord['follower_name_snapshot'],
            ];
        }
        return $dto;
    }

    private function recordDto(array $row, CustomerCareQueryScope $scope): array
    {
        $recordId = (int)$row['id'];
        $taskId = (int)($row['task_id'] ?? 0);
        $status = (string)$row['status'];
        $isCreator = (int)$row['created_by_staff_id'] === $scope->staffId();
        $actions = [];
        if ($status === CustomerCareRecordState::NORMAL && $isCreator) {
            $taskLinked = $taskId > 0;
            $actions[] = [
                'code' => 'void-care-record',
                'label' => '作废',
                'tone' => 'danger',
                'enabled' => !$taskLinked,
                'disabledReason' => $taskLinked
                    ? '任务关联记录作废需同时提交任务版本和记录版本，当前电脑端表单尚未携带双版本。'
                    : '',
            ];
        }
        return [
            'recordId' => (string)$recordId,
            'recordNo' => (string)($row['record_no'] ?? ''),
            'recordVersion' => (int)$row['version'],
            'taskId' => $taskId > 0 ? (string)$taskId : '',
            'taskVersion' => (int)($row['task_version'] ?? 0),
            'stream' => 'human',
            'typeCode' => (string)$row['record_type'],
            'typeLabel' => CustomerCareProjectionContract::taskLabel((string)$row['record_type']),
            'followedAt' => CustomerCareProjectionContract::dateTime(
                (int)$row['followed_at'],
                $scope->businessTimezone()
            ),
            'followedAtEpoch' => (int)$row['followed_at'],
            'methodCode' => (string)$row['followup_method'],
            'methodLabel' => CustomerCareProjectionContract::methodLabel((string)$row['followup_method']),
            'memberId' => (string)(int)$row['member_id'],
            'memberName' => (string)$row['member_name_snapshot'],
            'content' => (string)($row['detail'] !== '' ? $row['detail'] : $row['summary']),
            'summary' => (string)$row['summary'],
            'resultCode' => (string)$row['result_code'],
            'resultLabel' => CustomerCareProjectionContract::resultLabel((string)$row['result_code']),
            'actualFollowerName' => (string)$row['follower_name_snapshot'],
            'actualFollower' => [
                'staffId' => (string)(int)$row['follower_staff_id'],
                'employeeId' => (string)(int)$row['follower_employee_id'],
                'name' => (string)$row['follower_name_snapshot'],
            ],
            'creatorName' => (string)$row['follower_name_snapshot'],
            'creatorStaffId' => (string)(int)$row['created_by_staff_id'],
            'storeName' => (string)$row['business_store_name_snapshot'],
            'relatedBusiness' => [
                'type' => (string)($row['related_business_type'] ?? ''),
                'id' => (string)($row['related_business_id'] ?? ''),
                'label' => (string)($row['related_business_label_snapshot'] ?? ''),
            ],
            'status' => $status,
            'statusLabel' => CustomerCareProjectionContract::recordStatusLabel($status),
            'voidReason' => (string)($row['void_reason'] ?? ''),
            'createdAt' => CustomerCareProjectionContract::dateTime(
                (int)($row['created_at'] ?? 0),
                $scope->businessTimezone(),
                true
            ),
            'recordedAt' => CustomerCareProjectionContract::dateTime(
                (int)($row['recorded_at'] ?? 0),
                $scope->businessTimezone(),
                true
            ),
            'voidedAt' => CustomerCareProjectionContract::dateTime(
                (int)($row['voided_at'] ?? 0),
                $scope->businessTimezone(),
                true
            ),
            'availableActions' => $actions,
            'commandRequirements' => [
                'expectedRecordVersion' => (int)$row['version'],
                'expectedTaskVersion' => $taskId > 0 ? (int)($row['task_version'] ?? 0) : null,
            ],
        ];
    }

    private function latestCareDto(array $row, CustomerCareQueryScope $scope): array
    {
        return [
            'recordId' => (string)(int)$row['id'],
            'recordVersion' => (int)$row['version'],
            'followedAt' => CustomerCareProjectionContract::dateTime(
                (int)$row['followed_at'],
                $scope->businessTimezone()
            ),
            'methodCode' => (string)$row['followup_method'],
            'methodLabel' => CustomerCareProjectionContract::methodLabel((string)$row['followup_method']),
            'resultCode' => (string)$row['result_code'],
            'resultLabel' => CustomerCareProjectionContract::resultLabel((string)$row['result_code']),
            'content' => (string)($row['detail'] !== '' ? $row['detail'] : $row['summary']),
            'status' => (string)$row['status'],
        ];
    }

    private function taskActions(array $row, CustomerCareQueryScope $scope): array
    {
        if ((int)($row['is_visible'] ?? 0) !== 1) {
            return [];
        }
        $status = (string)$row['status'];
        $isOwner = (int)$row['owner_staff_id'] === $scope->staffId();
        $canExecute = $isOwner || $scope->canReassign();
        $actions = [];
        if ($canExecute && $status === CustomerCareTaskState::UNSTARTED) {
            $actions = [
                ['code' => 'start-care-task', 'label' => '开始跟进', 'tone' => 'primary', 'enabled' => true],
                ['code' => 'delete-care-task', 'label' => '删除任务', 'tone' => 'danger', 'enabled' => true],
            ];
        } elseif ($canExecute && $status === CustomerCareTaskState::IN_PROGRESS) {
            $actions = [
                ['code' => 'complete-care-task', 'label' => '完成跟进', 'tone' => 'primary', 'enabled' => true],
                ['code' => 'void-care-task', 'label' => '作废任务', 'tone' => 'danger', 'enabled' => true],
            ];
        }
        if ($scope->canReassign()
            && in_array($status, [CustomerCareTaskState::UNSTARTED, CustomerCareTaskState::IN_PROGRESS], true)) {
            $actions[] = [
                'code' => 'reassign-care-task',
                'label' => '转派',
                'tone' => 'secondary',
                'enabled' => true,
            ];
        }
        return $actions;
    }

    private function relatedBusinessDto(array $row): array
    {
        $typeMap = [
            'MANUAL' => ['type' => 'member', 'targetCode' => 'member-detail'],
            'SERVICE_COMPLETED' => ['type' => 'service', 'targetCode' => 'service-detail'],
            'PREVIOUS_FOLLOWUP' => ['type' => 'record', 'targetCode' => 'care-record-detail'],
        ];
        $source = (string)$row['source_type'];
        $mapped = $typeMap[$source] ?? ['type' => 'member', 'targetCode' => 'member-detail'];
        $businessId = $source === 'MANUAL'
            ? (string)(int)$row['member_id']
            : (string)$row['source_id'];
        return [
            'type' => $mapped['type'],
            'businessId' => $businessId,
            'label' => (string)$row['title'],
            'summary' => (string)$row['note'],
            'navigationTarget' => [
                'targetCode' => $mapped['targetCode'],
                'businessId' => $businessId,
            ],
        ];
    }

    private function preparations(CustomerCareQueryScope $scope): array
    {
        $assignees = [];
        if (!$scope->canReassign()) {
            // The request context has already verified this exact active store-staff
            // assignment. Do not make a self-assignment disappear because a separate
            // cashier role switch is disabled on an otherwise authorized employee.
            $assignees[] = [
                'value' => (string)$scope->staffId(),
                'label' => $scope->staffName(),
                'staffId' => (string)$scope->staffId(),
                'employeeId' => (string)$scope->employeeId(),
                'storeId' => (string)$scope->operationStoreId(),
                'enabled' => true,
            ];
        } else {
            foreach ($this->repository->activeAssignees($scope) as $row) {
                $assignees[] = [
                    'value' => (string)(int)$row['staff_id'],
                    'label' => (string)$row['staff_name'],
                    'staffId' => (string)(int)$row['staff_id'],
                    'employeeId' => (string)(int)$row['employee_id'],
                    'storeId' => (string)(int)$row['store_id'],
                    'enabled' => true,
                ];
            }
        }
        return [
            'completion' => [
                'methodOptions' => CustomerCareProjectionContract::methodOptions(),
                'resultOptions' => CustomerCareProjectionContract::resultOptions(),
                'assigneeOptions' => $assignees,
                'appointmentCapability' => [
                    'enabled' => false,
                    'disabledReason' => '预约领域 C3 尚未接入。',
                ],
            ],
            'reassignment' => ['assigneeOptions' => $scope->canReassign() ? $assignees : []],
        ];
    }

    private function statisticsDto(
        CustomerCareQueryScope $scope,
        array $team,
        string $dataAsOf,
        ?array $detail
    ): array {
        if (!$scope->canViewStatistics()) {
            return [
                'metricVersion' => 'care-metrics.v1',
                'aggregationCaughtUp' => true,
                'metrics' => [],
                'employeeRows' => [],
                'teamSummary' => null,
                'detail' => null,
            ];
        }
        $counts = $team['counts'];
        return [
            'metricVersion' => 'care-metrics.v1',
            'aggregationCaughtUp' => true,
            'metrics' => [
                [
                    'code' => 'care_open_workload', 'label' => '当前待跟进',
                    'value' => $counts['today'] + $counts['overdue'] + $counts['future'],
                    'unit' => '项', 'userReady' => true,
                    'description' => '未开始或进行中的任务总数。',
                ],
                [
                    'code' => 'care_completed', 'label' => '已完成',
                    'value' => $counts['completed'], 'unit' => '项',
                    'userReady' => true,
                    'description' => '状态为已完成的任务数。',
                ],
                [
                    'code' => 'care_overdue_current', 'label' => '当前逾期',
                    'value' => $counts['overdue'], 'unit' => '项',
                    'userReady' => true,
                    'description' => '计划时间早于当前时间、仍未完成的任务数。',
                ],
                [
                    'code' => 'care_activity_records', 'label' => '实际跟进记录',
                    'value' => (int)$team['validRecordCount'], 'unit' => '条',
                    'userReady' => true,
                    'description' => '正常状态且已关联任务的客情记录数。',
                ],
            ],
            'employeeRows' => array_values($team['employeeRows']),
            'detail' => $detail,
            'teamSummary' => [
                'todayCount' => $counts['today'],
                'overdueCount' => $counts['overdue'],
                'futureCount' => $counts['future'],
                'completedCount' => $counts['completed'],
                'dataAsOf' => $dataAsOf,
                'scope' => $scope->canViewAllTasks() ? 'authorized_team' : 'self',
            ],
        ];
    }

    /** Builds a paged, server-authoritative read-only drill-down for one displayed metric. */
    private function statisticsDetail(
        CustomerCareQueryScope $scope,
        array $query,
        int $now,
        int $dayEnd,
        string $dataAsOf
    ): ?array {
        $metric = (string)($query['metric'] ?? '');
        if ($metric === '') {
            return null;
        }

        $taskMetrics = [
            CustomerCareProjectionContract::STATISTICS_METRIC_OPEN_WORKLOAD => '当前待跟进',
            CustomerCareProjectionContract::STATISTICS_METRIC_COMPLETED => '已完成',
            CustomerCareProjectionContract::STATISTICS_METRIC_OVERDUE => '当前逾期',
            CustomerCareProjectionContract::STATISTICS_METRIC_EMPLOYEE_OPEN_WORKLOAD => '当前开放工作量',
            CustomerCareProjectionContract::STATISTICS_METRIC_EMPLOYEE_OVERDUE => '当前逾期',
        ];
        $recordMetrics = [
            CustomerCareProjectionContract::STATISTICS_METRIC_ACTIVITY_RECORDS => '实际跟进记录',
            CustomerCareProjectionContract::STATISTICS_METRIC_EMPLOYEE_COMPLETED => '按实际跟进人完成',
        ];
        $staffId = (int)($query['staffId'] ?? 0);
        $staffName = '';
        if ($staffId > 0) {
            foreach ($this->repository->activeAssignees($scope) as $assignee) {
                if ((int)($assignee['staff_id'] ?? 0) === $staffId) {
                    $staffName = (string)($assignee['staff_name'] ?? '');
                    break;
                }
            }
        }

        if (isset($taskMetrics[$metric])) {
            $taskQuery = [
                'scope' => $scope->canViewAllTasks() ? 'all' : 'my',
                'bucket' => CustomerCareProjectionContract::BUCKET_ALL,
                'keyword' => '',
                'status' => '',
                'statusGroup' => '',
                'ownerStaffId' => $staffId,
                'pageSize' => (int)$query['pageSize'],
                'cursor' => (string)$query['cursor'],
            ];
            if ($metric === CustomerCareProjectionContract::STATISTICS_METRIC_OPEN_WORKLOAD
                || $metric === CustomerCareProjectionContract::STATISTICS_METRIC_EMPLOYEE_OPEN_WORKLOAD) {
                $taskQuery['statusGroup'] = 'open';
            } elseif ($metric === CustomerCareProjectionContract::STATISTICS_METRIC_COMPLETED) {
                $taskQuery['status'] = CustomerCareTaskState::COMPLETED;
            } elseif ($metric === CustomerCareProjectionContract::STATISTICS_METRIC_OVERDUE
                || $metric === CustomerCareProjectionContract::STATISTICS_METRIC_EMPLOYEE_OVERDUE) {
                $taskQuery['bucket'] = CustomerCareProjectionContract::BUCKET_OVERDUE;
            }
            $view = $this->taskView($scope, $taskQuery, $now, $dayEnd);
            return [
                'metric' => $metric,
                'title' => $taskMetrics[$metric],
                'entityType' => 'task',
                'staffId' => $staffId > 0 ? (string)$staffId : '',
                'staffName' => $staffName,
                'records' => $view['records'],
                'total' => (int)$view['total'],
                'hasMore' => (bool)$view['hasMore'],
                'paginationCursor' => (string)$view['paginationCursor'],
                'dataAsOf' => $dataAsOf,
            ];
        }

        if (isset($recordMetrics[$metric])) {
            $recordQuery = [
                'keyword' => '',
                'stream' => 'human',
                'dataScope' => 'normal',
                'taskLinkedOnly' => true,
                'followerStaffId' => $staffId,
                'pageSize' => (int)$query['pageSize'],
                'cursor' => (string)$query['cursor'],
            ];
            $view = $this->recordView($scope, $recordQuery);
            return [
                'metric' => $metric,
                'title' => $recordMetrics[$metric],
                'entityType' => 'record',
                'staffId' => $staffId > 0 ? (string)$staffId : '',
                'staffName' => $staffName,
                'records' => $view['records'],
                'total' => (int)$view['total'],
                'hasMore' => (bool)$view['hasMore'],
                'paginationCursor' => (string)$view['paginationCursor'],
                'dataAsOf' => $dataAsOf,
            ];
        }

        throw new \LogicException('unsupported customer-care statistics metric');
    }
}
