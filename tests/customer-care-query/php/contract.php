<?php
declare(strict_types=1);

$backend = getenv('CUSTOMER_CARE_QUERY_BACKEND') ?: dirname(__DIR__, 3) . '/后端代码';
$care = $backend . '/app/services/customer/care';

foreach ([
    'CustomerCareClock.php',
    'CustomerCareDomainException.php',
    'CustomerCareErrorCode.php',
    'CustomerCareTaskState.php',
    'CustomerCareRecordState.php',
    'CustomerCareCodeRegistry.php',
] as $file) {
    require_once $care . '/' . $file;
}
foreach ([
    'CustomerCareProjectionException.php',
    'CustomerCareProjectionErrorCode.php',
    'CustomerCareQueryScope.php',
    'CustomerCareCursorCodec.php',
    'CustomerCareProjectionContract.php',
    'CustomerCareQueryRepository.php',
    'CustomerCareWorkbenchQueryService.php',
] as $file) {
    require_once $care . '/query/' . $file;
}
require_once $care . '/integration/CustomerCareActionInputMapper.php';

use app\services\customer\care\CustomerCareClock;
use app\services\customer\care\query\CustomerCareCursorCodec;
use app\services\customer\care\query\CustomerCareProjectionContract;
use app\services\customer\care\query\CustomerCareProjectionException;
use app\services\customer\care\query\CustomerCareQueryRepository;
use app\services\customer\care\query\CustomerCareQueryScope;
use app\services\customer\care\query\CustomerCareWorkbenchQueryService;
use app\services\customer\care\integration\CustomerCareActionInputMapper;

final class CareQueryFixedClock implements CustomerCareClock
{
    public function now(): int { return 1785286800; }

    public function businessDate(int $timestamp, string $timezone): string
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone($timezone))
            ->format('Y-m-d');
    }
}

final class CareQueryFakeRepository implements CustomerCareQueryRepository
{
    /** @var array */
    public $lastCreateContext = [];

    public function taskPage(CustomerCareQueryScope $scope, array $query, array $after, int $now, int $dayEnd): array
    {
        $rows = [$this->task(11, 501, $scope->staffId(), 'UNSTARTED', $now + 600)];
        return ['rows' => $rows, 'total' => 1, 'hasMore' => false];
    }

    public function taskBucketCounts(CustomerCareQueryScope $scope, array $query, int $now, int $dayEnd): array
    {
        return ['today' => 1, 'overdue' => 2, 'future' => 3, 'completed' => 4, 'all' => 11];
    }

    public function recordPage(CustomerCareQueryScope $scope, array $query, array $after): array
    {
        if ($query['stream'] === 'appointment') {
            return ['rows' => [], 'total' => 0, 'hasMore' => false];
        }
        return ['rows' => [[
            'id' => 31, 'record_key' => 'CARE-RECORD-31', 'record_no' => 'KQ2607290001', 'version' => 2, 'task_id' => null,
            'task_version' => 0, 'record_type' => 'DAILY_FOLLOWUP',
            'followed_at' => 1785283000, 'followup_method' => 'PHONE',
            'member_id' => 501, 'member_name_snapshot' => '会员501',
            'summary' => '回访完成', 'detail' => '回访完成详情', 'result_code' => 'SATISFIED',
            'follower_staff_id' => 101, 'follower_employee_id' => 1101,
            'follower_name_snapshot' => '员工101', 'created_by_staff_id' => 101,
            'business_store_name_snapshot' => '八号门店', 'status' => 'NORMAL',
            'void_reason' => '',
        ]], 'total' => 1, 'hasMore' => false];
    }

    public function customerMemberPage(CustomerCareQueryScope $scope, array $query, array $after): array
    {
        return ['memberIds' => [501], 'total' => 1, 'hasMore' => false];
    }

    public function memberProfiles(CustomerCareQueryScope $scope, array $memberIds): array
    {
        return [501 => [
            'memberId' => 501, 'name' => '会员501', 'phone' => '13800000501',
            'memberNo' => '100000501', 'level' => '金卡', 'tags' => ['重点维护'],
            'exclusiveServiceName' => '员工101', 'exclusiveServiceStaffId' => 101,
        ]];
    }

    public function memberCareSummaries(CustomerCareQueryScope $scope, array $memberIds, int $now): array
    {
        return [501 => [
            'storeName' => '八号门店', 'latestService' => '2026-07-28 深层护理',
            'latestServiceSource' => 'care_task_service_completed_snapshot',
            'latestCare' => '2026-07-29 电话 · 满意',
            'nextTask' => [
                'taskId' => '11', 'taskNo' => 'GJ2607290001',
                'label' => '2026-07-29 14:30 日常跟进',
                'status' => 'UNSTARTED', 'statusLabel' => '未开始', 'canOpen' => true,
            ], 'overdueCount' => 0,
        ]];
    }

    public function teamSummary(CustomerCareQueryScope $scope, int $now, int $dayEnd): array
    {
        return [
            'counts' => ['today' => 1, 'overdue' => 2, 'future' => 3, 'completed' => 4, 'all' => 11],
            'employeeRows' => [[
                'employeeId' => 1101, 'staffId' => 101, 'name' => '员工101',
                'currentOpenWorkload' => 6, 'completedByActualFollower' => 4, 'currentOverdue' => 2,
            ]],
            'validRecordCount' => 4,
        ];
    }

    public function activeAssignees(CustomerCareQueryScope $scope): array
    {
        return [
            [
                'staff_id' => 101, 'employee_id' => 1101,
                'store_id' => 8, 'staff_name' => '员工101',
            ],
            [
                'staff_id' => 102, 'employee_id' => 1102,
                'store_id' => 8, 'staff_name' => '员工102',
            ],
        ];
    }

    public function authorizedMember(CustomerCareQueryScope $scope, int $memberId, int $businessStoreId): ?array
    {
        $this->lastCreateContext = [
            'tenantId' => $scope->tenantId(),
            'businessStoreId' => $businessStoreId,
        ];
        return $memberId === 501 && $businessStoreId === 8
            ? ['member_id' => 501, 'member_name' => '会员501', 'business_store_id' => 8]
            : null;
    }

    public function authorizedTask(CustomerCareQueryScope $scope, int $taskId): ?array
    {
        return $taskId === 11 ? $this->task(11, 501, 101, 'IN_PROGRESS', 1785280000) : null;
    }

    public function authorizedRecord(CustomerCareQueryScope $scope, int $recordId): ?array
    {
        if ($recordId === 31) {
            return ['id' => 31, 'task_id' => null, 'version' => 2];
        }
        if ($recordId === 32) {
            return ['id' => 32, 'task_id' => 11, 'version' => 3];
        }
        return null;
    }

    public function latestRecordsByMember(CustomerCareQueryScope $scope, array $memberIds): array
    {
        return [];
    }

    public function recordsByTask(CustomerCareQueryScope $scope, array $taskIds): array
    {
        return [];
    }

    private function task(int $id, int $memberId, int $ownerId, string $status, int $plannedAt): array
    {
        return [
            'id' => $id, 'task_key' => 'CARE-TASK-' . $id, 'task_no' => 'GJ2607290001', 'version' => 2,
            'tenant_id' => 'TENANT_1', 'organization_id' => 'ORG_8',
            'organization_path' => '/ROOT/ORG_8/', 'organization_name_snapshot' => '八号组织',
            'business_store_id' => 8, 'business_store_name_snapshot' => '八号门店',
            'member_id' => $memberId, 'member_name_snapshot' => '会员' . $memberId,
            'owner_staff_id' => $ownerId, 'owner_employee_id' => $ownerId + 1000,
            'owner_name_snapshot' => '员工' . $ownerId, 'task_type' => 'DAILY_FOLLOWUP',
            'source_type' => 'MANUAL', 'source_id' => 'MANUAL-' . $id,
            'title' => '日常跟进', 'note' => '测试任务', 'status' => $status,
            'is_visible' => 1, 'planned_at' => $plannedAt, 'completed_at' => 0,
            'created_at' => 1785280000,
        ];
    }
}

$passed = 0;
$failed = 0;

function careQueryAssert(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS {$name}\n";
        return;
    }
    $failed++;
    echo "FAIL {$name}" . ($detail === '' ? '' : ": {$detail}") . "\n";
}

function careQueryThrows(callable $callable, string $code): bool
{
    try {
        $callable();
    } catch (CustomerCareProjectionException $exception) {
        return $exception->errorCode() === $code;
    } catch (Throwable $throwable) {
        return false;
    }
    return false;
}

function careTrustedContext(bool $manager = true): array
{
    return [
        'tenantId' => 'TENANT_1',
        'staffId' => 101,
        'employeeId' => 1101,
        'staffName' => '员工101',
        'operationStoreId' => 8,
        'operationStoreName' => '八号门店',
        'operationOrganizationId' => 'ORG_8',
        'operationOrganizationPath' => '/ROOT/ORG_8/',
        'operationOrganizationName' => '八号组织',
        'allowedBusinessStoreIds' => [8],
        'businessTimezone' => 'Asia/Shanghai',
        'canViewAllTasks' => $manager,
        'canCreateTask' => true,
        'canCreateRecord' => true,
        'canReassign' => $manager,
        'canViewStatistics' => $manager,
    ];
}

$clock = new CareQueryFixedClock();
$repo = new CareQueryFakeRepository();
$cursorSecret = 'care-query-contract-secret-32-bytes-minimum';
$codec = new CustomerCareCursorCodec($cursorSecret);
$service = new CustomerCareWorkbenchQueryService($repo, $clock, $codec);
$scope = CustomerCareQueryScope::fromTrustedContext(careTrustedContext());
$dayEnd = CustomerCareProjectionContract::dayEnd($clock->now(), 'Asia/Shanghai');

careQueryAssert('contract version frozen', CustomerCareProjectionContract::CONTRACT_VERSION === 'customer-care.v1');
careQueryAssert('overdue bucket wins', CustomerCareProjectionContract::bucket('UNSTARTED', $clock->now() - 1, $clock->now(), $dayEnd) === 'overdue');
careQueryAssert('today starts at current instant', CustomerCareProjectionContract::bucket('UNSTARTED', $clock->now(), $clock->now(), $dayEnd) === 'today');
careQueryAssert('future starts after business day', CustomerCareProjectionContract::bucket('IN_PROGRESS', $dayEnd + 1, $clock->now(), $dayEnd) === 'future');
careQueryAssert('completed independent of planned time', CustomerCareProjectionContract::bucket('COMPLETED', $dayEnd + 1, $clock->now(), $dayEnd) === 'completed');

careQueryAssert('non-manager all scope denied', careQueryThrows(static function () {
    $scope = CustomerCareQueryScope::fromTrustedContext(careTrustedContext(false));
    CustomerCareProjectionContract::normalizeTaskQuery(['scope' => 'all'], $scope);
}, 'CARE_QUERY_FORBIDDEN'));

$managerDefaultTaskQuery = CustomerCareProjectionContract::normalizeTaskQuery([], $scope);
careQueryAssert('manager defaults to all tasks and all statuses',
    $managerDefaultTaskQuery['scope'] === 'all'
        && $managerDefaultTaskQuery['bucket'] === CustomerCareProjectionContract::BUCKET_ALL);
$employeeDefaultTaskQuery = CustomerCareProjectionContract::normalizeTaskQuery(
    [],
    CustomerCareQueryScope::fromTrustedContext(careTrustedContext(false))
);
careQueryAssert('employee defaults to own tasks and all statuses',
    $employeeDefaultTaskQuery['scope'] === 'my'
        && $employeeDefaultTaskQuery['bucket'] === CustomerCareProjectionContract::BUCKET_ALL);

$taskRange = CustomerCareProjectionContract::normalizeTaskQuery([
    'scope' => 'my', 'plannedFrom' => '2026-08-03T09:00', 'plannedTo' => '2026-08-03T18:00',
], $scope);
careQueryAssert('task time range is normalized in the business timezone',
    $taskRange['plannedFrom'] > 0 && $taskRange['plannedTo'] > $taskRange['plannedFrom']);
$memberOpenTaskQuery = CustomerCareProjectionContract::normalizeTaskQuery([
    'memberId' => '501', 'statusGroup' => 'open',
], $scope);
careQueryAssert('task exact member and open status group are normalized together',
    $memberOpenTaskQuery['memberId'] === 501 && $memberOpenTaskQuery['statusGroup'] === 'open');
careQueryAssert('task exact member filter rejects invalid member id', careQueryThrows(static function () use ($scope) {
    CustomerCareProjectionContract::normalizeTaskQuery(['memberId' => '0'], $scope);
}, 'CARE_QUERY_INVALID'));
$recordRange = CustomerCareProjectionContract::normalizeRecordQuery([
    'followedFrom' => '2026-08-03T09:00', 'followedTo' => '2026-08-03T18:00',
], $scope);
careQueryAssert('record time range is normalized in the business timezone',
    $recordRange['followedFrom'] > 0 && $recordRange['followedTo'] > $recordRange['followedFrom']);
$memberRecordQuery = CustomerCareProjectionContract::normalizeRecordQuery(['memberId' => '501'], $scope);
careQueryAssert('record exact member filter is normalized', $memberRecordQuery['memberId'] === 501);
careQueryAssert('record exact member filter rejects invalid member id', careQueryThrows(static function () use ($scope) {
    CustomerCareProjectionContract::normalizeRecordQuery(['memberId' => '0'], $scope);
}, 'CARE_QUERY_INVALID'));
$customerQuery = CustomerCareProjectionContract::normalizeCustomerQuery(['memberId' => '501']);
careQueryAssert('customer exact member filter accepts a positive member id', $customerQuery['memberId'] === 501);
careQueryAssert('customer exact member filter rejects invalid member id', careQueryThrows(static function () {
    CustomerCareProjectionContract::normalizeCustomerQuery(['memberId' => '0']);
}, 'CARE_QUERY_INVALID'));
careQueryAssert('reversed time range is rejected', careQueryThrows(static function () use ($scope) {
    CustomerCareProjectionContract::normalizeTaskQuery([
        'scope' => 'my', 'plannedFrom' => '2026-08-03T18:00', 'plannedTo' => '2026-08-03T09:00',
    ], $scope);
}, 'CARE_QUERY_INVALID'));

$cursor = $codec->encode('tasks', 'scope-hash', ['plannedAt' => 100, 'id' => 2]);
careQueryAssert('signed cursor round trip', $codec->decode($cursor, 'tasks', 'scope-hash') === ['plannedAt' => 100, 'id' => 2]);
careQueryAssert('cursor cannot cross query scope', careQueryThrows(static function () use ($codec, $cursor) {
    $codec->decode($cursor, 'tasks', 'other-scope');
}, 'CARE_QUERY_INVALID'));
careQueryAssert('tampered cursor rejected', careQueryThrows(static function () use ($codec, $cursor) {
    $codec->decode(substr($cursor, 0, -1) . '0', 'tasks', 'scope-hash');
}, 'CARE_QUERY_INVALID'));
$malformedBody = '*';
$malformedCursor = $malformedBody . '.' . hash_hmac('sha256', $malformedBody, $cursorSecret);
careQueryAssert('malformed base64 cursor uses stable projection error', careQueryThrows(
    static function () use ($codec, $malformedCursor) {
        $codec->decode($malformedCursor, 'tasks', 'scope-hash');
    },
    'CARE_QUERY_INVALID'
));

$projection = $service->query(careTrustedContext(), [
    'view' => 'tasks',
    'query' => ['scope' => 'my', 'bucket' => 'today'],
]);
careQueryAssert('full five-block projection', isset(
    $projection['taskView'],
    $projection['customerView'],
    $projection['recordView'],
    $projection['statistics'],
    $projection['settings']
));
careQueryAssert('task and record queries default to current business month',
    ($projection['taskView']['appliedQuery']['plannedFrom'] ?? '') !== ''
        && ($projection['taskView']['appliedQuery']['plannedTo'] ?? '') !== ''
        && ($projection['recordView']['appliedQuery']['followedFrom'] ?? '') !== ''
        && ($projection['recordView']['appliedQuery']['followedTo'] ?? '') !== '');
$memberTaskProjection = $service->query(careTrustedContext(), [
    'view' => 'tasks', 'query' => ['memberId' => '501', 'statusGroup' => 'open'],
]);
careQueryAssert('task view echoes exact member and open status filters',
    ($memberTaskProjection['taskView']['appliedQuery']['memberId'] ?? '') === '501'
        && ($memberTaskProjection['taskView']['appliedQuery']['statusGroup'] ?? '') === 'open');
$memberRecordProjection = $service->query(careTrustedContext(), [
    'view' => 'records', 'query' => ['memberId' => '501'],
]);
careQueryAssert('record view echoes exact member filter',
    ($memberRecordProjection['recordView']['appliedQuery']['memberId'] ?? '') === '501');
careQueryAssert('server bucket counts returned', $projection['taskView']['bucketCounts']['overdue'] === 2);
careQueryAssert('task DTO carries stable navigation target', $projection['taskView']['records'][0]['relatedBusiness']['navigationTarget']['targetCode'] === 'member-detail');
careQueryAssert('task and record DTOs expose formal document numbers only',
    $projection['taskView']['records'][0]['taskNo'] === 'GJ2607290001'
        && $projection['recordView']['records'][0]['recordNo'] === 'KQ2607290001');
careQueryAssert('customer next task is a structured actionable reference',
    $projection['customerView']['records'][0]['nextTask']['taskId'] === '11'
        && $projection['customerView']['records'][0]['nextTask']['canOpen'] === true);
$customerProjection = $service->query(careTrustedContext(), [
    'view' => 'customers',
    'query' => ['memberId' => '501'],
]);
careQueryAssert('customer view echoes exact member filter for a stable refresh',
    ($customerProjection['customerView']['appliedQuery']['memberId'] ?? '') === '501');
$unfilteredCustomerProjection = $service->query(careTrustedContext(), [
    'view' => 'customers',
    'query' => [],
]);
careQueryAssert('unfiltered customer view does not echo zero as a member filter',
    ($unfilteredCustomerProjection['customerView']['appliedQuery']['memberId'] ?? null) === '');
careQueryAssert('task DTO identifies service summary source',
    $projection['taskView']['records'][0]['recentServiceSource'] === 'care_task_service_completed_snapshot');
careQueryAssert('manager task actions include transfer without replacing owner actions',
    array_column($projection['taskView']['records'][0]['availableActions'], 'code') === [
        'start-care-task', 'delete-care-task', 'reassign-care-task',
    ]
    && $projection['permissions']['canReassign'] === true);
careQueryAssert('rules and exceptions fail closed', $projection['permissions']['canManageRules'] === false
    && $projection['permissions']['canHandleExceptions'] === false
    && $projection['settings']['rules'] === []
    && $projection['settings']['exceptions'] === []);
careQueryAssert('appointment success remains a selectable care result',
    ($projection['preparations']['completion']['resultOptions'][3]['value'] ?? '') === 'APPOINTMENT_SUCCESS');
careQueryAssert('team summary server supplied', $projection['statistics']['teamSummary']['overdueCount'] === 2);

careQueryAssert('statistics metric rejects arbitrary metric names', careQueryThrows(static function () use ($scope) {
    CustomerCareProjectionContract::normalizeStatisticsQuery(['metric' => 'untrusted_metric'], $scope);
}, 'CARE_QUERY_INVALID'));
careQueryAssert('employee statistics metric requires an employee filter', careQueryThrows(static function () use ($scope) {
    CustomerCareProjectionContract::normalizeStatisticsQuery([
        'metric' => CustomerCareProjectionContract::STATISTICS_METRIC_EMPLOYEE_OPEN_WORKLOAD,
    ], $scope);
}, 'CARE_QUERY_INVALID'));
$statisticsDetailProjection = $service->query(careTrustedContext(), [
    'view' => 'statistics',
    'query' => ['metric' => CustomerCareProjectionContract::STATISTICS_METRIC_OPEN_WORKLOAD],
]);
careQueryAssert('statistics task detail is server-projected and read-only',
    ($statisticsDetailProjection['statistics']['detail']['metric'] ?? '')
        === CustomerCareProjectionContract::STATISTICS_METRIC_OPEN_WORKLOAD
    && ($statisticsDetailProjection['statistics']['detail']['entityType'] ?? '') === 'task'
    && isset($statisticsDetailProjection['statistics']['detail']['paginationCursor']));
$employeeRecordDetailProjection = $service->query(careTrustedContext(), [
    'view' => 'statistics',
    'query' => [
        'metric' => CustomerCareProjectionContract::STATISTICS_METRIC_EMPLOYEE_COMPLETED,
        'staffId' => '101',
    ],
]);
careQueryAssert('employee completion uses actual-follower record detail',
    ($employeeRecordDetailProjection['statistics']['detail']['entityType'] ?? '') === 'record'
    && ($employeeRecordDetailProjection['statistics']['detail']['staffId'] ?? '') === '101');

$nonManagerProjection = $service->query(careTrustedContext(false), [
    'view' => 'tasks',
    'query' => ['scope' => 'my', 'bucket' => 'today'],
]);
careQueryAssert('non-manager next-owner choices stay self-only',
    $nonManagerProjection['permissions']['canReassign'] === false
    && array_column(
        $nonManagerProjection['preparations']['completion']['assigneeOptions'],
        'staffId'
    ) === ['101']
    && $nonManagerProjection['preparations']['reassignment']['assigneeOptions'] === []);

$appointment = $service->query(careTrustedContext(), [
    'view' => 'records',
    'query' => ['stream' => 'appointment', 'dataScope' => 'normal'],
]);
careQueryAssert('appointment stream stays separate and empty', $appointment['recordView']['records'] === []
    && $appointment['recordView']['streamCapability']['enabled'] === false);

$mapper = new CustomerCareActionInputMapper($repo, $clock);
$mapped = $mapper->map('create-care-task', $scope, [
    'memberId' => '501',
    'taskTypeCode' => 'DAILY_FOLLOWUP',
    'plannedAt' => '2026-07-29T16:00',
    'ownerId' => '101',
    'content' => '页面试图扩大范围',
    'tenantId' => 'OTHER_TENANT',
    'businessStoreId' => 999,
], 'gateway-request-00000001');
careQueryAssert('mapper uses trusted tenant and store only', $repo->lastCreateContext === [
    'tenantId' => 'TENANT_1', 'businessStoreId' => 8,
] && $mapped['command']['businessStoreId'] === 8);
careQueryAssert('domain idempotency is deterministic and prefixed', preg_match(
    '/^CARE_CREATE_TASK-[0-9a-f-]{36}$/D',
    $mapped['command']['idempotencyKey']
) === 1);
$appointmentResult = $mapper->map('create-care-record', $scope, [
    'memberId' => '501', 'recordTypeCode' => 'FOLLOWUP',
    'followedAt' => '2026-07-29T10:00', 'methodCode' => 'PHONE',
    'content' => '记录预约成功结果', 'resultCode' => 'APPOINTMENT_SUCCESS',
], 'gateway-request-00000002');
careQueryAssert('appointment result stays a pure care record before appointment integration',
    ($appointmentResult['command']['resultCode'] ?? '') === 'APPOINTMENT_SUCCESS'
    && !array_key_exists('appointmentId', $appointmentResult['command']));
careQueryAssert('linked record requires explicit record version', careQueryThrows(static function () use ($mapper, $scope) {
    $mapper->map('void-care-record', $scope, [
        'recordId' => '32', 'expectedVersion' => '2', 'reason' => '录入错误',
    ], 'gateway-request-00000003');
}, 'CARE_QUERY_INVALID'));
$voidStandalone = $mapper->map('void-care-record', $scope, [
    'recordId' => '31', 'expectedVersion' => '2', 'reason' => '录入错误',
], 'gateway-request-00000004');
careQueryAssert('standalone record maps single explicit version', $voidStandalone['command']['expectedRecordVersion'] === 2
    && !array_key_exists('taskId', $voidStandalone['command']));

echo "CUSTOMER_CARE_QUERY_CONTRACT passed={$passed} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
