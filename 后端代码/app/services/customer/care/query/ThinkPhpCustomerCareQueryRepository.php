<?php

namespace app\services\customer\care\query;

use app\services\customer\care\CustomerCareRecordState;
use app\services\customer\care\CustomerCareTaskState;
use think\facade\Db;

/** ThinkPHP/MySQL 5.6 read model for customer-care.v1. */
final class ThinkPhpCustomerCareQueryRepository implements CustomerCareQueryRepository
{
    /** @var array<string,array<string,bool>> */
    private $columnCache = [];

    public function taskPage(
        CustomerCareQueryScope $scope,
        array $query,
        array $after,
        int $now,
        int $dayEnd
    ): array {
        $base = $this->taskQuery($scope, $query, $now, $dayEnd, true);
        $total = (int)(clone $base)->count();
        if ($after !== []) {
            $plannedAt = $this->positiveCursorInt($after, 'plannedAt');
            $id = $this->positiveCursorInt($after, 'id');
            $base->where(function ($where) use ($plannedAt, $id) {
                $where->where('planned_at', '>', $plannedAt)
                    ->whereOr(function ($same) use ($plannedAt, $id) {
                        $same->where('planned_at', $plannedAt)->where('id', '>', $id);
                    });
            });
        }
        $rows = $this->rows($base
            ->order('planned_at', 'asc')
            ->order('id', 'asc')
            ->limit((int)$query['pageSize'] + 1)
            ->select());
        $hasMore = count($rows) > (int)$query['pageSize'];
        if ($hasMore) {
            array_pop($rows);
        }
        return ['rows' => $rows, 'total' => $total, 'hasMore' => $hasMore];
    }

    public function taskBucketCounts(
        CustomerCareQueryScope $scope,
        array $query,
        int $now,
        int $dayEnd
    ): array {
        $base = $this->taskQuery($scope, $query, $now, $dayEnd, false);
        $row = $this->row($base->field(sprintf(
            'SUM(CASE WHEN status IN (\'%s\',\'%s\') AND planned_at >= %d AND planned_at <= %d THEN 1 ELSE 0 END) AS today_count,' .
            'SUM(CASE WHEN status IN (\'%s\',\'%s\') AND planned_at < %d THEN 1 ELSE 0 END) AS overdue_count,' .
            'SUM(CASE WHEN status IN (\'%s\',\'%s\') AND planned_at > %d THEN 1 ELSE 0 END) AS future_count,' .
            'SUM(CASE WHEN status = \'%s\' THEN 1 ELSE 0 END) AS completed_count,' .
            'COUNT(*) AS all_count',
            CustomerCareTaskState::UNSTARTED,
            CustomerCareTaskState::IN_PROGRESS,
            $now,
            $dayEnd,
            CustomerCareTaskState::UNSTARTED,
            CustomerCareTaskState::IN_PROGRESS,
            $now,
            CustomerCareTaskState::UNSTARTED,
            CustomerCareTaskState::IN_PROGRESS,
            $dayEnd,
            CustomerCareTaskState::COMPLETED
        ))->find()) ?: [];
        return [
            'today' => (int)($row['today_count'] ?? 0),
            'overdue' => (int)($row['overdue_count'] ?? 0),
            'future' => (int)($row['future_count'] ?? 0),
            'completed' => (int)($row['completed_count'] ?? 0),
            'all' => (int)($row['all_count'] ?? 0),
        ];
    }

    public function recordPage(CustomerCareQueryScope $scope, array $query, array $after): array
    {
        if ($query['stream'] === 'appointment') {
            return ['rows' => [], 'total' => 0, 'hasMore' => false];
        }
        $base = Db::name('customer_care_record')->alias('r')
            ->leftJoin(
                'customer_care_task t',
                't.id = r.task_id AND t.tenant_id = r.tenant_id'
            )
            ->where('r.tenant_id', $scope->tenantId());
        $this->applyAuthorizedMemberScope($base, $scope, 'r.member_id');
        if ($query['dataScope'] === 'normal') {
            $base->where('r.status', CustomerCareRecordState::NORMAL);
        }
        if ((int)($query['followedFrom'] ?? 0) > 0) {
            $base->where('r.followed_at', '>=', (int)$query['followedFrom']);
        }
        if ((int)($query['followedTo'] ?? 0) > 0) {
            $base->where('r.followed_at', '<=', (int)$query['followedTo']);
        }
        if (!empty($query['taskLinkedOnly'])) {
            $base->whereNotNull('r.task_id');
        }
        $followerStaffId = (int)($query['followerStaffId'] ?? 0);
        if ($followerStaffId > 0) {
            $base->where('r.follower_staff_id', $followerStaffId);
        }
        $this->applyRecordKeyword($base, (string)$query['keyword']);
        $total = (int)(clone $base)->count();
        if ($after !== []) {
            $followedAt = $this->positiveCursorInt($after, 'followedAt');
            $id = $this->positiveCursorInt($after, 'id');
            $base->where(function ($where) use ($followedAt, $id) {
                $where->where('r.followed_at', '<', $followedAt)
                    ->whereOr(function ($same) use ($followedAt, $id) {
                        $same->where('r.followed_at', $followedAt)->where('r.id', '<', $id);
                    });
            });
        }
        $rows = $this->rows($base
            ->field('r.*,t.version AS task_version,t.status AS task_status,t.is_visible AS task_is_visible')
            ->order('r.followed_at', 'desc')
            ->order('r.id', 'desc')
            ->limit((int)$query['pageSize'] + 1)
            ->select());
        $hasMore = count($rows) > (int)$query['pageSize'];
        if ($hasMore) {
            array_pop($rows);
        }
        return ['rows' => $rows, 'total' => $total, 'hasMore' => $hasMore];
    }

    public function customerMemberPage(
        CustomerCareQueryScope $scope,
        array $query,
        array $after
    ): array {
        $exclusiveColumns = $this->tableColumns('member_exclusive_service');
        $hasExclusive = isset(
            $exclusiveColumns['member_id'],
            $exclusiveColumns['store_id'],
            $exclusiveColumns['staff_name'],
            $exclusiveColumns['status']
        );
        $stores = implode(',', array_map('intval', $scope->allowedBusinessStoreIds()));
        $base = Db::name('store_user')->alias('su')
            ->join('user u', 'u.uid=su.uid')
            ->whereIn('su.store_id', $scope->allowedBusinessStoreIds())
            ->where('su.status', 1);
        if ($hasExclusive) {
            $base->leftJoin(
                'member_exclusive_service es',
                "es.member_id=u.uid AND es.status=1 AND es.store_id IN ({$stores})"
            );
        }
        $userColumns = $this->tableColumns('user');
        if (isset($userColumns['status'])) {
            $base->where('u.status', 1);
        }
        if (isset($userColumns['is_del'])) {
            $base->where('u.is_del', 0);
        }
        if ((int)($query['memberId'] ?? 0) > 0) {
            $base->where('u.uid', (int)$query['memberId']);
        }
        if ((string)$query['keyword'] !== '') {
            $like = '%' . addcslashes((string)$query['keyword'], "\\%_") . '%';
            $fields = ['u.real_name', 'u.nickname', 'u.phone', 'u.bar_code'];
            if ($hasExclusive) {
                $fields[] = 'es.staff_name';
            }
            $this->applyUtf8KeywordLike($base, $fields, $like);
        }
        $total = (int)(clone $base)->count('DISTINCT u.uid');
        if ($after !== []) {
            $base->where('u.uid', '>', $this->positiveCursorInt($after, 'memberId'));
        }
        $rows = $this->rows($base
            ->field('u.uid AS member_id')
            ->group('u.uid')
            ->order('u.uid', 'asc')
            ->limit((int)$query['pageSize'] + 1)
            ->select());
        $ids = array_values(array_filter(array_map(static function (array $row): int {
            return (int)($row['member_id'] ?? 0);
        }, $rows)));
        $hasMore = count($ids) > (int)$query['pageSize'];
        if ($hasMore) {
            array_pop($ids);
        }
        return ['memberIds' => $ids, 'total' => $total, 'hasMore' => $hasMore];
    }

    public function memberProfiles(CustomerCareQueryScope $scope, array $memberIds): array
    {
        $memberIds = $this->ids($memberIds);
        if ($memberIds === []) {
            return [];
        }
        $userColumns = $this->tableColumns('user');
        $fields = array_values(array_intersect(
            ['uid','nickname','real_name','phone','level','belong_store_id','bar_code'],
            array_keys($userColumns)
        ));
        if (!in_array('uid', $fields, true)) {
            return [];
        }
        $rows = $this->rows(Db::name('user')->whereIn('uid', $memberIds)->field($fields)->select());
        $levelNames = [];
        if (isset($this->tableColumns('system_user_level')['name'])) {
            $levelIds = $this->ids(array_column($rows, 'level'));
            if ($levelIds !== []) {
                $levelNames = Db::name('system_user_level')->whereIn('id', $levelIds)->column('name', 'id');
            }
        }
        $labels = $this->memberLabels($memberIds);
        $exclusive = $this->exclusivePeople($scope, $memberIds);
        $result = [];
        foreach ($rows as $row) {
            $id = (int)($row['uid'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $name = trim((string)($row['real_name'] ?? ''))
                ?: trim((string)($row['nickname'] ?? ''));
            $result[$id] = [
                'memberId' => $id,
                'name' => $name !== '' ? $name : '未命名会员',
                'phone' => (string)($row['phone'] ?? ''),
                'memberNo' => (string)($row['bar_code'] ?? ''),
                'level' => (string)($levelNames[(int)($row['level'] ?? 0)] ?? ''),
                'tags' => array_values($labels[$id] ?? []),
                'exclusiveServiceName' => (string)($exclusive[$id]['staff_name'] ?? '待分配'),
                'exclusiveServiceStaffId' => (int)($exclusive[$id]['staff_id'] ?? 0),
            ];
        }
        return $result;
    }

    public function memberCareSummaries(
        CustomerCareQueryScope $scope,
        array $memberIds,
        int $now
    ): array {
        $memberIds = $this->ids($memberIds);
        $result = [];
        foreach ($memberIds as $memberId) {
            $result[$memberId] = [
                'storeName' => '',
                'latestService' => '暂无服务记录',
                'latestServiceSource' => 'unavailable',
                'latestCare' => '暂无跟进记录',
                'nextTask' => [
                    'taskId' => '',
                    'taskNo' => '',
                    'label' => '暂无待跟进任务',
                    'status' => '',
                    'statusLabel' => '',
                    'canOpen' => false,
                ],
                'overdueCount' => 0,
            ];
        }
        if ($memberIds === []) {
            return $result;
        }

        $latestRecords = $this->latestRecordsByMember($scope, $memberIds);
        foreach ($latestRecords as $memberId => $record) {
            $result[$memberId]['latestCare'] = trim(
                CustomerCareProjectionContract::dateTime(
                    (int)$record['followed_at'],
                    $scope->businessTimezone()
                ) . ' ' . CustomerCareProjectionContract::methodLabel((string)$record['followup_method'])
                    . ' · ' . CustomerCareProjectionContract::resultLabel((string)$record['result_code'])
            );
            $result[$memberId]['storeName'] = (string)$record['business_store_name_snapshot'];
        }

        $tasks = $this->rows(Db::name('customer_care_task')
            ->where('tenant_id', $scope->tenantId())
            ->whereIn('member_id', $memberIds)
            ->where('is_visible', 1)
            ->whereIn('status', [CustomerCareTaskState::UNSTARTED, CustomerCareTaskState::IN_PROGRESS])
            ->order('planned_at', 'asc')
            ->order('id', 'asc')
            ->select());
        foreach ($tasks as $task) {
            $memberId = (int)$task['member_id'];
            if (!isset($result[$memberId])) {
                continue;
            }
            if ($result[$memberId]['nextTask']['taskId'] === '') {
                $status = (string)$task['status'];
                $result[$memberId]['nextTask'] = [
                    'taskId' => (string)(int)$task['id'],
                    'taskNo' => (string)($task['task_no'] ?? ''),
                    'label' => CustomerCareProjectionContract::dateTime(
                        (int)$task['planned_at'],
                        $scope->businessTimezone()
                    ) . ' ' . CustomerCareProjectionContract::taskLabel((string)$task['task_type']),
                    'status' => $status,
                    'statusLabel' => CustomerCareProjectionContract::taskStatusLabel($status),
                    'canOpen' => true,
                ];
                $result[$memberId]['storeName'] = (string)$task['business_store_name_snapshot'];
            }
            if ((int)$task['planned_at'] < $now) {
                $result[$memberId]['overdueCount']++;
            }
        }

        $serviceRows = $this->latestServiceTasks($scope, $memberIds);
        foreach ($serviceRows as $memberId => $task) {
            $result[$memberId]['latestService'] = trim(
                CustomerCareProjectionContract::dateTime(
                    (int)($task['created_at'] ?? 0),
                    $scope->businessTimezone()
                ) . ' ' . ((string)($task['title'] ?? '') ?: '服务完成')
            );
            $result[$memberId]['latestServiceSource'] = 'care_task_service_completed_snapshot';
            if ($result[$memberId]['storeName'] === '') {
                $result[$memberId]['storeName'] = (string)$task['business_store_name_snapshot'];
            }
        }
        return $result;
    }

    public function teamSummary(CustomerCareQueryScope $scope, int $now, int $dayEnd): array
    {
        if (!$scope->canViewStatistics()) {
            return [
                'counts' => ['today' => 0, 'overdue' => 0, 'future' => 0, 'completed' => 0, 'all' => 0],
                'employeeRows' => [],
                'validRecordCount' => 0,
            ];
        }
        $taskQuery = [
            'scope' => $scope->canViewAllTasks() ? 'all' : 'my',
            'bucket' => 'all',
            'keyword' => '',
            'status' => '',
            'pageSize' => 1,
            'cursor' => '',
        ];
        $counts = $this->taskBucketCounts($scope, $taskQuery, $now, $dayEnd);
        $openRows = $this->rows($this->taskQuery($scope, $taskQuery, $now, $dayEnd, false)
            ->whereIn('status', [CustomerCareTaskState::UNSTARTED, CustomerCareTaskState::IN_PROGRESS])
            ->field(sprintf(
                'owner_staff_id,owner_employee_id,owner_name_snapshot,COUNT(*) AS open_count,' .
                'SUM(CASE WHEN planned_at < %d THEN 1 ELSE 0 END) AS overdue_count',
                $now
            ))
            ->group('owner_staff_id,owner_employee_id,owner_name_snapshot')
            ->select());

        $recordQuery = Db::name('customer_care_record')
            ->where('tenant_id', $scope->tenantId())
            ->where('status', CustomerCareRecordState::NORMAL)
            ->whereNotNull('task_id');
        $this->applyAuthorizedMemberScope($recordQuery, $scope, 'member_id');
        $validRecordCount = (int)(clone $recordQuery)->count();
        $completedRows = $this->rows($recordQuery
            ->field('follower_staff_id,follower_employee_id,follower_name_snapshot,COUNT(*) AS completed_count')
            ->group('follower_staff_id,follower_employee_id,follower_name_snapshot')
            ->select());
        $employees = [];
        foreach ($openRows as $row) {
            $staffId = (int)$row['owner_staff_id'];
            $employees[$staffId] = [
                'employeeId' => (int)$row['owner_employee_id'],
                'staffId' => $staffId,
                'name' => (string)$row['owner_name_snapshot'],
                'currentOpenWorkload' => (int)$row['open_count'],
                'completedByActualFollower' => 0,
                'currentOverdue' => (int)$row['overdue_count'],
            ];
        }
        foreach ($completedRows as $row) {
            $staffId = (int)$row['follower_staff_id'];
            if (!isset($employees[$staffId])) {
                $employees[$staffId] = [
                    'employeeId' => (int)$row['follower_employee_id'],
                    'staffId' => $staffId,
                    'name' => (string)$row['follower_name_snapshot'],
                    'currentOpenWorkload' => 0,
                    'completedByActualFollower' => 0,
                    'currentOverdue' => 0,
                ];
            }
            $employees[$staffId]['completedByActualFollower'] = (int)$row['completed_count'];
        }
        ksort($employees, SORT_NUMERIC);
        return [
            'counts' => $counts,
            'employeeRows' => array_values($employees),
            'validRecordCount' => $validRecordCount,
        ];
    }

    public function activeAssignees(CustomerCareQueryScope $scope): array
    {
        $query = Db::name('system_store_staff')->alias('s')
            ->join('employee e', 'e.id=s.employee_id')
            ->where('s.store_id', $scope->operationStoreId())
            ->where('s.status', 1)
            ->where('s.is_del', 0)
            ->where('s.employee_id', '>', 0)
            ->where('e.status', 1)
            ->where('e.is_del', 0);
        // The V3 role switch was added after older compatible store-staff schemas.
        // Use it whenever present, while preserving a safe active-employee fallback.
        if (isset($this->tableColumns('system_store_staff')['cashier_craftsman_enabled'])) {
            $query->where('s.cashier_craftsman_enabled', 1);
        }
        return $this->rows($query
            ->field('s.id AS staff_id,s.employee_id,s.store_id,s.staff_name')
            ->order('s.staff_name', 'asc')
            ->order('s.id', 'asc')
            ->limit(500)
            ->select());
    }

    public function authorizedMember(
        CustomerCareQueryScope $scope,
        int $memberId,
        int $businessStoreId
    ): ?array {
        if ($memberId <= 0 || !in_array($businessStoreId, $scope->allowedBusinessStoreIds(), true)) {
            return null;
        }
        $query = Db::name('user')->alias('u')
            ->join('store_user su', 'su.uid=u.uid AND su.status=1')
            ->where('u.uid', $memberId)
            ->where('su.store_id', $businessStoreId);
        $columns = $this->tableColumns('user');
        if (isset($columns['status'])) {
            $query->where('u.status', 1);
        }
        if (isset($columns['is_del'])) {
            $query->where('u.is_del', 0);
        }
        $row = $this->row($query
            ->field('u.uid,u.real_name,u.nickname,u.phone,u.bar_code,su.store_id')
            ->find());
        if ($row === null) {
            return null;
        }
        $name = trim((string)($row['real_name'] ?? '')) ?: trim((string)($row['nickname'] ?? ''));
        return [
            'member_id' => (int)$row['uid'],
            'member_name' => $name !== '' ? $name : '未命名会员',
            'phone' => (string)($row['phone'] ?? ''),
            'member_no' => (string)($row['bar_code'] ?? ''),
            'business_store_id' => (int)$row['store_id'],
        ];
    }

    public function authorizedTask(CustomerCareQueryScope $scope, int $taskId): ?array
    {
        if ($taskId <= 0) {
            return null;
        }
        $query = Db::name('customer_care_task')->alias('t')
            ->where('tenant_id', $scope->tenantId())
            ->where('id', $taskId);
        $query->where(function ($visible) use ($scope) {
            $visible->where(function ($operational) use ($scope) {
                $operational->whereIn('t.business_store_id', $scope->allowedBusinessStoreIds());
                if (!$scope->canViewAllTasks()) {
                    $operational->where('t.owner_staff_id', $scope->staffId());
                }
            })->whereOr(function ($memberHistory) use ($scope) {
                $this->applyAuthorizedMemberScope($memberHistory, $scope, 't.member_id');
            });
        });
        return $this->row($query->find());
    }

    public function authorizedRecord(CustomerCareQueryScope $scope, int $recordId): ?array
    {
        if ($recordId <= 0) {
            return null;
        }
        $query = Db::name('customer_care_record')->alias('r')
            ->where('tenant_id', $scope->tenantId())
            ->where('id', $recordId);
        $this->applyAuthorizedMemberScope($query, $scope, 'r.member_id');
        return $this->row($query->find());
    }

    public function latestRecordsByMember(CustomerCareQueryScope $scope, array $memberIds): array
    {
        $memberIds = $this->ids($memberIds);
        if ($memberIds === []) {
            return [];
        }
        $base = Db::name('customer_care_record')
            ->where('tenant_id', $scope->tenantId())
            ->whereIn('member_id', $memberIds)
            ->where('status', CustomerCareRecordState::NORMAL);
        $idRows = $this->rows($base
            ->field("member_id,SUBSTRING_INDEX(GROUP_CONCAT(id ORDER BY followed_at DESC,id DESC), ',', 1) AS latest_id")
            ->group('member_id')
            ->select());
        $ids = $this->ids(array_column($idRows, 'latest_id'));
        if ($ids === []) {
            return [];
        }
        $rows = $this->rows(Db::name('customer_care_record')->whereIn('id', $ids)->select());
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['member_id']] = $row;
        }
        return $result;
    }

    public function recordsByTask(CustomerCareQueryScope $scope, array $taskIds): array
    {
        $taskIds = $this->ids($taskIds);
        if ($taskIds === []) {
            return [];
        }
        $rows = $this->rows(Db::name('customer_care_record')
            ->where('tenant_id', $scope->tenantId())
            ->whereIn('task_id', $taskIds)
            ->select());
        $result = [];
        foreach ($rows as $row) {
            $taskId = (int)($row['task_id'] ?? 0);
            if ($taskId > 0) {
                $result[$taskId] = $row;
            }
        }
        return $result;
    }

    private function taskQuery(
        CustomerCareQueryScope $scope,
        array $query,
        int $now,
        int $dayEnd,
        bool $includeBucket
    ) {
        $base = Db::name('customer_care_task')
            ->where('tenant_id', $scope->tenantId())
            ->whereIn('business_store_id', $scope->allowedBusinessStoreIds())
            ->where('is_visible', 1);
        if ($query['scope'] === 'my') {
            $base->where('owner_staff_id', $scope->staffId());
        }
        $ownerStaffId = (int)($query['ownerStaffId'] ?? 0);
        if ($ownerStaffId > 0) {
            $base->where('owner_staff_id', $ownerStaffId);
        }
        if (($query['statusGroup'] ?? '') === 'open') {
            $base->whereIn('status', [CustomerCareTaskState::UNSTARTED, CustomerCareTaskState::IN_PROGRESS]);
        }
        if ((string)$query['status'] !== '') {
            $base->where('status', (string)$query['status']);
        }
        if ((int)($query['plannedFrom'] ?? 0) > 0) {
            $base->where('planned_at', '>=', (int)$query['plannedFrom']);
        }
        if ((int)($query['plannedTo'] ?? 0) > 0) {
            $base->where('planned_at', '<=', (int)$query['plannedTo']);
        }
        $this->applyTaskKeyword($base, (string)$query['keyword']);
        if ($includeBucket) {
            switch ($query['bucket']) {
                case CustomerCareProjectionContract::BUCKET_TODAY:
                    $base->whereIn('status', [CustomerCareTaskState::UNSTARTED, CustomerCareTaskState::IN_PROGRESS])
                        ->where('planned_at', '>=', $now)
                        ->where('planned_at', '<=', $dayEnd);
                    break;
                case CustomerCareProjectionContract::BUCKET_OVERDUE:
                    $base->whereIn('status', [CustomerCareTaskState::UNSTARTED, CustomerCareTaskState::IN_PROGRESS])
                        ->where('planned_at', '<', $now);
                    break;
                case CustomerCareProjectionContract::BUCKET_FUTURE:
                    $base->whereIn('status', [CustomerCareTaskState::UNSTARTED, CustomerCareTaskState::IN_PROGRESS])
                        ->where('planned_at', '>', $dayEnd);
                    break;
                case CustomerCareProjectionContract::BUCKET_COMPLETED:
                    $base->where('status', CustomerCareTaskState::COMPLETED);
                    break;
            }
        }
        return $base;
    }

    private function applyTaskKeyword($query, string $keyword): void
    {
        if ($keyword === '') {
            return;
        }
        $like = '%' . addcslashes($keyword, "\\%_") . '%';
        $this->applyUtf8KeywordLike($query, [
            'task_no', 'member_name_snapshot', 'owner_name_snapshot', 'title', 'note', 'source_id',
        ], $like);
    }

    private function applyRecordKeyword($query, string $keyword): void
    {
        if ($keyword === '') {
            return;
        }
        $like = '%' . addcslashes($keyword, "\\%_") . '%';
        $this->applyUtf8KeywordLike($query, [
            'r.record_no', 'r.member_name_snapshot', 'r.summary', 'r.detail',
            'r.follower_name_snapshot', 'r.related_business_id',
        ], $like);
    }

    /**
     * Customer-care document numbers use ascii_bin while names and notes use utf8mb4.
     * Normalize every fixed, server-owned search field before a mixed keyword comparison.
     * The fields are internal constants, never browser-provided identifiers.
     */
    private function applyUtf8KeywordLike($query, array $fields, string $like): void
    {
        $query->where(function ($where) use ($fields, $like) {
            foreach ($fields as $index => $field) {
                $expression = sprintf('CONVERT(%s USING utf8mb4) COLLATE utf8mb4_general_ci LIKE ?', $field);
                if ($index === 0) {
                    $where->whereRaw($expression, [$like]);
                    continue;
                }
                $where->whereOrRaw($expression, [$like]);
            }
        });
    }

    /** @return array<int,array> */
    private function latestServiceTasks(CustomerCareQueryScope $scope, array $memberIds): array
    {
        $base = Db::name('customer_care_task')
            ->where('tenant_id', $scope->tenantId())
            ->whereIn('member_id', $memberIds)
            ->where('is_visible', 1)
            ->where('source_type', 'SERVICE_COMPLETED');
        $idRows = $this->rows($base
            ->field("member_id,SUBSTRING_INDEX(GROUP_CONCAT(id ORDER BY created_at DESC,id DESC), ',', 1) AS latest_id")
            ->group('member_id')
            ->select());
        $ids = $this->ids(array_column($idRows, 'latest_id'));
        if ($ids === []) {
            return [];
        }
        $rows = $this->rows(Db::name('customer_care_task')->whereIn('id', $ids)->select());
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['member_id']] = $row;
        }
        return $result;
    }

    /** @return array<int,string[]> */
    private function memberLabels(array $memberIds): array
    {
        $relation = $this->tableColumns('user_label_relation');
        $label = $this->tableColumns('user_label');
        if (!isset($relation['uid'], $relation['label_id'], $label['id'], $label['label_name'])) {
            return [];
        }
        $rows = $this->rows(Db::name('user_label_relation')->alias('r')
            ->join('user_label l', 'l.id=r.label_id')
            ->whereIn('r.uid', $memberIds)
            ->field('r.uid,l.label_name')
            ->order('r.id', 'asc')
            ->select());
        $result = [];
        foreach ($rows as $row) {
            $name = trim((string)($row['label_name'] ?? ''));
            if ($name !== '') {
                $result[(int)$row['uid']][] = $name;
            }
        }
        return $result;
    }

    /** @return array<int,array> */
    private function exclusivePeople(CustomerCareQueryScope $scope, array $memberIds): array
    {
        $columns = $this->tableColumns('member_exclusive_service');
        if (!isset($columns['member_id'], $columns['store_id'], $columns['staff_id'],
            $columns['staff_name'], $columns['status'])) {
            return [];
        }
        $rows = $this->rows(Db::name('member_exclusive_service')
            ->whereIn('member_id', $memberIds)
            ->whereIn('store_id', $scope->allowedBusinessStoreIds())
            ->where('status', 1)
            ->field('member_id,staff_id,employee_id,store_id,staff_name')
            ->select());
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['member_id']] = $row;
        }
        return $result;
    }

    /** @return array<string,bool> */
    private function tableColumns(string $table): array
    {
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }
        $prefix = $this->tablePrefix();
        try {
            $rows = Db::query('SHOW COLUMNS FROM `' . $prefix . $table . '`') ?: [];
        } catch (\Throwable $throwable) {
            return $this->columnCache[$table] = [];
        }
        $columns = [];
        foreach ($rows as $row) {
            $field = (string)($row['Field'] ?? $row['field'] ?? '');
            if ($field !== '') {
                $columns[$field] = true;
            }
        }
        return $this->columnCache[$table] = $columns;
    }

    private function tablePrefix(): string
    {
        $prefix = (string)(config('database.connections.mysql.prefix') ?: 'eb_');
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $prefix)) {
            throw new CustomerCareProjectionException(
                CustomerCareProjectionErrorCode::DEPENDENCY_NOT_READY,
                '客情查询数据库前缀配置无效。'
            );
        }
        return $prefix;
    }

    /**
     * Member DataScope is independent from task ownership. Once a member is visible,
     * care history is not truncated again by record creator or historical record store.
     */
    private function applyAuthorizedMemberScope(
        $query,
        CustomerCareQueryScope $scope,
        string $memberField
    ): void {
        if (!in_array($memberField, ['member_id', 'r.member_id', 't.member_id'], true)) {
            throw new \LogicException('unsupported customer-care member scope field');
        }
        $query->whereExists(function ($memberScope) use ($scope, $memberField) {
            $memberScope->name('store_user')->alias('care_scope_su')
                ->whereRaw('care_scope_su.uid = ' . $memberField)
                ->whereIn('care_scope_su.store_id', $scope->allowedBusinessStoreIds())
                ->where('care_scope_su.status', 1)
                ->field('care_scope_su.uid');
        });
    }

    /** @return int[] */
    private function ids(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $id = (int)$value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        ksort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    private function positiveCursorInt(array $after, string $field): int
    {
        $value = $after[$field] ?? null;
        if (!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value))) {
            throw new CustomerCareProjectionException(
                CustomerCareProjectionErrorCode::INVALID_QUERY,
                '客情分页位置无效。',
                ['field' => 'cursor.' . $field]
            );
        }
        $value = (int)$value;
        if ($value <= 0) {
            throw new CustomerCareProjectionException(
                CustomerCareProjectionErrorCode::INVALID_QUERY,
                '客情分页位置无效。',
                ['field' => 'cursor.' . $field]
            );
        }
        return $value;
    }

    /** @return array[] */
    private function rows($value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        return is_array($value) ? array_values($value) : [];
    }

    private function row($value): ?array
    {
        if ($value === null || $value === false || $value === []) {
            return null;
        }
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        return is_array($value) ? $value : null;
    }
}
